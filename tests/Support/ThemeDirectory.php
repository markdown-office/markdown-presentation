<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

/**
 * A theme directory built for one test.
*/
final class ThemeDirectory
{
    /**
     * @param array<string, string> $files File name to contents, relative to the directory.
    */
    public static function create(array $files, string $prefix = 'theme'): string
    {
        $directory = Scratch::directoryPath($prefix);

        foreach ($files as $name => $contents) {
            $path = $directory . '/' . $name;

            // A file can be named with a directory in front of it, so a theme beside
            // the deck can be laid out the way it would be in a project.
            if (!is_dir($path = dirname($path))) {
                mkdir($path, 0o777, true);
            }

            file_put_contents($directory . '/' . $name, $contents);
        }

        return $directory;
    }

    /**
     * The smallest directory that is a theme: the one file that is required.
    */
    public static function minimal(string $name = 'acme'): string
    {
        return self::create(['theme.yml' => sprintf("name: %s\n", $name)]);
    }
}
