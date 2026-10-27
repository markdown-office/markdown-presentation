<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Filesystem\PathScope;

/**
 * Turns the `theme:` a deck names into a theme, in the order one is looked for:
 *
 * 1. an explicit path — anything with a `/` in it, written from the deck's own
 *    directory — or the working directory, where the deck has none — unless it names
 *    an absolute one;
 * 2. `./themes/<name>` beside the deck;
 * 3. `./themes/<name>` beside the working directory;
 * 4. a theme in a root the calling program allowed;
 * 5. a theme that ships with this library;
 * 6. nothing, which is a failure naming every theme that did resolve.
 *
 * Step 6 is the point. Falling back to the built-in default would produce a deck in
 * the wrong colours with nothing in the output to say so; the alternative is an error
 * that names the themes that exist, which is a sentence the author of the deck can act
 * on.
 *
 * The deck's own directory comes before the working directory's because a deck travels:
 * the theme beside it travels with it, and the working directory is where the program
 * happens to be. A run that resolved against the working directory found the theme of
 * the machine — and found nothing at all unless it happened to start in the deck's own
 * directory. A relative path is written from the deck's directory for the same reason:
 * a path a deck writes is written from beside the deck, which is where a relative image
 * path resolves from too.
 *
 * A path is looked for on its own: one that does not resolve is a failure rather than a
 * name that is looked for elsewhere, so a deck writing `theme: ./nowhere` is told about
 * the path and not about the themes that do exist. What the path names decides only what
 * is read from it — the `theme.yml` of a directory, or the file itself as the manifest of
 * the theme around it — and not where it is looked for.
 *
 * Every step resolves the path and holds it to the directories this resolver may read —
 * the deck's directory, the working directory, the built-in themes, and whatever else the
 * program rendering allowed. A `theme:` is a path a deck writes, and a deck naming
 * `../../secrets/theme.yml` used to load it: whatever `author:` that file carried was copied
 * into `docProps/core.xml` of the deck, which is a way to get the contents of a file out
 * through a download. The rules are `Filesystem\PathScope`'s and are the same ones an image
 * path has to pass, because both are paths a deck wrote.
 */
final class ThemeResolver
{
    /** Where a theme sits under a directory, and beside the library's own source. */
    private const THEMES = '/themes';

    private readonly string $builtinDirectory;

    /**
     * @var list<string>
     */
    private readonly array $roots;

    private readonly PathScope $files;

    /**
     * @param list<string> $roots Further directories a deck may name a theme in, for a
     *                           shared library of themes beside the project. Each is a
     *                           directory of themes: a name is looked for in one of them
     *                           directly, the way it is in the themes that ship with this
     *                           library. As with an image's roots this is the calling
     *                           program's decision, made before a deck is read: a deck that
     *                           could widen it would be the arbitrary path read this
     *                           argument exists to stop.
     * @param string|null $deckDirectory The directory the deck was read from, which is
     *                                   where its own `themes/` sits and where a relative
     *                                   path is written from. Null for a deck handed in
     *                                   as a string: there is no file beside it, so a
     *                                   theme beside it is not there to be found.
     */
    public function __construct(
        private readonly string $workingDirectory = '.',
        ?string $builtinDirectory = null,
        private readonly ThemeLoader $loader = new ThemeLoader(),
        array $roots = [],
        private readonly ?string $deckDirectory = null,
    ) {
        // The themes that ship beside `src/`, so a package installed anywhere
        // resolves its own themes without being told where they are.
        $this->builtinDirectory = $builtinDirectory ?? dirname(__DIR__, 2) . self::THEMES;
        $this->roots = $roots;
        $this->files = new PathScope($this->allowed());
    }

