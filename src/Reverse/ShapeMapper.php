<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

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
 * Three things decide what a shape becomes, and only three:
 *
 * - **What kind of shape it is.** A text box is prose, a table is a table, a
 *   picture is a picture.
 * - **What it says.** A run's bold, italic and strike travel; its size, its
 *   colour and its face do not, because a deck has no way to say them and
 *   because the design of the new deck is not the design of the old one.
 * - **Where it sits.** Only for the order: a slide is a set of boxes, and the
 *   boxes have to be put in an order, and the order is where they are.
 */
final class ShapeMapper
{
    public function __construct(private readonly Options $options)
    {
    }

    /**
     * The blocks a shape becomes.
     *
     * @param int   $slide   The slide's number, counting from one, which names
     *        the pictures written out of it.
     * @param int   $image   How many pictures this slide has already given, so
     *        that the names do not collide.
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

        while (isset($items[$position]) && $items[$position]['level'] >= $level) {
            if ($items[$position]['level'] > $level) {
                break;
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
     * A picture carries its bytes rather than a path, because the path is
     * decided when the file is written out and not before, and the writer
     * needs the bytes to write it with.
     *
     * @return list<Block>
     */
    private function picture(Gd|Base64 $shape, int $slide, int $image): array
    {
        if ($this->options->mediaDirectory === null) {
            return [];
        }

        $extension = $shape->getExtension();

        if ($extension === '') {
            return [];
        }

        return [Block::image(new Image(
            $shape->getDescription(),
            sprintf('slide-%d-image-%d.%s', $slide, $image, $extension),
            $shape->getContents(),
        ))];
    }
}
