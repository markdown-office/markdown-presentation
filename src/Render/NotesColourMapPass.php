<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use RuntimeException;
use ZipArchive;

/**
 * Gives each notes slide the colour map that tells a reader which colours it may use.
 *
 * PHPPresentation writes a colour map for a slide and for a slide layout but not for a
 * notes slide, and Keynote will not open a presentation whose notes slide has none: the
 * import fails with "deck.pptx can't be imported", while the same deck without a note
 * imports fine. PowerPoint and LibreOffice do not notice, so the omission only shows up
 * in a reader nobody here runs, which is the worst place for a defect to live.
 *
 * The schema makes the element optional, so the file is not invalid — it is a file that
 * some readers refuse. The fix is upstream and in flight:
 *
 *     https://github.com/PHPOffice/PHPPresentation/pull/1034
 *
 * which adds exactly this element to the Writer. Once that is released and this
 * package takes it, this pass should be deleted rather than kept as a second
 * implementation of a fix that upstream now owns.
 */
final class NotesColourMapPass
{
    /**
     * `CT_NotesSlide` orders its children `cSld`, `clrMapOvr`, `transition`, `timing`,
     * `extLst`, so the element goes directly after the common slide data closes. Anywhere
     * else produces a file that is invalid for a stricter reason than the one being fixed.
     */
    private const ELEMENT = '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>';

    /**
     * Rewrites the package in place, and reports how many notes slides it changed.
     *
     * A deck with no notes has nothing to fix and is not touched at all, so a file with
     * no speaker notes in it comes out the way the writer wrote it.
     *
     * @throws RuntimeException When the presentation cannot be opened for writing, which is
     *         the one failure this can meet: a path that is not a package, or one the
     *         process cannot write.
     */
    public static function applyTo(string $path): int
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException(sprintf('Unable to open the presentation at "%s".', $path));
        }

        $changed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match('~^ppt/notesSlides/notesSlide\d+\.xml$~', $name) !== 1) {
                continue;
            }

            $patched = self::addColourMap((string) $zip->getFromIndex($index));

            if ($patched === $zip->getFromIndex($index)) {
                continue;
            }

            $zip->deleteName($name);
            $zip->addFromString($name, $patched);
            $changed++;
        }

        $zip->close();

        return $changed;
    }

    /**
     * The part itself, with the element added — and returned unchanged if it is already
     * there or if it is not a part this pass understands, so running it twice is harmless.
     */
    public static function addColourMap(string $xml): string
    {
        if (str_contains($xml, '<p:clrMapOvr')) {
            return $xml;
        }

        foreach (['</p:cSld>', '<p:cSld/>'] as $end) {
            $at = strpos($xml, $end);

            if ($at !== false) {
                return substr($xml, 0, $at + strlen($end))
                    . self::ELEMENT
                    . substr($xml, $at + strlen($end));
            }
        }

        return $xml;
    }
}