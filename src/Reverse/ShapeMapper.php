<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use MarkdownPresentation\Exception\UnreadableDeck;
use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\Drawing\Base64;
use PhpOffice\PhpPresentation\Shape\Drawing\Gd;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\RichText\BreakElement;
use PhpOffice\PhpPresentation\Shape\RichText\Paragraph;
use PhpOffice\PhpPresentation\Shape\RichText\TextElement;
use PhpOffice\PhpPresentation\Shape\Table;
use PhpOffice\PhpPresentation\Style\Bullet;
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
 *   colour and its face do not, because a deck has no way to say them and
 *   because the design of the new deck is not the design of the old one.
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
     *        shapes and not the pictures, so a slide with a text box and then a
     *        picture names the picture after the second shape.
     * @param bool  $outline Whether the shape is a body placeholder, whose
     *        paragraphs are an outline whether or not the slide says so.
     *
     * @return list<Block>
     */
    public function blocks(AbstractShape $shape, int $slide, int $image, bool $outline = false): array
    {
        if ($shape instanceof Table) {
            return $this->table($shape);
        }

        if ($shape instanceof Gd || $shape instanceof Base64) {
            return $this->picture($shape, $slide, $image);
        }

        if ($shape instanceof RichText) {
            return $this->prose(
                array_values($shape->getParagraphs()),
                $outline,
                $this->linkOf($shape),
            );
        }

        return [];
    }

    /**
     * The address a shape's text is a link to, or an empty string when it is
     * not one.
     *
     * A link to another slide of the same deck is not an address, and a deck
     * has no way to say "go to slide three", so those are left out rather
     * than written as a link to a file that is not there.
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
     * @return list<Block>
     */
    private function items(array $items, int &$position, int $level): array
    {
        $blocks = [];

        // The walk recurses once per level of nesting, and the level comes from
        // the deck: a shape whose paragraphs sit at ever deeper levels is a
        // shape that never comes back, and the walk takes the stack with it
        // rather than returning an error. The ceiling is checked where the
        // nesting is read, so a deck that goes past it is refused.
        if ($level > Options::MAX_NESTING) {
            throw UnreadableDeck::nestedTooDeep(Options::MAX_NESTING);
        }

        while (isset($items[$position]) && $items[$position]['level'] >= $level) {
            if ($items[$position]['level'] > $level) {
                // The item is deeper than this level, so it belongs to the item
                // before it rather than beside it. The walk that reads the
                // nested items takes the position with it, and what comes back
                // may be at a level between the two — a list whose levels jump
                // from 0 to 2 and back to 1 gives an item at level 1 where the
                // nested walk left off.
                //
                // That item is a child of the item being read, at a level of its
                // own, so it is read as a nested list of its own rather than
                // dropped: ending the walk here lost every item from the jump
                // onwards, with nothing to say so.
                $ordered = $items[$position]['ordered'];

                // The walk is pointed at the item it is about, so it always takes
                // that item: the level it is given is the level that item has.
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

        $path = $shape->getPath();

        if ($path === '') {
            return [];
        }

        $name = basename($path);
        $contents = @file_get_contents($path);

        if ($contents === false || ! $this->isImage($name, $contents)) {
            return [];
        }

        return [Block::image(new Image(
            $shape->getDescription(),
            sprintf('slide-%d-image-%d.%s', $slide, $image, pathinfo($name, PATHINFO_EXTENSION)),
            $contents,
        ))];
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

        if (str_starts_with($name, '.') || ! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        if ($extension === 'svg') {
            return str_contains($bytes, '<svg');
        }

        return @getimagesizefromstring($bytes) !== false;
    }
}
