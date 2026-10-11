<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

use RuntimeException;

/**
 * A place for the files a test creates, and their removal.
 *
 * Everything lands in `tmp/pest` inside the project rather than in the system temp
 * directory, so a run writes nothing outside the repository. The subdirectory is
 * what keeps two runs apart.
*/
final class Scratch
{
    private const SUBDIRECTORY = 'pest';

    public static function directory(): string
    {
        $directory = dirname(__DIR__, 2) . '/tmp/' . self::SUBDIRECTORY;

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the scratch directory "%s".', $directory));
        }

        return $directory;
    }

    /**
     * A directory no other test is using.
    */
    public static function directoryPath(string $prefix = 'dir'): string
    {
        $path = self::directory() . '/' . $prefix . '-' . bin2hex(random_bytes(6));

        mkdir($path, 0o777, true);

        return $path;
    }

    /**
     * A file in the scratch directory with the given contents, and its path.
    */
    public static function file(string $name, string $contents): string
    {
        $path = self::directory() . '/' . $name . '-' . bin2hex(random_bytes(6));

        file_put_contents($path, $contents);

        return $path;
    }

    public static function cleanUp(): void
    {
        $entries = scandir(self::directory());

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = self::directory() . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
    }

    private static function removeTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
