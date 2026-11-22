<?php

declare(strict_types=1);

namespace MarkdownPresentation\Filesystem;

/**
 * The file system, asked about a name it may not be able to answer for.
 *
 * Every predicate this library asks about a path with is asked here, and the answer is
 * never an exception: a name the file system cannot answer about is answered "no", and the
 * caller carries on. That is the promise this library already makes for a name that walks
 * out of its base, for a name that is a URL and for a name holding a null byte — a null
 * byte is the same kind of name.
 */
final class FileSystem
{
    /**
     * Whether a path names a file.
     */
    public static function isFile(string $path): bool
    {
        return self::answerable($path) && is_file($path);
    }

    /**
     * Whether a path names a directory.
     */
    public static function isDirectory(string $path): bool
    {
        return self::answerable($path) && is_dir($path);
    }

    /**
     * Whether a path names anything at all.
     */
    public static function exists(string $path): bool
    {
        return self::answerable($path) && file_exists($path);
    }

    /**
     * Whether a path names something that can be read.
     */
    public static function isReadable(string $path): bool
    {
        return self::answerable($path) && is_readable($path);
    }

    /**
     * The size of the file a path names, or null where there is no such file.
     */
    public static function size(string $path): ?int
    {
        if (!self::answerable($path) || !is_file($path)) {
            return null;
        }

        $size = filesize($path);

        return $size === false ? null : $size;
    }

    /**
     * Whether the file system can answer about a path at all.
     *
     * PHP 8.6 raises a `ValueError` from `is_file()` and its neighbours for a path
     * holding a null byte, where earlier versions answered false. A deck can write one, so
     * the name is answered here rather than raised: an `Error` is not an `Exception` and
     * would escape every `catch` this library documents.
     */
    private static function answerable(string $path): bool
    {
        return !str_contains($path, "\0");
    }
}
