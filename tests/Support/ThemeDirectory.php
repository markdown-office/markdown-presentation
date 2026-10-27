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
     * A file of a theme replaced by a link to another path.
     *
     * The link is made where the file was, so a theme laid out with links is a theme
     * like any other: what matters is where the link points, not that it is one.
     *
     * @param string $file   The file to replace.
     * @param string $target The path the link points at.
     */
    public static function link(string $file, string $target): string
    {
        if (file_exists($file) || is_link($file)) {
            unlink($file);
        }

        symlink($target, $file);

        return $file;
    }

    /**
     * The smallest directory that is a theme: the one file that is required.
     */
    public static function minimal(string $name = 'acme'): string
    {
        return self::create(['theme.yml' => sprintf("name: %s\n", $name)]);
    }

    /**
     * A working directory holding `themes/acme` and whatever else is asked for beside it.
     *
     * A test about resolving a name needs one theme among others, or there is nothing it
     * could have resolved wrongly, and a test about what a failure lists needs the others
     * spelled out. Anything passed here is added to that one rather than replacing it.
     *
     * @param array<string, string> $files More files, keyed by their path under the
     *                                     working directory.
     */
    public static function withAcme(array $files = [], string $prefix = 'working'): string
    {
        return self::create($files + ['themes/acme/theme.yml' => self::ACME], $prefix);
    }

    /**
     * The manifest a theme directory has to carry to be a theme at all, under a name the
     * tests assert on rather than one the directory happens to be called.
     */
    public const ACME = "name: Acme\n";

    /**
     * A theme directory that is nothing but the manifest {@see self::ACME} writes, plus
     * whatever else is asked to sit beside it.
     *
     * Separate from {@see self::minimal()} because the tests here are about what a theme
     * directory may carry and must be able to say "nothing else", and spelling the manifest
     * out at every call site is how a test ends up asserting against a theme that is not the
     * one it means to be about.
     *
     * @param array<string, string> $beside Files beside `theme.yml`, keyed by name.
     */
    public static function acme(array $beside = [], string $prefix = 'theme'): string
    {
        return self::create(['theme.yml' => self::ACME] + $beside, $prefix);
    }
}
