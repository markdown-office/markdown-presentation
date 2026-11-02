<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A file this process cannot write.
 *
 * The disk filled, the directory does not exist and cannot be made, the path
 * names a directory rather than a file. None of it is the deck's fault, and
 * the message says which path it was.
 */
final class FileNotWritable extends Exception
{
    public static function cannotWrite(string $path): self
    {
        return new self(sprintf('Unable to write "%s".', $path));
    }

    /**
     * A path that names something a document cannot be written to.
     *
     * A device, a directory or a named pipe is not a file, and the archive writer opens
     * whatever it is given — which is how writing a deck to `/dev/null` produced a message
     * about an empty file and a PHP deprecation about opening one, rather than a sentence
     * that says what was wrong.
     */
    public static function notAFile(string $path): self
    {
        return new self(sprintf(
            'The path "%s" is not a regular file, so nothing can be written to it. A deck is written to a file.',
            $path,
        ));
    }

    public static function cannotCreateDirectory(string $path): self
    {
        return new self(sprintf('Unable to create the directory "%s".', $path));
    }

    public static function cannotCreateMediaDirectory(string $path): self
    {
        return new self(sprintf('Unable to create the media directory "%s".', $path));
    }
}
