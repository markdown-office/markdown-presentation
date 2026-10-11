<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Filesystem\PathScope;

/**
 * Turns the `theme:` a deck names into a theme, in the order a theme is looked for:
 *
 * 1. an explicit path — anything with a `/` in it, so `theme: "./themes/acme"` and
 *    `theme: "themes/acme"` both mean that directory;
 * 2. `./themes/<name>` beside the deck;
 * 3. a theme that ships with the library;
 * 4. nothing, which is a failure listing every name that did resolve.
 *
 * Step 4 is the point. Falling back to the default theme would produce a deck in
 * the wrong colours with nothing in the output to say so; the alternative is an
 * error that names the themes that exist, which is a sentence the author of the deck
 * can act on.
 *
 * Every step resolves the path with `realpath()` and holds it to the directories this
 * resolver may read — the working directory, the built-in themes, and whatever else the
 * program rendering allowed. A `theme:` is a path a deck writes, and a deck naming
 * `../../secrets/theme.yml` used to load it: whatever `author:` that file carried was copied
 * into `docProps/core.xml` of the deck, which is a way to get the contents of a file out
 * through a download. The rules are `Filesystem\PathScope`'s and are the same ones an image
 * path has to pass, because both are paths a deck wrote.
 */
final class ThemeResolver
{
    /** Where a theme sits under the working directory, and beside the library's own source. */
    private const THEMES = '/themes';

    private readonly string $builtinDirectory;

    private readonly PathScope $files;

    /**
     * @param list<string> $roots Further directories a deck may name a theme in, for a
     *                           shared library of themes beside the project. As with an
     *                           image's roots this is the calling program's decision, made
     *                           before a deck is read: a deck that could widen it would be
     *                           the arbitrary path read this argument exists to stop.
     */
    public function __construct(
        private readonly string $workingDirectory = '.',
        ?string $builtinDirectory = null,
        private readonly ThemeLoader $loader = new ThemeLoader(),
        array $roots = [],
    ) {
        // The themes that ship beside `src/`, so a package installed anywhere
        // resolves its own themes without being told where they are.
        $this->builtinDirectory = $builtinDirectory ?? dirname(__DIR__, 2) . self::THEMES;
        $this->files = new PathScope([$this->workingDirectory, $this->builtinDirectory, ...$roots]);
    }

    /**
     * @throws ThemeNotFound When no step of the order above resolves.
     */
    public function resolve(string $name): LoadedTheme
    {
        if (PathScope::isUrl($name)) {
            throw ThemeNotFound::aUrl($name);
        }

        if (str_contains($name, '/')) {
            // Where a name is written *from* says nothing about whether it is allowed: what is
            // allowed is where it lands, which is the same question an image path asks.
            return $this->loader->load($this->directory(
                $name,
                str_starts_with($name, '/') ? $name : $this->workingDirectory . '/' . $name,
            ));
        }

        foreach ($this->roots() as $root) {
            if (is_dir($directory = $root . '/' . $name)) {
                return $this->loader->load($this->directory($name, $directory));
            }
        }

        throw ThemeNotFound::named($name, $this->available());
    }

    /**
     * The directory a step of the order above found, resolved and held to the roots.
     *
     * Two messages rather than one, because "there is nothing there" and "it is there and
     * this render may not read it" are different problems with different fixes: the first is
     * a typo in a deck, the second is a deck reaching for something.
     *
     * @param string $name  What the deck wrote.
     * @param string $found The path it resolved to before `realpath()`.
     *
     * @throws ThemeNotFound
     */
    private function directory(string $name, string $found): string
    {
        if (!is_dir($found)) {
            throw ThemeNotFound::noDirectory($found);
        }

        $real = PathScope::canonical($found);

        if ($real === null || !$this->files->holds($real)) {
            throw ThemeNotFound::outside($name, $real ?? $found, $this->files->roots());
        }

        return $real;
    }

    /**
     * The directories a name is looked for under, the working directory first.
     *
     * One list because a name has to resolve the same way whether it is being looked up or
     * enumerated: a name the resolver would accept and not list is a name a failure message
     * leaves the reader to guess at.
     *
     * @return list<string>
     */
    private function roots(): array
    {
        return [$this->workingDirectory . self::THEMES, $this->builtinDirectory];
    }

    /**
     * Every theme name that resolves, local and built-in together, sorted: a name
     * resolves to one of them and the reader of the list cannot tell which, so
     * listing one kind ahead of the other would say something untrue.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = [];

        foreach ($this->roots() as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach (scandir($root) ?: [] as $entry) {
                if (str_starts_with($entry, '.')) {
                    continue;
                }

                if (is_file($root . '/' . $entry . '/' . ThemeLoader::THEME_FILE)) {
                    $names[$entry] = true;
                }
            }
        }

        $found = array_keys($names);

        sort($found);

        return $found;
    }
}
