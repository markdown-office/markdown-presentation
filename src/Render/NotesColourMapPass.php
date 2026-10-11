<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnreadablePackage;
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
     * @param ZipArchive|null $archive An archive to work in rather than one this opens.
     *        It is how a test reaches the refusal below, and it has to be a real file
     *        behind the reader: `ZipArchive::$numFiles` is a read-only property of the
     *        internal class that a subclass cannot redeclare, so a stand-in archive reports
     *        no parts to walk. A damaged payload is not a substitute — whether one refuses
     *        to inflate is the builder's own business, and a reader that answers `false`
     *        because it was told to is the same refusal everywhere.
     *
     * @throws UnreadablePackage When the presentation cannot be opened for writing, or a
     *         notes slide cannot be read out of it. Both are the caller's rather than the
     *         deck's, and neither is a failure a deck author can fix by naming something
     *         else — which is why this is not an {@see \MarkdownPresentation\Exception\InvalidInput}.
     */
    public static function applyTo(string $path, ?ZipArchive $archive = null): int
    {
        $zip = $archive ?? new ZipArchive();

        if ($zip->open($path) !== true) {
            throw UnreadablePackage::cannotOpen($path);
        }

        // Read every part this pass rewrites before rewriting any of them.
        //
        // Rewriting inside the walk looks harmless and is not. `deleteName()` is
        // deferred: libzip marks the entry and does not drop it from `numFiles` until
        // the archive closes, so the `addFromString()` that follows grows `numFiles` by
        // one while `$index` grows by one, and the walk cannot end. It appears to end,
        // because a part already carrying the element is left alone — so a deck whose
        // slides all need rewriting runs forever, and one that does not grows the
        // package for as long as memory lasts.
        //
        // The rewritten entry also lands at the end of the archive, which moves every
        // index after it. What `$index` points at one turn is therefore not what it
        // pointed at the last. Where the build tolerates reading an entry marked for
        // deletion the result is a slide patched under the wrong index; where it does
        // not, `getFromIndex()` answers false and the pass reports the part unreadable
        // although it is in the package and perfectly decompressable.
        //
        // Collecting the names first costs a handful of strings and removes both.
        $pending = [];

        for ($index = 0, $count = $zip->numFiles; $index < $count; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match('~^ppt/notesSlides/notesSlide\d+\.xml$~', $name) !== 1) {
                continue;
            }

            // Read once. This called `getFromIndex()` a second time to compare against the
            // patched part, which decompresses the notes slide twice for a comparison that
            // needs no second copy — and, on an entry the archive cannot decompress, made
            // that comparison false against a string, so the entry was deleted and written
            // back as zero bytes.
            $xml = self::contents($zip, $index);

            if ($xml === false) {
                $zip->close();

                throw UnreadablePackage::cannotRead($path, $name);
            }

            $patched = self::addColourMap($xml);

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
     * A part's contents, or false where the archive cannot produce them.
     *
     * An entry whose payload does not decompress raises a warning before `ZipArchive`
     * answers false, and the refusal above is the answer a caller gets either way: the
     * warning is noise about a file this method is about to refuse. Handled rather than
     * silenced, because `@` does not keep a diagnostic from a handler that is listening —
     * and {@see \MarkdownPresentation\Render\ImageConverter::decode()} is the same
     * problem solved the same way.
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
