<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Theme\LayoutRole;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;

/**
 * Writes a finished presentation out, and runs the five passes over the written package.
 *
 * All five are things the file needs that PHPPresentation does not write:
 * {@see PlaceholderPass} gives every layout part real placeholders, {@see NotesColourMapPass}
 * gives every notes slide the colour map Keynote refuses to open a deck without,
 * {@see NotesChainPass} gives every notes slide the notes master and the back-link it hangs from,
 * {@see MeasureRoundingPass} gives every spacing measure the whole number the schema asks for and
 * PowerPoint insists on, and {@see TimingPass} gives a slide the click chain that builds it up.
 * None can be done through the writer's own API, so all five are passes over the package
 * afterwards — which is what makes the file's last step one thing rather than a detail of every
 * shape.
 *
 * @see PlaceholderPass
 * @see NotesColourMapPass
 * @see NotesChainPass
 * @see MeasureRoundingPass
 * @see TimingPass
 */
final class DeckWriter
{
    /**
     * PHPPresentation answers a path whose directory is not there by emitting a warning and a
     * low-level "could not close zip file", after a broken archive has been left behind.
     * Saying so here is what makes the failure one a caller can act on.
     *
     * @param list<LayoutRole>            $roles  The roles the deck used, in the order their layouts were built.
     * @param array<int, list<list<int>>> $builds Slide number to the steps that build it up,
     *        each step to the ids of the shapes it shows. A slide no entry names builds up not
     *        at all, which is how a deck that steps only some of its slides comes out with a
     *        click chain on those and none on the rest. See {@see TimingPass::applyTo()}.
     *
     * @throws Exception
     */
    public static function save(
        PhpPresentation $presentation,
        string $path,
        SlideGeometry $geometry,
        array $roles,
        array $builds = [],
    ): string {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            throw new Exception(sprintf('There is no directory at "%s" to write "%s" into.', $directory, basename($path)));
        }

        IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

        NotesColourMapPass::applyTo($path);
        NotesChainPass::applyTo($path);
        MeasureRoundingPass::applyTo($path);
        PlaceholderPass::applyTo($path, $geometry, $roles);
        TimingPass::applyTo($path, $builds);

        return $path;
    }
}
