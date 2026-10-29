<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Files\DiskFinder;
use MarkdownPresentation\Files\FileFinder;
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
 *
 * The files *inside* a theme are held to the same scope, because a directory that is
 * inside it may hold a `theme.yml` that is a link out of it: the path the deck wrote
 * is inside the scope and the file it reaches is not, which is the pair a check on the
 * path as written cannot tell. The loader holds each file it reads, and the template it
 * names, and refuses the one that resolves elsewhere.
 *
 * The themes themselves are read through a finder, which is given the directories of
 * themes above: the finder resolves a name against them in the order they are given,
 * and a theme directory that holds no manifest still shadows a later one. A finder the
 * calling program brought is the source of themes in its own right, and the directories
 * on disk remain the fallback rather than the only place a theme is looked for.
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

    private readonly PathScope $scope;

    private readonly ThemeLoader $loader;

    /** The loader the calling program brought, if it brought one. */
    private readonly ?ThemeLoader $given;

    /** The source of themes the calling program brought, if it brought one. */
    private readonly ?FileFinder $files;

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
     * @param ThemeLoader|null $loader The loader the themes are read through. A loader
     *                                  given here brings its own scope; the scope of this
     *                                  resolver still holds the theme's own directory.
     * @param FileFinder|null $files The source of themes, for a program whose themes are
     *                                 not in a directory. The directories of themes on disk
     *                                 remain the fallback.
     */
    public function __construct(
        private readonly string $workingDirectory = '.',
        ?string $builtinDirectory = null,
        ?ThemeLoader $loader = null,
        array $roots = [],
        private readonly ?string $deckDirectory = null,
        ?FileFinder $files = null,
    ) {
        $this->roots = $roots;
        // The themes that ship beside `src/`, so a package installed anywhere
        // resolves its own themes without being told where they are.
        $this->builtinDirectory = $builtinDirectory ?? dirname(__DIR__, 2) . self::THEMES;
        $this->scope = new PathScope($this->allowed());
        $this->given = $loader;
        $this->loader = $loader ?? new ThemeLoader($this->scope);
        $this->files = $files;
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
            $this->given,
            $this->roots,
            $directory,
            $this->files,
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
            $path = str_starts_with($name, '/') ? $name : $this->base() . '/' . $name;

            return is_file($path)
                ? $this->themeOfManifest($this->held($name, $path), $name)
                : $this->themeIn($this->directory($name, $path), $name);
        }

        $theme = $this->throughFinder($name);

        if ($theme !== null) {
            return $theme;
        }

        foreach ($this->themeDirectories() as $themes) {
            if (is_dir($directory = $themes . '/' . $name)) {
                return $this->themeIn($this->directory($name, $directory), $name);
            }
        }

        throw ThemeNotFound::named($name, $this->available());
    }

    /**
     * A theme in the source the calling program brought, or null when it has none.
     *
     * The manifest is asked for by name — `house/theme.yml` — because the library
     * composes names and a finder answers them. A finder that answers a path has it
     * held to the scope, exactly as a theme found on disk has; one that answers the
     * bytes has the caller's word for them.
     *
     * Whether the theme is there at all is answered by the listing of its files, and
     * not by asking for the manifest: a theme whose files are there but whose manifest
     * is not is a different failure from a theme that is not there, and the listing
     * tells the two apart without reading anything.
     */
    private function throughFinder(string $name): ?LoadedTheme
    {
        $finder = $this->finder();

        $manifest = $finder->path($name . '/' . ThemeLoader::THEME_FILE, 'theme');

        if ($manifest !== null) {
            // The theme's own directory is held to the scope, as it is when the theme was
            // found on disk: a `theme:` is a path a deck writes, and what is allowed is
            // where it lands.
            $this->held($name, dirname($manifest));

            return $this->loader->load($name, $name, $finder);
        }

        if ($finder->files($name) === []) {
            return null;
        }

        return $this->loader->load($name, $name, $finder);
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
     * The finder the themes are read through: the source the calling program brought,
     * or the disk default.
     *
     * The default holds the directories of themes the six steps name, in the order they
     * are tried, so a theme is resolved by its directory and a theme directory that holds
     * no manifest still shadows a later one.
     */
    private function finder(): FileFinder
    {
        return $this->files ?? new DiskFinder(theme: $this->themeDirectories());
    }

    /**
     * The theme in a directory, read through a finder that resolves it there.
     *
     * The finder is given the directory that holds the theme, so the name it resolves is
     * the theme this resolver found and not another theme that shares its name.
     */
    private function themeIn(string $directory, string $written): LoadedTheme
    {
        return $this->loader->load(
            basename($directory),
            $written,
            new DiskFinder(theme: [dirname($directory)]),
        );
    }

    /**
     * The theme a manifest is, read through a finder that resolves it there.
     *
     * The manifest's own directory, so the files beside it are the ones the manifest was
     * written next to.
     */
    private function themeOfManifest(string $file, string $written): LoadedTheme
    {
        $directory = dirname($file);

        return $this->loader->loadManifest(
            basename($directory),
            $written,
            new DiskFinder(theme: [dirname($directory)]),
        );
    }

    /**
     * The directory a step of the order above found, resolved and held to the roots.
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
    private function directory(string $name, string $found): string
    {
        if (!is_dir($found)) {
            throw ThemeNotFound::noDirectory($found);
        }

        return $this->held($name, $found);
    }

    /**
     * A path resolved and held to the directories this resolver may read.
     *
     * @param string $name  What the deck wrote.
     * @param string $found The path it resolved to before it was resolved further.
     *
     * @throws ThemeNotFound
     */
    private function held(string $name, string $found): string
    {
        $real = PathScope::resolve($found);

        if ($real === null || !$this->scope->holds($real)) {
            throw ThemeNotFound::outside($name, $real ?? $found, $this->scope->roots());
        }

        return $real;
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
     * The source the calling program brought and the directories on disk are listed
     * together, because a name that resolves is a name that is offered: a program with
     * themes of its own does not lose the ones that ship with this library.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = [...$this->finder()->themes(), ...$this->diskThemes()];

        $unique = array_values(array_unique($names));

        sort($unique);

        return $unique;
    }

    /**
     * The themes in the directories on disk, which are the fallback for a name the source
     * the calling program brought does not have.
     *
     * @return list<string>
     */
    private function diskThemes(): array
    {
        return (new DiskFinder(theme: $this->themeDirectories()))->themes();
    }
}
