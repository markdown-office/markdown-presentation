<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\MalformedTheme;

/**
 * A `theme.yml`'s `style:` mapping, and the {@see Style} it turns into.
 *
 * The spellings are the file's and not the numbers': the same gap is `block` in a file and
 * `blockGap()` in a renderer, and a heading treatment is `heading-weight: regular` in a file
 * and a `false` in a renderer. {@see Style} carries neither word, and because it never sees a
 * file it holds none of the bounds below either — a style built in code is the caller's own
 * business, where a style written in one is a mistake somebody can be told about.
 *
 * @phpstan-type Numbers array{body: int, title: int, cover: int, section: int, headings: non-empty-list<int>, leading: float}
 * @phpstan-type Rhythm array{margin: int, gutter: int, block: int, rule: int}
 */
final class StyleMapping
{
    /** Every key a `style:` mapping may carry, in the order a `theme.yml` should list them. */
    public const KEYS = [
        'margin', 'gutter', 'block', 'rule',
        'body', 'title', 'cover', 'section', 'headings', 'leading',
        'heading-weight', 'heading-ink',
    ];

    /** The heading face's own reading weight rather than a synthesised bold. */
    public const REGULAR = 'regular';

    /** The bold a heading is drawn in, which a face may not carry at all. */
    public const BOLD = 'bold';

    /** The ink the body text is drawn in, which is also what a title is drawn in. */
    public const INK = 'ink';

    /** The theme's own accent, which a heading in a body may be drawn in instead. */
    public const ACCENT = 'accent';

    /** The two treatments a heading can be given, in the order a failure offers them. */
    public const WEIGHTS = [self::REGULAR, self::BOLD];

    /** The two inks a heading can be drawn in, in the order a failure offers them. */
    public const INKS = [self::INK, self::ACCENT];

    /**
     * What every failure here appends to the file it came from.
     *
     * A `theme.yml` whose `style:` mapping is wrong is fixed in that mapping and not in the rest
     * of the theme, so the message names the part rather than the file — otherwise all of them
     * read as though the whole file were at fault.
     */
    private const STYLE = ' (style)';

    /** The smallest a margin, a gutter, a gap or a rule may be, in pixels. */
    private const MINIMUM_LENGTH = 1;

    /** The smallest a type size may be, in points. */
    private const MINIMUM_POINTS = 1;

    /** The tightest leading may be, as a multiple of a line's own height. */
    private const MINIMUM_LEADING = 1.0;

    /**
     * The numbers a theme's `style:` mapping names, and the library's own for every key it does
     * not name.
     *
     * Every failure names the key and says what it wanted, for the reason
     * {@see MalformedTheme} gives: the writer of a `theme.yml` is somebody who can act on
     * that.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    public static function read(array $data, string $file = 'theme.yml'): Style
    {
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                throw MalformedTheme::unknownKey($file . self::STYLE, (string) $key, self::KEYS);
            }
        }

        $defaults = Style::defaults();
        $weight = self::oneOf($data, 'heading-weight', $file, self::WEIGHTS, self::BOLD);
        $ink = self::oneOf($data, 'heading-ink', $file, self::INKS, self::INK);

        return new Style(
            self::length($data, 'margin', $file, $defaults->margin()),
            self::length($data, 'gutter', $file, $defaults->gutter()),
            self::length($data, 'block', $file, $defaults->blockGap()),
            self::length($data, 'rule', $file, $defaults->ruleWeight()),
            self::points($data, 'body', $file, $defaults->body()),
            self::points($data, 'title', $file, $defaults->title()),
            self::points($data, 'cover', $file, $defaults->cover()),
            self::points($data, 'section', $file, $defaults->section()),
            self::scale($data, 'headings', $file, $defaults->headings()),
            self::readLeading($data, $file, $defaults->leading()),
            $weight === self::BOLD,
            $ink === self::ACCENT,
        );
    }

    /**
     * The `style:` mapping a style is written as, every key of it present.
     *
     * All twelve rather than the handful a file happened to change, so a written theme says
     * the whole of what a theme may say — which is what makes two written themes comparable
     * and {@see self::read()} of a written style the style it was written from.
     *
     * @return array<string, int|float|list<int>|string>
     *
     * @phpstan-return array<string, mixed>&array{Numbers, Rhythm, 'heading-weight': string, 'heading-ink': string}
     */
    public static function write(Style $style): array
    {
        return [
            'margin' => $style->margin(),
            'gutter' => $style->gutter(),
            'block' => $style->blockGap(),
            'rule' => $style->ruleWeight(),
            'body' => $style->body(),
            'title' => $style->title(),
            'cover' => $style->cover(),
            'section' => $style->section(),
            'headings' => $style->headings(),
            'leading' => $style->leading(),
            'heading-weight' => $style->headingIsBold() ? self::BOLD : self::REGULAR,
            'heading-ink' => $style->headingUsesAccent() ? self::ACCENT : self::INK,
        ];
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
            throw MalformedTheme::wrongType($file . self::STYLE, $key, 'a whole number of pixels');
        }

        if ($value < self::MINIMUM_LENGTH) {
            throw MalformedTheme::wrongType($file . self::STYLE, $key, sprintf('a number of pixels of at least %d', self::MINIMUM_LENGTH));
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
            throw MalformedTheme::wrongType($file . self::STYLE, $key, sprintf('a whole number of points, at least %d', self::MINIMUM_POINTS));
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
            throw MalformedTheme::wrongType($file . self::STYLE, $key, 'a list of type sizes in points, shallowest heading first');
        }

        foreach ($value as $size) {
            if (!is_int($size) || $size < self::MINIMUM_POINTS) {
                throw MalformedTheme::wrongType($file . self::STYLE, $key, sprintf('a list of whole point sizes, each at least %d', self::MINIMUM_POINTS));
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
            throw MalformedTheme::wrongType($file . self::STYLE, 'leading', 'a number');
        }

        if ($value < self::MINIMUM_LEADING) {
            throw MalformedTheme::wrongType($file . self::STYLE, 'leading', sprintf('a number of at least %s', self::MINIMUM_LEADING));
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
            throw MalformedTheme::wrongType($file . self::STYLE, $key, sprintf('one of: %s', implode(', ', $choices)));
        }

        return $value;
    }
}