    /**
     * The same resolver, looking beside the deck in that directory as well.
     *
     * A deck and its theme are one thing, and the program rendering the deck knows where
     * the deck is while the resolver does not. A copy rather than a change in place,
     * because the caller may hand the same resolver to several renders.
     *
     * The directory is also the one a relative path in the deck is written from, so a
     * deck naming `theme: ./theme.yml` finds the manifest beside it.
     */
    public function beside(string $directory): self
    {
        return new self(
            $this->workingDirectory,
            $this->builtinDirectory,
            $this->loader,
            $this->roots,
            $directory,
        );
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
            return $this->path(
                $name,
                str_starts_with($name, '/') ? $name : $this->base() . '/' . $name,
            );
        }

        foreach ($this->themeDirectories() as $themes) {
            if (is_dir($directory = $themes . '/' . $name)) {
                return $this->loader->load($this->held($name, $directory));
            }
        }

        throw ThemeNotFound::named($name, $this->available());
    }

    /**
     * The directory a relative path is written from.
     *
     * The deck's own directory, because that is where a deck's paths are written from —
     * the rule a relative image path follows. The working directory stands in for a deck
     * handed in as a string, which has no directory of its own.
     */
    private function base(): string
    {
        return $this->deckDirectory ?? $this->workingDirectory;
    }

    /**
     * The theme an explicit path names: a directory holding a theme, or the manifest itself.
     *
     * @param string $name  What the deck wrote.
     * @param string $found The path it resolved to before it was resolved further.
     *
     * @throws ThemeNotFound
     */
    private function path(string $name, string $found): LoadedTheme
    {
        if (is_file($found)) {
            return $this->loader->loadManifest($this->held($name, $found));
        }

        if (!is_dir($found)) {
            throw ThemeNotFound::noDirectory($found);
        }

        return $this->loader->load($this->held($name, $found));
    }

    /**
     * A path resolved and held to the directories this resolver may read.
     *
     * Two messages rather than one, because "there is nothing there" and "it is there and
     * this render may not read it" are different problems with different fixes: the first is
     * a typo in a deck, the second is a deck reaching for something.
     *
     * @param string $name  What the deck wrote.
     * @param string $found The path it resolved to before it was resolved further.
     *
     * @throws ThemeNotFound
     */
    private function held(string $name, string $found): string
    {
        $real = PathScope::resolve($found);

        if ($real === null || !$this->files->holds($real)) {
            throw ThemeNotFound::outside($name, $real ?? $found, $this->files->roots());
        }

        return $real;
    }

    /**
     * The directories a name is looked for in, in the order they are tried.
     *
     * One list because a name has to resolve the same way whether it is being looked up
     * or enumerated: a name the resolver would accept and not list is a name a failure
     * message leaves the reader to guess at.
     *
     * @return list<string>
     */
    private function themeDirectories(): array
    {
        return [
            ...($this->deckDirectory === null ? [] : [$this->deckDirectory . self::THEMES]),
            $this->workingDirectory . self::THEMES,
            ...$this->roots,
            $this->builtinDirectory,
        ];
    }

    /**
     * The directories a deck-written theme path may land in.
     *
     * @return list<string>
     */
    private function allowed(): array
    {
        $allowed = [$this->workingDirectory, $this->builtinDirectory, ...$this->roots];

        if ($this->deckDirectory !== null) {
            array_unshift($allowed, $this->deckDirectory);
        }

        return $allowed;
    }

    /**
     * Every theme name that resolves, the deck's own, the working directory's, the
     * roots' and the library's together, sorted: a name resolves to one of them and the
     * reader of the list cannot tell which, so listing one kind ahead of the other would
     * say something untrue.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = [];

        foreach ($this->themeDirectories() as $themes) {
            if (!is_dir($themes)) {
                continue;
            }

            foreach (scandir($themes) ?: [] as $entry) {
                if (str_starts_with($entry, '.')) {
                    continue;
                }

                if (is_file($themes . '/' . $entry . '/' . ThemeLoader::THEME_FILE)) {
                    $names[$entry] = true;
                }
            }
        }

        $found = array_keys($names);

        sort($found);

        return $found;
    }
}
