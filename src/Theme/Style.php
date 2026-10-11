<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\MalformedTheme;

/**
 * The numbers a theme may set: its type scale, its spacing rhythm and how its headings are
 * treated.
 *
 * A theme that can only name twelve colours and three fonts gives three decks that differ in
 * a swatch and nothing else — the same layout, the same sizes and the same gaps in all three,
 * so choosing a theme changes nothing a reader would notice on the second slide. These are
 * the numbers that decide what a slide *looks* like rather than what colour it is.
 *
 * Every key is optional and takes the library's own value where a file does not mention it,
 * which is what lets a theme name one thing — a wider margin, a heavier rule — and leave the
 * rest of the type scale alone. An empty mapping is the same theme as no mapping at all.
 *
 * The code and table sizes are deliberately *not* here. They are the density of a block
 * rather than part of the type scale: a table is legible at twelve points whatever the body
 * text is set at, and tying the two together would make a deck with a large body unreadable
 * in its tables.
 *
 * @phpstan-type Numbers array{body: int, title: int, cover: int, section: int, headings: non-empty-list<int>, leading: float}
 * @phpstan-type Rhythm array{margin: int, gutter: int, block: int, rule: int}
 */
final class Style
{
    /** Every key a `style:` mapping may carry, in the order a `theme.yml` should list them. */
    public const KEYS = [
        'margin', 'gutter', 'block', 'rule',
        'body', 'title', 'cover', 'section', 'headings', 'leading',
        'heading-weight', 'heading-ink',
    ];

    /** The two treatments a heading can be given, and the one that is the default. */
    public const WEIGHTS = ['regular', 'bold'];

    /** The two inks a heading can be drawn in, and the one that is the default. */
    public const INKS = ['ink', 'accent'];

    /** A heading of a single weight, or none: the reading weight is a face's own. */
    private const REGULAR = 'regular';

    /** The default type scale, in points, and the default leading as a multiple of a line's own
     * height. The values {@see \MarkdownPresentation\Render\SlideGeometry} has always used, so
     * a `theme.yml` that names no `style:` renders exactly what it rendered before this
     * existed — which is the whole of the reason they are the defaults rather than a choice.
     */

