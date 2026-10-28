<?php

declare(strict_types=1);

/**
 * Draws the logos of the corporate template.
 *
 *   php example/corporate-template/tools/make-logo.php
 *
 * A mark rather than a wordmark: the name beside it is text in the deck, so it is
 * one edit rather than a new picture. The mark is an aperture — a crosshair and a
 * pupil — because the company makes measurement instruments and an aperture is
 * what a measurement is taken through.
 *
 * Two of them, because a brand kit has two: the mark in the primary colour for a
 * light page, and the mark in white for a dark one. A dark slide and a light one
 * are the same deck, and the furniture on them cannot be the same furniture.
 *
 * The colours are the theme's, and they are read out of it rather than written in:
 * the logo and the theme have to agree, and a hex in two places is two places to
 * forget.
 */

$root = __DIR__;
$theme = \file_get_contents($root . '/theme.yml');

if ($theme === false) {
    \fwrite(\STDERR, "cannot read the theme\n");
    exit(1);
}

/**
 * A colour of the theme, as the theme spells it.
 */
$colour = static function (string $slot) use ($theme): string {
    if (\preg_match('/^\s*' . \preg_quote($slot, '/') . ':\s*"?#?([0-9A-Fa-f]{6})"?/m', $theme, $match) !== 1) {
        \fwrite(\STDERR, "the theme names no colour " . $slot . "\n");
        exit(1);
    }

    return $match[1];
};

/**
 * The mark, in one colour, with the crosshair knocked out of it.
 *
 * The crosshair is transparent rather than white, so the mark is one shape on any
 * background: the page shows through it on a light slide and the panel shows
 * through it on a dark one. A mark that carried its own white would be a white
 * crosshair on a dark panel, which is nothing at all.
 */
$mark = static function (int $size, string $hex): \GdImage {
    $image = \imagecreatetruecolor($size, $size);

    \imagesavealpha($image, true);
    \imagefill($image, 0, 0, \imagecolorallocatealpha($image, 0, 0, 0, 127));

    $ink = \imagecolorallocate($image, ...\array_map('hexdec', \str_split($hex, 2)));
    $hole = \imagecolorallocatealpha($image, 0, 0, 0, 127);

    \imagefilledellipse($image, $size / 2, $size / 2, $size - 8, $size - 8, $ink);

    // From here the pixels are written as they are rather than blended, so the
    // crosshair replaces the disc with the ground showing through it.
    \imagealphablending($image, false);
    \imagesetthickness($image, 26);

    foreach ([[$size / 2, 96, $size / 2, 186], [$size / 2, 326, $size / 2, 416]] as [$x1, $y1, $x2, $y2]) {
        \imageline($image, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $hole);
    }

    foreach ([[96, $size / 2, 186, $size / 2], [326, $size / 2, 416, $size / 2]] as [$x1, $y1, $x2, $y2]) {
        \imageline($image, (int) $x1, (int) $y1, (int) $x2, (int) $y2, $hole);
    }

    \imagefilledellipse($image, $size / 2, $size / 2, 44, 44, $hole);

    return $image;
};

$size = 512;

foreach (['logo.png' => $colour('accent1'), 'logo-light.png' => 'FFFFFF'] as $file => $hex) {
    $image = $mark($size, $hex);

    if (!\imagepng($image, $root . '/' . $file)) {
        \fwrite(\STDERR, "cannot write " . $file . "\n");
        exit(1);
    }

    \printf("wrote %s/%s\n", $root, $file);
}
