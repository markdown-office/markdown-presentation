<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Render\SlideGeometry;

/**
 * A number a deck writes as a measurement.
 *
 * One pattern and two readings of it, because two kinds of thing are measured here and a bare
 * number means the unit each of them is written in. A position or a size on the slide is
 * pixels — the 960 × 540 grid {@see SlideGeometry} holds and the numbers in the README are in —
 * and a type size or a stroke is points, which is what a size of type is in everywhere else in
 * this library ({@see \MarkdownPresentation\Theme\Style::body()}) and what an author writing
 * `font-size=24` means. Refusing the bare form in either place would push a model into writing
 * `font-size=24pt` and `left=320px`, which is a unit of ceremony for a deck nobody is measuring
 * by hand.
 *
 * `px` and `pt` are both accepted everywhere, and `%` only where there is an axis to resolve it
 * against — a horizontal measurement against {@see SlideGeometry::WIDTH}, a vertical one
 * against `HEIGHT`, and nowhere at all for a stroke, a padding or a radius.
 */
final class Length
{
    /**
     * A sign, then a number with or without a fraction, then an optional unit.
     *
     * A leading `.` is allowed so that `.5` is half rather than refused, which is the
     * fraction-first spelling a percentage is written in.
     */
    private const PATTERN = '/\A([+-]?(?:\d+(?:\.\d+)?|\.\d+))(%|px|pt)?\z/i';

    /**
     * How an attribute spells a measurement, or what it defaults to.
     *
     * A bare `{width}` asks for the attribute rather than for its value, and
     * {@see DirectiveAttributes::getString()} answers a bare one with the default — which would
     * silently draw a full-bleed shape out of what was a typo. Refused here instead, at the
     * one place every measurement comes through.
     *
     * @throws MalformedShape
     */
    public static function written(
        int $slide,
        string $name,
        string $attribute,
        DirectiveAttributes $attributes,
        string $default,
    ): string {
        $value = $attributes->get($attribute);

        if ($value === null) {
            return $default;
        }

        if ($value === true) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $attribute,
                'A bare attribute asks for the attribute rather than for its value: write width=320.',
            );
        }

        return $value;
    }

    /**
     * A length on the slide, in pixels.
     *
     * @param int|null $of {@see SlideGeometry::WIDTH} or `HEIGHT` for a measurement that may be
     *                     a percentage of the slide, or null for one that may not.
     *
     * @throws MalformedShape
     */
    public static function measure(int $slide, string $name, string $attribute, string $written, ?int $of): float
    {
        [$number, $unit] = self::parse($slide, $name, $attribute, $written);

        if ($unit === '%') {
            return self::percentage($number, $of ?? throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                'Only a position or a size may be a percentage of the slide; this one is a length in its own right.',
            ));
        }

        // Pixels, with or without the unit an author wrote it with. Points are the one
        // conversion here, and {@see self::points()} is its mirror for a size of type.
        return $unit === 'pt' ? $number * SlideGeometry::PIXELS_PER_POINT : $number;
    }

    /**
     * The same length, rounded the way a shape has to be written.
     *
     * `a:off` is a signed coordinate and `a:ext` an unsigned one, so a box is whole pixels.
     * Rounding rather than truncating is what the rest of the library does — see
     * {@see SlideGeometry::band()} — and a shape whose width came out one pixel short of the
     * right edge is a shape that does not reach it.
     *
     * @throws MalformedShape
     */
    public static function pixels(int $slide, string $name, string $attribute, string $written, ?int $of): int
    {
        return (int) round(self::measure($slide, $name, $attribute, $written, $of));
    }

    /**
     * A length of type or of line, in points.
     *
     * A bare number is points rather than pixels, which is the one place this grammar and
     * {@see self::measure()} read a number differently. A type size is written in points in
     * every other surface this library has — a theme's `body:`, the sizes in
     * {@see \MarkdownPresentation\Theme\Style} — and `font-size=24` meaning 18pt because it
     * was multiplied by four thirds would be a trap rather than a consistency.
     *
     * There is no axis for a percentage to resolve against, so one is refused.
     *
     * @throws MalformedShape
     */
    public static function points(int $slide, string $name, string $attribute, string $written): float
    {
        [$number, $unit] = self::parse($slide, $name, $attribute, $written);

        return match ($unit) {
            'pt', '' => $number,
            'px' => $number / SlideGeometry::PIXELS_PER_POINT,
            // The only other unit the pattern admits, so the default is unreachable rather than
            // silently wrong: there is no axis a size of type is a percentage of.
            default => throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                'A size of type or of line is a number of points or of pixels, not a percentage of the slide.',
            ),
        };
    }

    /**
     * @return array{0: float, 1: string} The number and the unit, lower-cased and with a bare
     *                                    number already read as the unit the caller asked for.
     *
     * @throws MalformedShape
     */
    private static function parse(int $slide, string $name, string $attribute, string $written): array
    {
        if (preg_match(self::PATTERN, $written, $match) !== 1) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                'A length is a number, a number of pixels, a number of points, or — for a position or a size '
                . '— a percentage of the slide: left=40%, left=320, font-size=24pt.',
            );
        }

        $number = (float) $match[1];

        if ($number < 0) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                'A length is not negative: a free shape lies within the slide, so write left=0 rather than a negative one.',
            );
        }

        return [$number, strtolower($match[2] ?? '')];
    }

    /**
     * @param int $of The axis to resolve against, which {@see self::measure()} has already
     *                 established by refusing a percentage with nowhere to resolve against.
     */
    private static function percentage(float $number, int $of): float
    {
        return $number / 100 * $of;
    }
}
