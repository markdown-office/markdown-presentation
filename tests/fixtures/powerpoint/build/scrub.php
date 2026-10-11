<?php

declare(strict_types=1);

/**
 * Takes the name PowerPoint wrote into a fixture and the file it is in, back out.
 *
 *   php tests/fixtures/powerpoint/build/scrub.php <deck.pptx> [...]
 *
 * A deck authored on a machine carries the account that authored it. `cp:lastModifiedBy` in
 * `docProps/core.xml` is the local user's name on both decks this repository carries, and
 * `dc:creator` is whoever the deck says made it; neither is anything to commit, and neither is
 * anything the tests read. `Company` and `Manager` in `docProps/app.xml` are the organisation,
 * and were empty here — they are emptied anyway rather than assumed, because a deck built on
 * somebody's work machine is exactly the one that would carry one.
 *
 * Nothing else is touched, and in particular the timestamps are left alone: they are how a
 * reader knows when the fixture was made, and the rule this repository already follows is to
 * compare parts rather than bytes, so an unstable `dcterms:modified` costs nothing.
 *
 * The archive is written back part by part and with every entry's timestamp reset, because a
 * `.pptx` is a zip and PowerPoint writes 1980 into every entry: a file with mixed entry dates
 * is a file that diffs as though every part had changed when it has not.
 *
 * It fails rather than warns. A part that cannot be rewritten, or a `docProps` that still says
 * who made the deck, is a fixture that must not be committed.
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require __DIR__ . '/FixtureFailed.php';

/**
 * The elements that name a person or an organisation, by the part they live in.
 *
 * Both are emptied rather than deleted: PowerPoint writes the empty element for a deck it has
 * no author for, and an absent one is a document PowerPoint fills in again on the next save.
 *
 * @var array<string, list<string>>
 */
const IDENTITY = [
    'docProps/core.xml' => ['dc:creator', 'cp:lastModifiedBy'],
    'docProps/app.xml' => ['Company', 'Manager'],
];

$paths = array_slice($argv, 1);

if ($paths === []) {
    fwrite(STDERR, "Nothing to scrub. Pass one or more .pptx paths.\n");

    exit(1);
}

foreach ($paths as $path) {
    scrub($path);
}

echo 'scrubbed ', count($paths), " deck(s)\n";

/**
 * @throws PowerPointFixtureFailed When a deck cannot be read, rewritten, or shown to be clean.
 */
function scrub(string $path): void
{
    $source = new ZipArchive();

    if ($source->open($path) !== true) {
        throw new PowerPointFixtureFailed(sprintf('Unable to open "%s".', $path));
    }

    $rewritten = tempnam(sys_get_temp_dir(), 'scrub') . '.pptx';
    $target = new ZipArchive();

    if ($target->open($rewritten, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $source->close();

        throw new PowerPointFixtureFailed(sprintf('Unable to write "%s".', $rewritten));
    }

    for ($index = 0; $index < $source->numFiles; $index++) {
        $part = (string) $source->getNameIndex($index);
        $contents = (string) $source->getFromIndex($index);

        if (isset(IDENTITY[$part])) {
            $contents = emptyThose($contents, IDENTITY[$part], $part);
        }

        // The timestamp rather than the entry's own: `ZipArchive::addFromString` has no other
        // way to set one, and 1980 is the epoch of the format.
        $target->addFromString($part, $contents);
        $target->setCompressionName($part, ZipArchive::CM_DEFLATE);
    }

    $target->close();
    $source->close();

    if (!rename($rewritten, $path)) {
        throw new PowerPointFixtureFailed(sprintf('Unable to put the scrubbed deck back at "%s".', $path));
    }

    assertClean($path);
}

/**
 * The named elements, emptied.
 *
 * @param list<string> $elements
 *
 * @throws PowerPointFixtureFailed When an element is there and could not be emptied.
 */
function emptyThose(string $xml, array $elements, string $part): string
{
    foreach ($elements as $element) {
        $pattern = '#<' . preg_quote($element, '#') . '(\s[^>]*)?>.*?</' . preg_quote($element, '#') . '>#s';

        if (preg_match($pattern, $xml) !== 1) {
            continue;
        }

        $replaced = preg_replace($pattern, '<' . $element . '$1></' . $element . '>', $xml);

        if (!is_string($replaced)) {
            throw new PowerPointFixtureFailed(sprintf('Unable to empty <%s> in %s.', $element, $part));
        }

        $xml = $replaced;
    }

    return $xml;
}

/**
 * The check that makes the write above worth doing: read every part back and refuse the file
 * if anything still says who made it or where it was made.
 *
 * @throws PowerPointFixtureFailed
 */
function assertClean(string $path): void
{
    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        throw new PowerPointFixtureFailed(sprintf('Unable to reopen "%s".', $path));
    }

    $found = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $part = (string) $zip->getNameIndex($index);
        $contents = (string) $zip->getFromIndex($index);

        foreach (IDENTITY[$part] ?? [] as $element) {
            if (preg_match('#<' . preg_quote($element, '#') . '(\s[^>]*)?>([^<]+)</#', $contents, $match) === 1) {
                $found[] = $part . ' <' . $element . '> "' . $match[2] . '"';
            }
        }
    }

    $zip->close();

    if ($found !== []) {
        throw new PowerPointFixtureFailed(sprintf(
            '"%s" still names who made it: %s.',
            $path,
            implode('; ', $found),
        ));
    }
}