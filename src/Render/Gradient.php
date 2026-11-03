<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\MalformedGradient;
use MarkdownPresentation\Render\Free\Colour;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\GradientStop;

/**
 * A gradient: the stops it is painted with, and the way it runs.
 *
 * The angle is CSS's — 0° is to the top, 90° to the right, 180° to the bottom — and the
 * format counts its own angles from the right, ninety degrees out of step, so the
 * conversion is one subtraction.
 *
 * The stops are the ones the format holds: a colour and a position, the position being the
 * one `a:gs/@pos` counts from 0 to 100000.
 */
final class Gradient
{
    /** The angle a gradient runs at when the deck names none: top to bottom. */
    public const DEFAULT_ANGLE = 180;

    /**
     * The most stops a gradient may have.
     *
     * A gradient of a hundred stops is already more than a picture can show, and the stops
     * are read into objects before they are written, so the number of them is a bound on
     * what a deck can ask for.
     */
    public const MAX_STOPS = 100;

    /**
     * The most characters the stops of a gradient may take.
     *
     * The stops are read apart before they are counted, and a list of a million commas
     * would be read into a million pieces before anything could refuse it — so the length is
     * the bound, and the count is the second one.
     */
    private const MAX_LENGTH = 8192;

    /** How far the format's angle is out of step with CSS's. */
    private const OFFSET = 90;

    /**
     * @param list<GradientStop> $stops The stops, in the order they were written.
     * @param bool               $radial Whether the gradient runs from the middle outwards.
     * @param int                $angle  The angle in CSS's degrees, from 0 to 359.
     */
    private function __construct(
        public readonly array $stops,
        public readonly bool $radial,
        public readonly int $angle,
    ) {
    }

    /**
     * A gradient of the stops a deck wrote.
     *
     * The stops are a comma-separated list, and a stop may carry the position it is at as
     * a percentage of the gradient: `accent1@50`. A stop that carries none is spread evenly
     * among the ones that do.
     *
     * @param string $where The subject of a failure: where the gradient was written.
     *
     * @throws MalformedGradient
     */
    public static function of(string $where, string $written, Theme $theme, bool $radial = false, ?int $angle = null): self
    {
        return new self(self::stops($where, $written, $theme), $radial, $angle ?? self::DEFAULT_ANGLE);
    }

    /**
     * The angle a deck wrote, in CSS's degrees.
     *
     * @param string $where The subject of a failure: where the angle was written.
     *
     * @throws MalformedGradient
     */
    public static function angle(string $where, int|string $written): int
    {
        $text = trim((string) $written);

        if (preg_match('/\A-?\d+\z/', $text) !== 1) {
            throw MalformedGradient::angle(
                $where,
                $text,
                'An angle is a whole number of degrees, from 0 to 359: gradient-angle=45.',
            );
        }

        return ((int) $text % 360 + 360) % 360;
    }

    /**
     * The fill the gradient is written as.
     *
     * The stops are set as a list rather than added one at a time: a fill that names no
     * stop has the two its colours name, and a stop added to it would be a third one.
     */
    public function fill(): Fill
    {
        $fill = new Fill();
        $fill->setFillType($this->radial ? Fill::FILL_GRADIENT_PATH : Fill::FILL_GRADIENT_LINEAR);
        $fill->setRotation($this->rotation());
        $fill->setGradientStops($this->stops);

        return $fill;
    }

    /**
     * The angle the format counts in.
     *
     * The format's angles run clockwise from the right, and CSS's run clockwise from the
     * top, so the one is the other less ninety degrees.
     */
    private function rotation(): int
    {
        return ($this->angle + 360 - self::OFFSET) % 360;
    }

    /**
     * The stops a deck wrote.
     *
     * @return list<GradientStop>
     *
     * @throws MalformedGradient
     */
    private static function stops(string $where, string $written, Theme $theme): array
    {
        if (strlen($written) > self::MAX_LENGTH) {
            throw MalformedGradient::written(
                $where,
                $written,
                sprintf('A gradient is at most %d characters long.', self::MAX_LENGTH),
            );
        }

        $parts = explode(',', $written);

        if (count($parts) < 2) {
            throw MalformedGradient::written(
                $where,
                $written,
                'A gradient is two or more stops: gradient="accent1,lt2".',
            );
        }

        if (count($parts) > self::MAX_STOPS) {
            throw MalformedGradient::written(
                $where,
                $written,
                sprintf('A gradient is at most %d stops.', self::MAX_STOPS),
            );
        }

        $colours = [];
        $named = [];

        foreach ($parts as $index => $part) {
            $part = trim($part);

            if ($part === '') {
                throw MalformedGradient::written(
                    $where,
                    $written,
                    'A gradient is two or more stops: gradient="accent1,lt2".',
                );
            }

            [$colour, $position] = self::stop($where, $written, $part, $theme);

            $colours[] = $colour;
            $named[] = $position;
        }

        $positions = self::spread($where, $written, $named);

        $stops = [];

        foreach ($colours as $index => $colour) {
            $stops[] = new GradientStop($positions[$index], $colour);
        }

        return $stops;
    }

