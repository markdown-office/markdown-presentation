<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

/**
 * The numbers a theme may set: its type scale, its spacing rhythm and how its headings are
 * treated.
 *
 * A theme that can only name twelve colours and three fonts gives three decks that differ in
 * a swatch and nothing else — the same layout, the same sizes and the same gaps in all three,
 * so choosing a theme changes nothing a reader would notice on the second slide. These are
 * the numbers that decide what a slide *looks* like rather than what colour it is.
 *
 * Nothing here knows how a `theme.yml` spells any of them: {@see StyleMapping} reads the
 * twelve out of one and writes them back, so every name below is the library's own, and a
 * style built in code is held to no bound but the types it takes. A margin of zero is a
 * layout mistake rather than a malformed theme, and a file is where that is caught.
 *
 * The code and table sizes are deliberately *not* here. They are the density of a block
 * rather than part of the type scale: a table is legible at twelve points whatever the body
 * text is set at, and tying the two together would make a deck with a large body unreadable
 * in its tables.
 */
final class Style
{
    /**
     * The library's own numbers, with a default for every one of them.
     *
     * Every parameter has a default, so `new Style()` and {@see self::defaults()} are the
     * same thing and a caller that wants the library's own numbers can say so without naming
     * all twelve of them. {@see self::defaults()} exists for the caller that wants the
     * defaults *by name* rather than by remembering them.
     *
     * The values are the ones {@see \MarkdownPresentation\Render\SlideGeometry} has always
     * used, so a `theme.yml` that names no `style:` renders exactly what it rendered before this
     * existed — which is the whole of the reason they are the defaults rather than a choice.
     *
     * @param non-empty-list<int> $headings Sizes by depth, shallowest first.
     */
    public function __construct(
        private readonly int $margin = 40,
        private readonly int $gutter = 20,
        private readonly int $block = 12,
        private readonly int $rule = 2,
        private readonly int $body = 16,
        private readonly int $title = 28,
        private readonly int $cover = 40,
        private readonly int $section = 44,
        private readonly array $headings = [22, 20, 18, 16, 14],
        private readonly float $leading = 1.2,
        private readonly bool $headingIsBold = true,
        private readonly bool $headingUsesAccent = false,
    ) {
    }

    /**
     * The library's own numbers, named rather than remembered.
     */
    public static function defaults(): self
    {
        return new self();
    }

    /** The margin left all the way round the page, in pixels. */
    public function margin(): int
    {
        return $this->margin;
    }

    /**
     * The gap that separates two things rather than enclosing them, in pixels: two columns,
     * a bullet from its text, one level of a list from the one above.
     */
    public function gutter(): int
    {
        return $this->gutter;
    }

    /** The gap left between two blocks in a column, in pixels. */
    public function blockGap(): int
    {
        return $this->block;
    }

    /**
     * How thick a thematic break is drawn, in pixels.
     *
     * The bar itself rather than the room it occupies: {@see
     * \MarkdownPresentation\Render\SlideGeometry::RULE_HEIGHT} is how much of the column the
     * break takes, and that is a layout decision rather than a theme's.
     */
    public function ruleWeight(): int
    {
        return $this->rule;
    }

    /** The size body text is drawn at, in points. */
    public function body(): int
    {
        return $this->body;
    }

    /** The size a content slide's title is drawn at, in points. */
    public function title(): int
    {
        return $this->title;
    }

    /** The size the title layout's title is drawn at, in points. */
    public function cover(): int
    {
        return $this->cover;
    }

    /** The size a section layout's title is drawn at, in points. */
    public function section(): int
    {
        return $this->section;
    }

    /**
     * The sizes a heading in a body is drawn at, by its depth, shallowest first.
     *
     * A scale shorter than Markdown has levels runs out at its last entry, so the deepest
     * headings take the smallest size it names rather than failing on a document CommonMark
     * accepts.
     *
     * @return non-empty-list<int>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /** The multiple of a line's own height one line of type is drawn at. */
    public function leading(): float
    {
        return $this->leading;
    }

    /**
     * Whether a heading is drawn in a bold weight or its face's own.
     *
     * A theme that says `regular` gets the reading weight of the heading face rather than a
     * synthesised bold, which is what a serif deck with a large title wants and what a sans
     * deck with a heavy title does not.
     */
    public function headingIsBold(): bool
    {
        return $this->headingIsBold;
    }

    /**
     * Whether a heading is drawn in the theme's accent rather than in the ink the body text
     * is.
     *
     * Asked as a boolean rather than returning the word, because the two things a caller does
     * with the answer are both "paint it with this" and neither wants the spelling.
     */
    public function headingUsesAccent(): bool
    {
        return $this->headingUsesAccent;
    }
}
