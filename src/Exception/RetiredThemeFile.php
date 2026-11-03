<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A file beside a theme that this format does not have.
 *
 * A theme was a directory of up to five files, and three of them have become sections of
 * the one file that is left. They are refused rather than ignored, because a file that is
 * quietly not read is a theme that looks like the one its author wrote and is not: the
 * author renames a layout in the file beside the theme, the deck is drawn with the old
 * mapping, and nothing in the output says so.
 *
 * The third of them is the Word half's, and this half loaded it for years without ever
 * using it — its slots name Word styles, and a deck of slides has no use for them. Its
 * message says which half the file belongs to, so that the reader is not left looking for
 * a section this half does not have.
 */
final class RetiredThemeFile extends InvalidInput
{
    /**
     * A file whose content has become a section of the theme's own file.
     *
     * The refusal is about the file being there rather than about what is in it, so the
     * message names both steps: the content goes into the section it became, and the file
     * goes with it.
     *
     * @param string $section The section the content goes into, without its colon.
     */
    public static function moved(string $theme, string $file, string $section): self
    {
        return new self(sprintf(
            'The theme "%s" has a "%s" beside it. A theme is one file: move its content into the "%s:" section of its "theme.yml", and delete the file.',
            $theme,
            $file,
            $section,
        ));
    }

    /**
     * A file that belongs to the other half of the suite.
     */
    public static function otherHalf(string $theme, string $file): self
    {
        return new self(sprintf(
            'The theme "%s" has a "%s" beside it. That file is the Word half\'s, and this half does not read it: its '
            . 'slots name Word styles, which a deck of slides has no use for. Delete it, and the theme is read again.',
            $theme,
            $file,
        ));
    }
}
