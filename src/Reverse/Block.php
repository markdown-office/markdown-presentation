<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

/**
 * One block-level element read back out of a deck.
 *
 * A tagged union rather than a class per kind: {@see DeckWriter} switches on
 * `kind`, and one type lets the grouping pass rearrange a flat sequence of
 * units without a cast at every step.
 */
final class Block
{
    public const PARAGRAPH = 'paragraph';
    public const LIST = 'list';
    public const ITEM = 'item';
    public const TABLE = 'table';
    public const ROW = 'row';
    public const CELL = 'cell';
    public const IMAGE = 'image';

    /**
     * @param string         $kind     One of the `PARAGRAPH`, `LIST`, `ITEM`,
     *        `TABLE`, `ROW`, `CELL` or `IMAGE` constants.
     * @param list<Block>    $children List items, a table's rows, and so on.
     * @param list<Inline>   $inlines  The inline content of a paragraph.
     * @param bool           $ordered  Whether a list is numbered rather than
     *        bulleted.
     * @param Image|null     $image    The picture, for a block that is one.
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $children = [],
        public readonly array $inlines = [],
        public readonly bool $ordered = false,
        public readonly ?Image $image = null,
    ) {
    }

    /**
     * @param list<Inline> $inlines
     */
    public static function paragraph(array $inlines): self
    {
        return new self(self::PARAGRAPH, inlines: $inlines);
    }

    /**
     * @param list<Block> $items `ITEM` blocks.
     * @param bool        $ordered Whether the list is numbered rather than
     *        bulleted.
     */
    public static function list(array $items, bool $ordered = false): self
    {
        return new self(self::LIST, children: $items, ordered: $ordered);
    }

    /**
     * @param list<Block> $blocks
     */
    public static function item(array $blocks): self
    {
        return new self(self::ITEM, children: $blocks);
    }

    /**
     * @param list<Block> $rows `ROW` blocks.
     */
    public static function table(array $rows): self
    {
        return new self(self::TABLE, children: $rows);
    }

    /**
     * @param list<Block> $cells
     */
    public static function row(array $cells): self
    {
        return new self(self::ROW, children: $cells);
    }

    /**
     * @param list<Block> $blocks
     */
    public static function cell(array $blocks): self
    {
        return new self(self::CELL, children: $blocks);
    }

    public static function image(Image $image): self
    {
        return new self(self::IMAGE, image: $image);
    }

    public function is(string ...$kinds): bool
    {
        return in_array($this->kind, $kinds, true);
    }
}
