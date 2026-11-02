<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use MarkdownPresentation\Exception\UnreadableDeck;
use MarkdownPresentation\Render\Free\ShapeKind;
use MarkdownPresentation\Render\SlideGeometry;
use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\AutoShape;
use PhpOffice\PhpPresentation\Shape\Drawing\Base64;
use PhpOffice\PhpPresentation\Shape\Drawing\Gd;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\RichText\BreakElement;
use PhpOffice\PhpPresentation\Shape\RichText\Paragraph;
use PhpOffice\PhpPresentation\Shape\RichText\TextElement;
use PhpOffice\PhpPresentation\Shape\Table;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\Font;

/**
 * A shape read out of a slide, turned into the blocks a deck is written in.
 *
 * The shape of a slide is a set of boxes, and the shape of a deck is a
 * sequence of blocks. This is where the first becomes the second.
 *
 * Two things decide what a shape becomes, and only two:
 *
 * - **What kind of shape it is.** A text box is prose, a table is a table, a
 *   picture is a picture.
 * - **What it says.** A run's bold, italic and strike travel; its size, its
 *   colour and its face do not, because the design of the new deck is not the
 *   design of the old one — and because the label of a text style is plain
 *   text with no escape in it, so a word whose text carries a bracket cannot be
 *   written back as a style at all.
 *
 * A shape the deck placed itself is a third kind, and it is written back as the
 * free shape it is when {@see Options::$preserveGeometry} says to. That is the
 * inverse of the renderer: the box it was given, the colour it was painted and
 * the words in it travel, and the rest of what a shape is does not.
 */
