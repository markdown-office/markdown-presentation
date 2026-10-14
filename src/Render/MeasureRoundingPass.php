<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnreadablePackage;
use ZipArchive;

/**
 * Rounds the paragraph spacing measures to whole numbers, because a fractional one makes
 * PowerPoint refuse the deck.
 *
 * {@see \MarkdownPresentation\Render\SlideGeometry} states its gaps in points as floats — a
 * heading's trailing gap is `0.35` of the heading's own size, and `0.35 * 22` is not `7.7` in
 * binary floating point but `7.699999999999999`. PHPPresentation then writes a point measure by
 * multiplying by one hundred without rounding, which turns that into `769.9999999999999`:
 *
 *     vendor/phpoffice/phppresentation/src/PhpPresentation/Writer/PowerPoint2007/AbstractSlide.php
 *     $objWriter->writeAttribute('val', $paragraph->getSpacingAfter() * 100);
 *
 * `ST_TextSpacingPoint` is an integer type, so the attribute is invalid, and PowerPoint does not
 * drop the paragraph that carries it — it opens the presentation as `[Repaired]` and reports
 * that it could not read some of the content. LibreOffice reads the value and does not care, which
 * is why this is invisible to every render on this machine and to every test that looks at
 * geometry.
 *
 * Rounding at the writer is the only place that works: a float times one hundred is integral only
 * for the multiples of a quarter point, so a theme that states its gaps in anything finer cannot be
 * carried correctly *into* the writer, and choosing theme values that happen to be exact would
 * trade a repair prompt for a silent constraint on the spacing model.
 */
final class MeasureRoundingPass
{
    /**
     * The parts whose paragraphs carry a spacing measure. A layout and a master can hold a text
     * style with a line spacing in it, and a notes slide holds a text body exactly like a slide
     * does, so all four are walked rather than only the two a reader looks at.
     */
    private const PARTS = '~^ppt/(?:slides|slideLayouts|slideMasters|notesSlides)/[^/]+\.xml$~';

    /**
     * One spacing measure: the two elements are siblings in the vocabulary, both are declared as
     * whole numbers, and the writer produces both by multiplying a float.
     */
    private const MEASURE = '~(<a:spc(?:Pts|Pct) val=")([+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)(")~';

    /**
     * Rewrites the package in place, and reports how many parts it changed.
     *
     * A deck whose measures are all whole numbers is not touched at all, and running this twice
     * changes nothing the second time, because a whole number is left as it stands rather than
     * rewritten to its own value.
     *
     * @param ZipArchive|null $archive An archive to work in rather than one this opens.
     *        {@see NotesColourMapPass::applyTo()} is where that is explained.
     *
     * @throws UnreadablePackage When the presentation cannot be opened for writing, or a part
     *         cannot be read out of it.
     */
    public static function applyTo(string $path, ?ZipArchive $archive = null): int
    {
        $zip = $archive ?? new ZipArchive();

        if ($zip->open($path) !== true) {
            throw UnreadablePackage::cannotOpen($path);
        }

        // Read every part this pass rewrites before rewriting any of them, for the reason
        // NotesColourMapPass sets out at length: `deleteName()` is deferred, so rewriting inside
        // the walk walks an archive that keeps changing underneath it.
        $pending = [];

        for ($index = 0, $count = $zip->numFiles; $index < $count; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match(self::PARTS, $name) !== 1) {
                continue;
            }

            $xml = self::contents($zip, $index);

            if ($xml === false) {
                $zip->close();

                throw UnreadablePackage::cannotRead($path, $name);
            }

            $patched = self::round($xml);

            if ($patched !== $xml) {
                $pending[$name] = $patched;
            }
        }

        foreach ($pending as $name => $patched) {
            $zip->deleteName((string) $name);
            $zip->addFromString((string) $name, $patched);
        }

        $zip->close();

        return count($pending);
    }

    /**
     * A part with every fractional measure in it rounded to the nearest whole number.
     *
     * Public so that the rounding can be stated on its own; {@see self::applyTo()} is the only
     * caller outside tests.
     */
    public static function round(string $xml): string
    {
        $rounded = preg_replace_callback(
            self::MEASURE,
            static fn (array $m): string => $m[1] . self::whole($m[2]) . $m[3],
            $xml,
        );

        return $rounded ?? $xml;
    }

    /**
     * The measure as a whole number, or unchanged where it already is one.
     *
     * A whole number is left as the characters the writer wrote rather than reprinted from the
     * float it came from: `769.9999999999999` and `770` mean the same gap, and rewriting a value
     * that is already correct would make this pass report work it did not do.
     */
    private static function whole(string $measure): string
    {
        if (preg_match('~^[+-]?\d+$~', $measure) === 1) {
            return $measure;
        }

        return (string) (int) round((float) $measure);
    }

    /**
     * A part's contents, or false where the archive cannot produce them.
     *
     * {@see NotesColourMapPass::contents()} is where the suppressed warning is explained.
     */
    private static function contents(ZipArchive $zip, int $index): string|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $zip->getFromIndex($index);
        } finally {
            restore_error_handler();
        }
    }
}
