<?php

declare(strict_types=1);

namespace MarkdownPresentation;

use ZipArchive;

/**
 * The theme part of a package, and the name in it.
 *
 * A `.pptx` may hold more than one theme: a deck that has notes slides carries a theme for
 * its notes master as well, and it is a copy of the first. The theme of the *slide master* is
 * the deck's own, and it is found by the relationship that names it rather than by being the
 * first part with the name — a package is free to number its parts as it likes.
 *
 * The name in the theme part is where PowerPoint keeps the name of a theme, and it is where
 * this library keeps the name of the theme a deck was drawn in: see
 * {@see \MarkdownPresentation\Render\ThemeNamePass}.
 */
final class ThemePart
{
    /**
     * The relationships of a slide master, and the theme one of them names.
     */
    private const MASTER = '~^ppt/slideMasters/_rels/slideMaster\d+\.xml\.rels$~';

    /**
     * The `name` attribute of the `a:theme` element.
     */
    private const NAME = '~<a:theme\b[^>]*\bname="([^"]*)"~';

    /**
     * The theme part of the slide master, or null where the package has none.
     */
    public static function of(ZipArchive $zip): ?string
    {
        for ($index = 0, $count = $zip->numFiles; $index < $count; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match(self::MASTER, $name) !== 1) {
                continue;
            }

            $rels = self::contents($zip, $name);

            if ($rels === null) {
                continue;
            }

            if (preg_match('~Target="\.\./(theme/theme\d+\.xml)"~', $rels, $found) === 1) {
                return 'ppt/' . $found[1];
            }
        }

        return null;
    }

    /**
     * The name the deck's theme part carries, or null where it says nothing.
     *
     * The name is whatever the writer put there: the name of a theme for a deck this library
     * wrote, and something else — `Office Theme`, most often — for a deck written by another
     * program. A caller that means to use the name has to be ready for both.
     */
    public static function nameOf(ZipArchive $zip): ?string
    {
        $part = self::of($zip);

        if ($part === null) {
            return null;
        }

        $xml = self::contents($zip, $part);

        if ($xml === null) {
            return null;
        }

        if (preg_match(self::NAME, $xml, $found) !== 1) {
            return null;
        }

        return $found[1];
    }

    /**
     * A part's contents, or null where the archive cannot produce them.
     *
     * The warning is suppressed rather than allowed to reach the caller: a part that cannot
     * be read is a part the reader has to cope with, and a warning about it is noise on top
     * of the failure the caller is about to report in its own words.
     */
    private static function contents(ZipArchive $zip, string $name): ?string
    {
        set_error_handler(static fn (): bool => true);

        try {
            $contents = $zip->getFromName($name);
        } finally {
            restore_error_handler();
        }

        return $contents === false ? null : $contents;
    }
}
