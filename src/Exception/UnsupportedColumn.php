<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A `::column` the slide's layout has no column to move a block into.
 *
 * The marker is well formed and the slide is what cannot offer: `::column` is how a block is
 * moved into the next column, and a role with one column has no next one. A third `::column`
 * on a two-column slide is the same failure and gets the same message, because a layout with
 * three columns is the thing both of them are asking for and neither of them has. So is a
 * marker with nothing written after it on a role with one column, which is measured by what
 * the deck wrote rather than by the block it left behind.
 *
 * Not {@see UnknownMarker}: that family is a marker that is not one, and `::column` is one.
 * {@see UnsupportedLink} and {@see UnsupportedImageFormat} are the same shape as this — the
 * deck asked for something well spelled that the file cannot carry, and a consumer catching
 * it wants to know which of its limitations it hit.
 */
final class UnsupportedColumn extends InvalidInput
{
    /**
     * @param string $role    The layout role the slide names, as it is written in the deck.
     * @param int    $columns The columns that role has, which is what the marker was measured
     *                        against — a template may give a role more than the library does.
     */
    public static function beyond(int $slide, string $role, int $columns): self
    {
        return new self(sprintf(
            'Slide %d has a "::column", and the "%s" layout has %d column%s. "::column" moves the '
            . 'blocks after it into the next column, so a slide that uses one needs a layout with '
            . 'more columns than this.',
            $slide,
            $role,
            $columns,
            $columns === 1 ? '' : 's',
        ));
    }
}
