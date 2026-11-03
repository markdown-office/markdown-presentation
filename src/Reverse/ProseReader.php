<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use MarkdownPresentation\Exception\UnreadableDeck;
use PhpOffice\PhpPresentation\Shape\RichText\BreakElement;
use PhpOffice\PhpPresentation\Shape\RichText\Paragraph;
use PhpOffice\PhpPresentation\Shape\RichText\Run;
use PhpOffice\PhpPresentation\Shape\RichText\TextElement;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Font;

/**
 * The blocks a paragraph becomes.
 *
 * The prose of a deck is the one thing every shape that holds words is read through: a text
 * box, a table cell, a free text block and a placeholder's own paragraphs all come through
 * here, so that a bulleted paragraph is a list wherever it was written and a hyperlink is a
 * link wherever it was found.
 */
final class ProseReader
{
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
     * The levels are relative to one another and not to anything else, which is
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
}
