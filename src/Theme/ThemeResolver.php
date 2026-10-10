<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\ThemeNotFound;

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
*/
final class ThemeResolver
{
    private readonly string $builtinDirectory;

    public function __construct(
        private readonly string $workingDirectory = '.',
        ?string $builtinDirectory = null,
        private readonly ThemeLoader $loader = new ThemeLoader(),
    ) {
        // The themes that ship beside `src/`, so a package installed anywhere
        // resolves its own themes without being told where they are.
        $this->builtinDirectory = $builtinDirectory ?? dirname(__DIR__, 2) . '/themes';
    }

    /**
     * @throws ThemeNotFound When no step of the order above resolves.
    */
    public function resolve(string $name): LoadedTheme
    {
        if (str_contains($name, '/')) {
            $directory = str_starts_with($name, '/') ? $name : $this->workingDirectory . '/' . $name;

            return is_dir($directory)
                ? $this->loader->load($directory)
                : throw ThemeNotFound::noDirectory($directory);
        }

        foreach ([$this->workingDirectory . '/themes', $this->builtinDirectory] as $root) {
            if (is_dir($root . '/' . $name)) {
                return $this->loader->load($root . '/' . $name);
            }
        }

        throw ThemeNotFound::named($name, $this->available());
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

        foreach ([$this->workingDirectory . '/themes', $this->builtinDirectory] as $root) {
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