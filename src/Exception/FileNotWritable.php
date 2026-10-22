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

    public static function cannotCreateDirectory(string $path): self
    {
        return new self(sprintf('Unable to create the directory "%s".', $path));
    }

    public static function cannotCreateMediaDirectory(string $path): self
    {
        return new self(sprintf('Unable to create the media directory "%s".', $path));
    }
}