    /**
     * The numbers a theme may set, with the library's own defaults.
 *
     * Every parameter has a default, so `new Style()` and {@see self::defaults()} are the
     * same thing and a caller that wants the library's own numbers can say so without naming
     * all twelve of them. {@see self::defaults()} exists for the caller that wants the
     * defaults *by name* rather than by remembering them.
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
        private readonly string $headingWeight = self::WEIGHTS[1],
        private readonly string $headingInk = self::INKS[0],
    ) {
    }

    /**
     * The library's own numbers, named rather than remembered.
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * A theme's `style:` mapping.
     *
     * Every failure names the key and says what it wanted, for the reason
     * {@see MalformedTheme} gives: the writer of a `theme.yml` is somebody who can act on
     * that.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    public static function fromArray(array $data, string $file = 'theme.yml'): self
    {
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                throw MalformedTheme::unknownKey($file . ' (style)', (string) $key, self::KEYS);
            }
        }

        $style = self::defaults();

        return new self(
            self::length($data, 'margin', $file, $style->margin),
            self::length($data, 'gutter', $file, $style->gutter),
            self::length($data, 'block', $file, $style->block),
            self::length($data, 'rule', $file, $style->rule),
            self::points($data, 'body', $file, $style->body),
            self::points($data, 'title', $file, $style->title),
            self::points($data, 'cover', $file, $style->cover),
            self::points($data, 'section', $file, $style->section),
            self::scale($data, 'headings', $file, $style->headings),
            self::readLeading($data, $file, $style->leading()),
            self::oneOf($data, 'heading-weight', $file, self::WEIGHTS, $style->headingWeight),
            self::oneOf($data, 'heading-ink', $file, self::INKS, $style->headingInk),
        );
    }

    /**
     * A pixel measurement, and the smallest one a layout can be drawn at.
     *
     * A margin or a gap of zero is refused because a shape laid out past the bottom of its box
     * asked for exactly that: the room left went negative and both the size and the centring
     * carried it on. The floor is {@see \MarkdownPresentation\Render\SlideGeometry::MIN_SIZE}
     * because a box of nothing is not a layout.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    private static function length(array $data, string $key, string $file, int $fallback): int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return $fallback;
        }

        if (!is_int($value)) {
            throw MalformedTheme::wrongType($file . ' (style)', $key, 'a whole number of pixels');
        }

        if ($value < self::MINIMUM_LENGTH) {
            throw MalformedTheme::wrongType($file . ' (style)', $key, sprintf('a number of pixels of at least %d', self::MINIMUM_LENGTH));
        }

        return $value;
    }

    /**
     * A type size in points. A whole number, because DrawingML stores one in hundredths of a
     * point and the fractional sizes a caller reaches for here are a mistake rather than a
     * decision.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    private static function points(array $data, string $key, string $file, int $fallback): int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return $fallback;
        }

        if (!is_int($value) || $value < self::MINIMUM_POINTS) {
            throw MalformedTheme::wrongType($file . ' (style)', $key, sprintf('a whole number of points, at least %d', self::MINIMUM_POINTS));
        }

        return $value;
    }

    /**
     * The heading scale by depth, which is a list rather than a number.
     *
     * One entry is allowed, and then every heading is that size: a theme that wants its
     * headings flat rather than stepped says so here instead of writing the same number five
     * times.
     *
     * @param array<array-key, mixed> $data
     * @param non-empty-list<int>      $fallback
     *
     * @return non-empty-list<int>
     *
     * @throws MalformedTheme
     */
    private static function scale(array $data, string $key, string $file, array $fallback): array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return $fallback;
        }

        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw MalformedTheme::wrongType($file . ' (style)', $key, 'a list of type sizes in points, shallowest heading first');
        }

        foreach ($value as $size) {
            if (!is_int($size) || $size < self::MINIMUM_POINTS) {
                throw MalformedTheme::wrongType($file . ' (style)', $key, sprintf('a list of whole point sizes, each at least %d', self::MINIMUM_POINTS));
            }
        }

        return $value;
    }

    /**
     * The multiple of a line's own height one line is drawn at.
     *
     * A float rather than a whole number because leading is the one measurement here that is
     * genuinely fractional — 1.15 and 1.4 are both ordinary values and neither is a rounding
     * of the other.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    private static function readLeading(array $data, string $file, float $fallback): float
    {
        $value = $data['leading'] ?? null;

        if ($value === null) {
            return $fallback;
        }

        if (!is_int($value) && !is_float($value)) {
            throw MalformedTheme::wrongType($file . ' (style)', 'leading', 'a number');
        }

        if ($value < self::MINIMUM_LEADING) {
            throw MalformedTheme::wrongType($file . ' (style)', 'leading', sprintf('a number of at least %s', self::MINIMUM_LEADING));
        }

        return (float) $value;
    }

    /**
     * A key that takes one of a fixed list of words.
     *
     * @param array<array-key, mixed> $data
     * @param list<string>             $choices
     *
     * @throws MalformedTheme
     */
    private static function oneOf(array $data, string $key, string $file, array $choices, string $fallback): string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return $fallback;
        }

        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw MalformedTheme::wrongType($file . ' (style)', $key, sprintf('one of: %s', implode(', ', $choices)));
        }

        return $value;
    }

    /** The smallest a margin, a gutter, a gap or a rule may be, in pixels. */
    private const MINIMUM_LENGTH = 1;

    /** The smallest a type size may be, in points. */
    private const MINIMUM_POINTS = 1;

    /** The tightest leading may be, as a multiple of a line's own height. */
    private const MINIMUM_LEADING = 1.0;

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
        return $this->headingWeight === self::WEIGHTS[1];
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
        return $this->headingInk === self::INKS[1];
    }

    /**
     * The numbers this style was built from, for a caller that writes them down.
     *
     * @return array<string, int|float|list<int>|string>
     *
     * @phpstan-return array<string, mixed>&array{Numbers, Rhythm, 'heading-weight': string, 'heading-ink': string}
     */
    public function toArray(): array
    {
        return [
            'margin' => $this->margin,
            'gutter' => $this->gutter,
            'block' => $this->block,
            'rule' => $this->rule,
            'body' => $this->body,
            'title' => $this->title,
            'cover' => $this->cover,
            'section' => $this->section,
            'headings' => $this->headings,
            'leading' => $this->leading,
            'heading-weight' => $this->headingIsBold() ? self::WEIGHTS[1] : self::REGULAR,
            'heading-ink' => $this->headingUsesAccent() ? self::INKS[1] : self::INKS[0],
        ];
    }
}