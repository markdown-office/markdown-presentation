<?php

declare(strict_types=1);

namespace MarkdownPresentation\Files;

use MarkdownPresentation\Theme\ThemeLoader;

/**
 * The default finder: the files are in directories on this machine.
 *
 * It holds the bases — the directories a name is looked for in — and nothing else.
 * What may be read is not its business: the scope belongs to the render rather than to
 * the storage, so the library holds every path this finder answers to its own roots
 * before it reads it.
 *
 * The bases are given per role and are tried in the order they are given, so the
 * calling program decides where a deck's files come from: the themes beside the deck
 * before the ones that ship with this package, a shared library of themes before
 * either.
 */
final class DiskFinder implements FileFinder
{
    /**
     * @param list<string> $theme    The directories a theme's files are looked for in, in
     *                               the order they are tried.
     * @param list<string> $picture  The directories a picture is looked for in, in the
     *                               order they are tried.
     * @param list<string> $document The directories a deck's own file is looked for in,
     *                               in the order they are tried.
     */
    public function __construct(
        private readonly array $theme = [],
        private readonly array $picture = [],
        private readonly array $document = [],
    ) {
    }

    /**
     * The contents of a file a name identifies, or null when there is none.
     */
    public function read(string $name, ?string $role = null): ?string
    {
        $path = $this->resolve($name, $role);

        if ($path === null) {
            return null;
        }

        // `@file_get_contents()` rather than a check first: a file that cannot be read is
        // a file the finder has no contents for, and the warning it would print is not
        // a failure anybody can act on.
        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * The path of a file a name identifies, when this finder holds it as a file.
     *
     * The path as it was resolved rather than as the machine spells it: the library
     * resolves what it is given and holds it to its own roots, and a path that arrives
     * already resolved is a path it cannot check.
     */
    public function path(string $name, ?string $role = null): ?string
    {
        return $this->resolve($name, $role);
    }

    /**
     * The themes that resolve, sorted.
     *
     * A directory that holds a `theme.yml` is listed and one without it is not. A base
     * that is not there holds nothing: a directory the caller pointed at may not exist
     * yet, and a base with nothing in it is not a failure here.
     *
     * @return list<string>
     */
    public function themes(): array
    {
        $names = [];

        foreach ($this->theme as $base) {
            if (!is_dir($base)) {
                continue;
            }

            foreach (scandir($base) ?: [] as $entry) {
                if (str_starts_with($entry, '.')) {
                    continue;
                }

                if (is_file(rtrim($base, '/') . '/' . $entry . '/' . ThemeLoader::THEME_FILE)) {
                    $names[$entry] = true;
                }
            }
        }

        $found = array_keys($names);

        sort($found);

        return $found;
    }

    /**
     * The names of the files of a theme, sorted.
     *
     * The files of the directory the theme's name resolves to, so the listing and the
     * file the library asks for are about the same theme: a source that cannot serve a
     * master is told apart from a theme that has none.
     *
     * A name that begins with a dot is not one of the theme's files: it is a thing the
     * file system put there rather than something the library reads.
     *
     * @return list<string>
     */
    public function files(string $theme): array
    {
        $directory = $this->themeDirectory($theme);

        if ($directory === null) {
            return [];
        }

        $names = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            if (is_file($directory . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * The file a name identifies, or null when there is none.
     *
     * The first base that has the file decides.
     */
    private function resolve(string $name, ?string $role): ?string
    {
        if (str_starts_with($name, '/')) {
            // A name that is already a path is taken as it stands: resolving it against
            // a base would name a file nobody meant.
            return is_file($name) ? $name : null;
        }

        if ($role === 'theme') {
            return $this->resolveTheme($name);
        }

        return $this->inWorlds($name, $role);
    }

    /**
     * The file one of the worlds holds, or null when none of them does.
     */
    private function inWorlds(string $name, ?string $role): ?string
    {
        foreach ($this->worlds($role) as $base) {
            $path = rtrim($base, '/') . '/' . $name;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The file of a theme a name identifies, or null when there is none.
     *
     * A theme's file is named inside its theme, so the name is `<theme>/<file>` and
     * the theme's directory is found first. The file is then looked for inside that
     * directory and nowhere else: a directory that exists but holds no manifest
     * shadows a later one, which is what a theme resolved from a directory has always
     * done.
     */
    private function resolveTheme(string $name): ?string
    {
        $slash = strpos($name, '/');

        if ($slash === false) {
            // A name without a theme in it names no file of a theme.
            return null;
        }

        $directory = $this->themeDirectory(substr($name, 0, $slash));

        if ($directory === null) {
            return null;
        }

        $path = $directory . '/' . substr($name, $slash + 1);

        return is_file($path) ? $path : null;
    }

    /**
     * The directory a theme is in, or null when there is no such theme.
     *
     * The first base that has a directory of that name, whether or not it holds a
     * manifest: the manifest decides whether the directory is a theme, and the
     * directory decides which theme a name means.
     */
    private function themeDirectory(string $theme): ?string
    {
        foreach ($this->theme as $base) {
            $directory = rtrim($base, '/') . '/' . $theme;

            if (is_dir($directory)) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * The directories a name is looked for in, for a role.
     *
     * A role the finder was given no bases for looks in none of them: the role says
     * which world the name is resolved in, and a world it does not know about is not
     * one it can answer for. A name with no role is looked for in every world, in the
     * order the roles are declared, and is resolved as a plain name — the role is
     * what says how a name is resolved.
     *
     * The theme role is not among them: a theme is resolved by its directory rather
     * than by a file in a base, which {@see self::resolveTheme()} is where happens.
     *
     * @return list<string>
     */
    private function worlds(?string $role): array
    {
        return match ($role) {
            null => [...$this->theme, ...$this->picture, ...$this->document],
            'picture' => $this->picture,
            'document' => $this->document,
            default => [],
        };
    }
}
