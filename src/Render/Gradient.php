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
        $parts = explode(',', $written);

        if (count($parts) < 2) {
            throw MalformedGradient::written(
                $where,
                $written,
                'A gradient is two or more stops: gradient="accent1,lt2".',
            );
        }

        $stops = [];
        $spread = count($parts) - 1;

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

            $stops[] = new GradientStop(
                $position ?? (int) round($index / $spread * GradientStop::POSITION_END),
                $colour,
            );
        }

        return $stops;
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
     * The position of a stop, as the percentage the deck wrote it as.
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
