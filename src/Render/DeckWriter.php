<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Theme\LayoutRole;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;

/**
 * Writes a finished presentation out, and runs the two passes over the written package.
 *
 * Both passes are things the file needs that PHPPresentation does not write:
 * {@see PlaceholderPass} gives every layout part real placeholders, and {@see NotesColourMapPass}
 * gives every notes slide the colour map Keynote refuses to open a deck without. Neither can be
 * done through the writer's own API, so both are passes over the package afterwards — which is
 * what makes the file's last step one thing rather than a detail of every shape.
 *
 * @see PlaceholderPass
 * @see NotesColourMapPass
 */
final class DeckWriter
{
    /**
     * PHPPresentation answers a path whose directory is not there by emitting a warning and a
     * low-level "could not close zip file", after a broken archive has been left behind.
     * Saying so here is what makes the failure one a caller can act on.
     *
     * @param list<LayoutRole> $roles The roles the deck used, in the order their layouts were built.
     *
     * @throws Exception
     */
    public static function save(
        PhpPresentation $presentation,
        string $path,
        SlideGeometry $geometry,
        array $roles,
    ): string {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            throw new Exception(sprintf('There is no directory at "%s" to write "%s" into.', $directory, basename($path)));
        }

        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

        NotesColourMapPass::applyTo($path);
        PlaceholderPass::applyTo($path, $geometry, $roles);

        return $path;
    }
}