final class ShapeMapper
{
    /**
     * The picture formats a deck may hold, and the extension each keeps.
     *
     * A `.pptx` holds a vector beside a raster of it, and the raster is the one a
     * reader can show, so the raster is what comes out.
     */
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'tiff', 'webp', 'svg'];

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * The blocks a shape becomes.
     *
     * @param int   $slide   The slide's number, counting from one, which names
     *        the pictures written out of it.
     * @param int   $image   How many shapes this slide has already given, which
     *        names the pictures so that they do not collide. It counts the
     *        shapes and not the pictures.
     * @param bool  $outline Whether the shape is a body placeholder, whose
     *        paragraphs are an outline whether or not the slide says so.
     *
     * @return list<Block>
     */
    public function blocks(AbstractShape $shape, int $slide, int $image, bool $outline = false): array
    {
        // A free shape is asked about first, because a free text block is a text box and
        // would otherwise be read as the prose it happens to hold.
        $placed = $this->placed($shape);

        if ($placed !== []) {
            return $placed;
        }

        return match (true) {
            $shape instanceof Table => $this->table($shape),
            $shape instanceof Gd || $shape instanceof Base64 => $this->picture($shape, $slide, $image),
            $shape instanceof RichText => $this->prose(
                array_values($shape->getParagraphs()),
                $outline,
                $this->linkOf($shape),
            ),
            default => [],
        };
    }

    /**
     * The free shape a shape becomes, or nothing where it is not one.
     *
     * A shape the deck placed itself is an autoshape, or a text box the library
     * wrote under the name of the directive that draws it. The autoshape is
     * recognised by its own geometry, which is the true inverse: the name is
     * what this library writes, and a deck written by another program has
     * names of its own.
     */
    private function placed(AbstractShape $shape): array
    {
        if (!$this->options->preserveGeometry) {
            return [];
        }

        $kind = $this->kindOf($shape);

        if ($kind === null) {
            return [];
        }

        return [Block::shape($this->freeShape($shape, $kind))];
    }

    /**
     * The free shape a shape is, or null where it is not one this library draws.
     */
    private function kindOf(AbstractShape $shape): ?ShapeKind
    {
        if ($shape instanceof AutoShape) {
            foreach (ShapeKind::cases() as $kind) {
                if ($kind->autoShape() === $shape->getType()) {
                    return $kind;
                }
            }

            return null;
        }

        if (!$shape instanceof RichText || $shape->isPlaceholder()) {
            return null;
        }

        // A text box the library wrote carries the name of the directive that draws
        // it, and the layout's own text carries the name of the block it was drawn
        // from. Only the first is a free shape.
        foreach (ShapeKind::cases() as $kind) {
            if ($kind->value === $shape->getName() && $kind->isContent()) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * The free shape, with the box it was given and the colour it was painted.
     */
    private function freeShape(AbstractShape $shape, ShapeKind $kind): Shape
    {
        $box = $this->box($shape);
        $attributes = [
            'left' => $this->percentage($box['x'], SlideGeometry::WIDTH),
            'top' => $this->percentage($box['y'], SlideGeometry::HEIGHT),
            'width' => $this->percentage($box['width'], SlideGeometry::WIDTH),
            'height' => $this->percentage($box['height'], SlideGeometry::HEIGHT),
        ];

        $fill = $this->fill($shape);

        if ($fill !== null) {
            $attributes['fill'] = $fill;
        }

        $rotation = $shape->getRotation();

        if ($rotation !== 0) {
            $attributes['rotate'] = (string) $rotation;
        }

        // A shape that runs off the edge is refused by the renderer unless it says so, and
        // the shape it was read from ran off the edge.
        if ($box['x'] < 0 || $box['y'] < 0 || $box['x'] + $box['width'] > SlideGeometry::WIDTH
            || $box['y'] + $box['height'] > SlideGeometry::HEIGHT) {
            $attributes['bleed'] = 'true';
        }

        $body = $kind->isContent()
            ? $this->prose(array_values($shape->getParagraphs()))
            : [];

        // A label is the words an ink shape holds. A free text block holds its body
        // instead, and a shape with both is a shape with two places for its words.
        $label = $kind->isContent() ? null : $this->label($shape);

        if ($label !== null) {
            $attributes['label'] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $label) . '"';
        }

        return new Shape($kind->value, $attributes, $body);
    }

    /**
     * The box a shape occupies, in the pixels the library counts in.
     *
     * The pixels are the reader's own: PHPPresentation converts the EMU of the file into
     * pixels when it reads a shape, and a pixel is 9525 EMU at the 96 dpi the library
     * converts at, so the two count the same grid.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function box(AbstractShape $shape): array
    {
        return [
            'x' => (int) round($shape->getOffsetX()),
            'y' => (int) round($shape->getOffsetY()),
            'width' => (int) round($shape->getWidth()),
            'height' => (int) round($shape->getHeight()),
        ];
    }

    /**
     * A measurement as a percentage of the slide, as the free shapes write it.
     *
     * Four decimals and no more: a shape at a third of the way across a 960-pixel slide is
     * 33.3333%, and that is the nearest a percentage gets to it. A percentage rather than a
     * number of pixels because a shape is placed relative to the slide, and a deck read on a
     * page of another size is the same deck.
     */
    private function percentage(int $pixels, int $of): string
    {
        $percentage = round($pixels / $of * 100, 4);

        $written = rtrim(rtrim(number_format($percentage, 4, '.', ''), '0'), '.');

        return ($written === '' || $written === '-' ? '0' : $written) . '%';
    }

    /**
     * The colour a shape is painted, or null where it is painted with none.
     *
     * The colour a theme slot resolved to and not the slot itself: the slot is in the theme,
     * and the theme is not in the deck. A shape painted with a gradient or a pattern is left
     * without a colour, because one colour is not what it is painted with.
     */
    private function fill(AbstractShape $shape): ?string
    {
        $fill = $shape->getFill();

        if (!$fill instanceof Fill || $fill->getFillType() !== Fill::FILL_SOLID) {
            return null;
        }

        $colour = $fill->getStartColor();

        if (!$colour instanceof Color) {
            return null;
        }

        return strtoupper($colour->getRGB());
    }

    /**
     * The words an ink shape holds, or null where it holds none.
     *
     * A label is the shape's own text, and it is plain: a label is one run of text, and the
     * text of a shape with paragraphs in it is a body rather than a label.
     */
    private function label(AbstractShape $shape): ?string
    {
        if ($shape instanceof AutoShape) {
            $text = trim($shape->getText());

            return $text === '' ? null : $text;
        }

        if (!$shape instanceof RichText) {
            return null;
        }

        $paragraphs = array_values($shape->getParagraphs());

        if (count($paragraphs) !== 1) {
            return null;
        }

        $text = '';

        foreach ($paragraphs[0]->getRichTextElements() as $element) {
            if ($element instanceof TextElement) {
                $text .= $element->getText();
            }
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * The address a shape's text is a link to, or an empty string when it is
     * not one.
     *
     * A link to another slide of the same deck is not an address, and a deck
     * has no way to say "go to slide three", so those are left out rather than
     * written as a link to a file that is not there.
     */
    private function linkOf(RichText $shape): string
    {
        if (! $shape->hasHyperlink()) {
            return '';
        }

        $hyperlink = $shape->getHyperlink();

        if ($hyperlink->isInternal()) {
            return '';
        }

        return $hyperlink->getUrl();
    }

    /**
     * The blocks a shape's paragraphs become.
     *
     * A paragraph is a list item when it carries a bullet, when it is nested
     * deeper than the first level, or when it sits in a body placeholder. The
     * last of those is a rule about the shape rather than the paragraph,
     * because PowerPoint inherits the bullet from the master instead of
     * writing it into the slide: a body placeholder is an outline, and an
     * outline is a list whether or not the slide says so.
     *
     * @param list<Paragraph> $paragraphs
     * @param bool            $outline Whether the shape is a body placeholder,
     *        whose paragraphs are an outline whether or not the slide says so.
     * @param string          $url The address the shape's text is a link to, or
     *        an empty string when it is not one.
     *
     * @return list<Block>
     */
    public function prose(array $paragraphs, bool $outline = false, string $url = ''): array
    {
        $items = [];
        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            $inlines = $this->inlines($paragraph);

            if ($inlines === []) {
                continue;
            }

            if ($url !== '') {
                $inlines = [Inline::link($url, $inlines)];
            }

            if (! $this->isItem($paragraph, $outline)) {
                $blocks[] = Block::paragraph($inlines);

                continue;
            }

            $items[] = [
                'level' => $paragraph->getAlignment()->getLevel(),
                'ordered' => $paragraph->getBulletStyle()?->getBulletType() === Bullet::TYPE_NUMERIC,
                'inlines' => $inlines,
            ];
        }

        if ($items !== []) {
            $blocks[] = $this->list($items);
        }

        return $blocks;
    }

    /**
     * A list of items, nested by the level each one is written at.
     *
     * The levels are relative to each other and not to anything else, which is
     * what Markdown's indentation is too, so the shallowest level in the shape
     * is the top of the list whichever level the shape started at.
     *
     * @param list<array{level: int, ordered: bool, inlines: list<Inline>}> $items
     */
    private function list(array $items): Block
    {
        $shallowest = $items[0]['level'];

        foreach ($items as $item) {
            $shallowest = min($shallowest, $item['level']);
        }

        foreach ($items as $index => $item) {
            $items[$index]['level'] = $item['level'] - $shallowest;
        }

        $position = 0;

        return Block::list(
            $this->items($items, $position, 0),
            $items[0]['ordered'],
        );
    }

    /**
     * The items at one level, and the items nested under them.
     *
     * @param list<array{level: int, ordered: bool, inlines: list<Inline>}> $items
     * @param int $position How far through the list this has got.
     *
     * @return list<list<list<Inline>>>
     */
    private function items(array $items, int &$position, int $level): array
    {
        $blocks = [];

        if ($level > Options::MAX_NESTING) {
            throw UnreadableDeck::nestedTooDeep(Options::MAX_NESTING);
        }

        while (isset($items[$position]) && $items[$position]['level'] >= $level) {
            if ($items[$position]['level'] > $level) {
                // The item is deeper than this level, so it belongs to the item
                // before it rather than beside it. The walk is pointed at the
                // item it is about and takes the level that item has, so what
                // comes back may be at a level between the two — a list whose
                // levels jump from 0 to 2 and back to 1 leaves the walk at level
                // 1, which is deeper than this level and so is read as the next
                // item of this list, holding a nested list of its own.
                $ordered = $items[$position]['ordered'];

                $nested = $this->items($items, $position, $items[$position]['level']);

                $blocks[] = Block::item([Block::list($nested, $ordered)]);

                continue;
            }

            $item = $items[$position];
            $position++;

            $children = [Block::paragraph($item['inlines'])];

            if (isset($items[$position]) && $items[$position]['level'] > $level) {
                // The flag is read before the walk moves on: the position is past
                // the last item by the time it comes back.
                $ordered = $items[$position]['ordered'];

                $nested = $this->items($items, $position, $items[$position]['level']);

                if ($nested !== []) {
                    $children[] = Block::list($nested, $ordered);
                }
            }

            $blocks[] = Block::item($children);
        }

        return $blocks;
    }

    /**
     * Whether a paragraph is a list item rather than a line of prose.
     */
    private function isItem(Paragraph $paragraph, bool $outline): bool
    {
        $bullet = $paragraph->getBulletStyle()?->getBulletType();

        if ($bullet !== null && $bullet !== Bullet::TYPE_NONE) {
            return true;
        }

        if ($paragraph->getAlignment()->getLevel() > 0) {
            return true;
        }

        return $outline;
    }

    /**
     * The inline runs of a paragraph.
     *
     * @return list<Inline>
     */
    private function inlines(Paragraph $paragraph): array
    {
        $inlines = [];

        foreach ($paragraph->getRichTextElements() as $element) {
            if ($element instanceof BreakElement) {
                $inlines[] = Inline::break();

                continue;
            }

            $font = $element instanceof TextElement ? $element->getFont() : null;

            $inlines[] = Inline::text(
                $element instanceof TextElement ? $element->getText() : '',
                bold: $font?->isBold() ?? false,
                italic: $font?->isItalic() ?? false,
                strike: ($font?->getStrikethrough() ?? Font::STRIKE_NONE) !== Font::STRIKE_NONE,
            );
        }

        return $inlines;
    }

    /**
     * The blocks a table becomes.
     *
     * @return list<Block>
     */
    private function table(Table $shape): array
    {
        $rows = [];

        foreach ($shape->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cells[] = Block::cell($this->prose(array_values($cell->getParagraphs())));
            }

            $rows[] = Block::row($cells);
        }

        if ($rows === []) {
            return [];
        }

        return [Block::table($rows)];
    }

    /**
     * The blocks a picture becomes.
     *
     * The picture carries the path it was read from — a `zip://` URL into the
     * package — and the bytes behind it are the ones the file holds, so the
     * picture that is written out is the picture that was in the deck rather
     * than an encoding of it made again.
     *
     * @return list<Block>
     */
    private function picture(Gd|Base64 $shape, int $slide, int $image): array
    {
        if ($this->options->mediaDirectory === null) {
            return [];
        }

        $readable = $this->readablePicture($shape);

        if ($readable === null) {
            return [];
        }

        [$name, $contents] = $readable;

        return [Block::image(new Image(
            $shape->getDescription(),
            sprintf('slide-%d-image-%d.%s', $slide, $image, pathinfo($name, PATHINFO_EXTENSION)),
            $contents,
        ))];
    }

    /**
     * The bytes of a picture, or nothing when it cannot be read.
     *
     * A picture that cannot be read is left out: a deck with an image reference
     * to nothing is a deck with a broken image in it, which is worse than a
     * deck without the image.
     *
     * @return array{0: string, 1: string}|null The name and the bytes of it.
     */
    private function readablePicture(Gd|Base64 $shape): ?array
    {
        $path = $shape->getPath();

        if ($path === '') {
            return null;
        }

        $name = basename($path);
        $contents = @file_get_contents($path);

        if ($contents === false || !$this->isImage($name, $contents)) {
            return null;
        }

        return [$name, $contents];
    }

    /**
     * Whether a name and the bytes behind it are an image.
     *
     * The extension is checked first because it is free, and because the name
     * is the half that has to end up on disk. A leading dot is refused along
     * with it: `.htaccess` is an "extension" in the loose sense that a split on
     * a dot produces, and it is a configuration file rather than a picture.
     *
     * The bytes are checked because the name is chosen by the deck, and an
     * extension is a claim rather than a fact. `getimagesizefromstring()` knows
     * the raster formats and says so by returning false for everything else —
     * and for a vector, which is why one is recognised by its own markup.
     */
    private function isImage(string $name, string $bytes): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (str_starts_with($name, '.') || !in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        if ($extension === 'svg') {
            return str_contains($bytes, '<svg');
        }

        return @getimagesizefromstring($bytes) !== false;
    }
}
