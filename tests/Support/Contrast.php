<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

/**
 * The WCAG contrast ratio between two colours, as the formula defines it.
 *
 * A dark theme is a claim about legibility, and the claim is arithmetic: whether the
 * type a theme's shapes are painted in can be read on the page the theme paints. Reading
 * that off a rendered slide says only that one reader's screen managed it.
 */
final class Contrast
{
    /**
     * A ratio from 1 (the two colours are the same) to 21 (black on white).
     *
     * Colours are `#rrggbb`; a `#rgb` shorthand is expanded, because a theme file
     * cannot carry one but a caller writing a test by hand can.
     */
    public static function ratio(string $one, string $other): float
    {
        $lighter = max(self::luminance($one), self::luminance($other));
        $darker = min(self::luminance($one), self::luminance($other));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * The relative luminance of a colour, from the WCAG 2 definition.
     */
    private static function luminance(string $colour): float
    {
        $hex = ltrim($colour, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channels = [];

        foreach (str_split($hex, 2) as $channel) {
            $value = hexdec($channel) / 255;
            // The linear segment of the transfer function, which is not the square of
            // the value and is the reason a ratio cannot be worked out by eye.
            $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}