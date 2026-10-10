<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

/**
 * The Word style slots a `styles.yml` may name.
 *
 * These are the slot names of the Word half's `Configuration\Styles`, listed here
 * because this half has to check them and that half has to define them. The two
 * lists have to stay in step: a slot added to `Styles` without a line here is a
 * slot a theme cannot override, and one removed from `Styles` without a line here
 * is a slot a theme may name and get nowhere.
*/
final class StyleSlots
{
    public const HEADING_1 = 'heading.1';
    public const HEADING_2 = 'heading.2';
    public const HEADING_3 = 'heading.3';
    public const HEADING_4 = 'heading.4';
    public const HEADING_5 = 'heading.5';
    public const HEADING_6 = 'heading.6';

    public const PARAGRAPH = 'paragraph';
    public const BLOCK_QUOTE = 'blockQuote';
    public const CODE_BLOCK = 'codeBlock';
    public const THEMATIC_BREAK = 'thematicBreak';
    public const HTML_FALLBACK = 'htmlFallback';
    public const LIST_PARAGRAPH = 'listParagraph';

    public const CODE_FONT = 'codeFont';
    public const LINK_FONT = 'linkFont';

    public const BULLET_LIST = 'bulletList';
    public const ORDERED_LIST = 'orderedList';

    public const TABLE = 'table';
    public const TABLE_HEADER_ROW = 'tableHeaderRow';
    public const TABLE_CELL = 'tableCell';

    /**
     * Every slot, in the order `Styles::defaults()` gives them.
     *
     * @return list<string>
    */
    public static function names(): array
    {
        return [
            self::HEADING_1,
            self::HEADING_2,
            self::HEADING_3,
            self::HEADING_4,
            self::HEADING_5,
            self::HEADING_6,
            self::PARAGRAPH,
            self::BLOCK_QUOTE,
            self::CODE_BLOCK,
            self::THEMATIC_BREAK,
            self::HTML_FALLBACK,
            self::LIST_PARAGRAPH,
            self::CODE_FONT,
            self::LINK_FONT,
            self::BULLET_LIST,
            self::ORDERED_LIST,
            self::TABLE,
            self::TABLE_HEADER_ROW,
            self::TABLE_CELL,
        ];
    }
}