    /**
     * Where each stop of the gradient sits.
     *
     * A stop that names a position is at it, and a stop that names none is spread evenly
     * among the ones that do — which is what a gradient in CSS does, and what a reader of
     * the deck expects. The ends are the boundaries: a stop before the first that names a
     * position is spread from the start of the gradient, and a stop after the last is spread
     * to its end.
     *
     * The positions are then checked, because a gradient whose stops do not move forward is
     * a gradient the reader cannot draw.
     *
     * @param list<int|null> $named The position each stop named, or null where it named none.
     *
     * @return list<int>
     *
     * @throws MalformedGradient
     */
    private static function spread(string $where, string $written, array $named): array
    {
        $count = count($named);
        $fixed = [];

        // The first stop of a gradient is at its start and the last at its end, whatever
        // they name — which is what a gradient in CSS does with the ones it leaves out.
        if ($named[0] === null) {
            $fixed[0] = GradientStop::POSITION_START;
        }

        if ($named[$count - 1] === null) {
            $fixed[$count - 1] = GradientStop::POSITION_END;
        }

        foreach ($named as $index => $position) {
            if ($position !== null) {
                $fixed[$index] = $position;
            }
        }

        ksort($fixed);

        $positions = $fixed;
        $anchors = array_keys($fixed);

        foreach ($anchors as $number => $anchor) {
            $next = $anchors[$number + 1] ?? null;

            if ($next === null) {
                break;
            }

            // The stops between two fixed ones are spread over the gap between them, at
            // equal intervals: the gap is divided by the number of intervals, which is one
            // more than the number of stops in it.
            $between = $next - $anchor - 1;

            if ($between < 1) {
                continue;
            }

            $gap = $fixed[$next] - $fixed[$anchor];

            for ($i = 1; $i <= $between; $i++) {
                $positions[$anchor + $i] = (int) round($fixed[$anchor] + $gap * $i / ($between + 1));
            }
        }

        ksort($positions);

        $previous = -1;

        foreach ($positions as $position) {
            if ($position <= $previous) {
                throw MalformedGradient::written(
                    $where,
                    $written,
                    'The stops of a gradient move forward: a stop at '
                    . $previous . '% and one at ' . $position . '% are the same place.',
                );
            }

            $previous = $position;
        }

        return array_values($positions);
    }

    /**
     * One stop: the colour it is, and the position it is at where it named one.
     *
     * @return array{0: \PhpOffice\PhpPresentation\Style\Color, 1: int|null}
     *
     * @throws MalformedGradient
     */
    private static function stop(string $where, string $written, string $part, Theme $theme): array
    {
        $position = null;
        $name = $part;

        if (str_contains($part, '@')) {
            [$name, $at] = explode('@', $part, 2);
            $position = self::position($where, $written, $at);
        }

        $name = strtolower(trim($name));

        if (Colour::isNone($name)) {
            throw MalformedGradient::written(
                $where,
                $written,
                'A stop is a colour, and none is not one: gradient="accent1,lt2".',
            );
        }

        $colour = Colour::resolve($name, $theme);

        if ($colour === null) {
            throw MalformedGradient::written($where, $written, Theme::colourAdvice());
        }

        return [$colour, $position];
    }

    /**
     * The position of a stop, in the scale the format counts them in.
     *
     * The deck writes it as a percentage, and the format counts from 0 to 100000.
     *
     * @throws MalformedGradient
     */
    private static function position(string $where, string $written, string $at): int
    {
        $at = trim($at);

        if (preg_match('/\A\d+\z/', $at) !== 1 || (int) $at > 100) {
            throw MalformedGradient::written(
                $where,
                $written,
                'A stop\'s position is a whole number of percent, from 0 to 100: gradient="accent1@50,lt2".',
            );
        }

        return (int) $at * 1000;
    }
}
