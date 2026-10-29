<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\Exception;

/**
 * The staging half of writing a deck: a file of the library's own, read back and given back.
 *
 * A deck that comes back as bytes has to be written to a file first, because a `.pptx` is a
 * package and PHP's `ZipArchive` cannot write one to memory — `open('php://temp')` answers
 * 9 (`ER_NOENT`), and `open('php://temp', CREATE | OVERWRITE)` fails at `close()` with
 * "Failure to create temporary file". PHPPresentation's writer takes a path as well.
 *
 * The file is made with `tempnam()`, so its name is one nobody guessed and its mode is one
 * nobody else on the machine can read.
 *
 * The file is the library's, and it goes when the read is over: nothing of the library's
 * is left on the disk, whether the deck was written or the write failed.
 */
final class Staging
{
    /** The prefix of a staged deck, so a file in flight is recognisable as one. */
    private const PREFIX = 'mdpres_';

    /**
     * The deck a writer writes, as the bytes of the file it wrote.
     *
     * @param callable(string):void $write Writes the deck to the path it is given.
     *
     * @throws Exception When the deck cannot be read back.
     */
    public static function of(callable $write): string
    {
        // A file that could not be made leaves a path that is not there, and the writer
        // that opens the deck refuses it and says so.
        $path = @tempnam(sys_get_temp_dir(), self::PREFIX) ?: sys_get_temp_dir() . '/' . self::PREFIX . bin2hex(random_bytes(8));

        try {
            $write($path);

            // The writer unlinks the file `tempnam()` made and writes its own at the
            // process umask: a deck in flight through a shared temporary directory is not
            // readable by every account on the machine, and this is the only moment its
            // permissions can be narrowed.
            if (is_file($path)) {
                @chmod($path, 0o600);
            }

            // `is_readable()` first, so that a file that cannot be read is reported as
            // that rather than as a warning the test runner turns into a failure of its
            // own. A deck that was not written is a failure and not an empty deck.
            $contents = is_readable($path) ? @file_get_contents($path) : false;

            if ($contents === false) {
                throw new Exception('Unable to read the generated deck.');
            }

            return $contents;
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
