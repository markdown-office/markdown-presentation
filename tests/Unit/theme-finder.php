<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Files\DiskFinder;
use MarkdownPresentation\Files\FileFinder;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Tests\Support\MemoryFinder;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\ThemeLoader;
use MarkdownPresentation\Theme\ThemeResolver;

/*
 * The theme, read through a finder.
 *
 * The library asks for names and a finder answers; the scope — the question of what
 * this render may read — stays with the library, which holds the path a finder answered
 * to its own scope and refuses what is out of reach in its own words. These are about
 * that division: the finder answers for a file that is out of reach, and the library is
 * the one that says no.
 */

/**
 * A resolver that looks nowhere but where it is told to.
 *
 * The themes that ship with this library and the ones beside the deck would otherwise
 * be in every list of what a failure names, and a test about a name that is not there
 * is about the names that are.
 */
function finderResolver(?FileFinder $files = null, array $roots = []): ThemeResolver
{
    return new ThemeResolver(
        workingDirectory: ThemeDirectory::create([], 'working'),
        builtinDirectory: ThemeDirectory::create([], 'builtin'),
        roots: $roots,
        files: $files,
    );
}

it('reads a theme through the finder', function () {
    // The source the finder exists for: a plugin that keeps its themes in a database
    // and never had a directory to hand over. The theme comes back the same way it
    // would from a directory.
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1: ReportTitle\n",
        'house/layouts.yml' => "layouts:\n  content: Title and Content\n",
    ]);

    $theme = (new ThemeLoader())->load('house', 'house', $finder);

    expect($theme->theme()->name())->toBe('House')
        ->and($theme->styles())->toBe(['heading.1' => 'ReportTitle'])
        ->and($theme->layouts())->toBe(['content' => 'Title and Content'])
        // A finder with no path has no directory, and the theme's name stands in.
        ->and($theme->directory())->toBe('house');
});

it('asks the finder for the theme\'s files by name', function () {
    // The names are the library's own: a theme's manifest is `<theme>/theme.yml` and
    // the files beside it are `<theme>/styles.yml` and the rest.
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1: ReportTitle\n",
    ]);

    (new ThemeLoader())->load('house', 'house', $finder);

    $asked = $finder->asked();

    sort($asked);

    expect($asked)->toBe([
        'house/layouts.yml',
        'house/styles.yml',
        'house/template.pptx',
        'house/text-styles.yml',
        'house/theme.yml',
    ]);
});

it('resolves a theme the source holds and no directory has', function () {
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1: ReportTitle\n",
    ]);

    $theme = finderResolver($finder)->resolve('house');

    expect($theme->theme()->name())->toBe('House')
        ->and($theme->styles())->toBe(['heading.1' => 'ReportTitle']);
});

it('refuses a theme file that resolves outside the scope, in the library\'s words', function () {
    // The finder answers the path; the library holds it to its own scope and refuses
    // it. The message is the library's, because the library is what knows what this
    // render may read.
    $themes = ThemeDirectory::create(['house/theme.yml' => "name: House\n"], 'themes');
    $outside = ThemeDirectory::create(['styles.yml' => "heading.1: ReportTitle\n"], 'outside');

    ThemeDirectory::link($themes . '/house/styles.yml', $outside . '/styles.yml');

    $loader = new ThemeLoader(new PathScope([$themes]));

    expect(fn () => $loader->load('house', 'house', new DiskFinder(theme: [$themes])))
        ->toThrow(ThemeNotFound::class, 'is at "' . realpath($outside . '/styles.yml') . '" rather than one this render may read');
});

it('refuses a theme the source answers for outside the roots, in the library\'s words', function () {
    // The finder answers the path; the library holds it to its own scope and refuses
    // it. A finder that refused would throw this half's exception, which the other
    // half of this suite would not catch.
    $outside = ThemeDirectory::create(['secrets/theme.yml' => "name: Secrets\n"], 'outside');

    expect(fn () => finderResolver(new DiskFinder(theme: [$outside]))->resolve('secrets'))
        ->toThrow(ThemeNotFound::class, 'rather than one this render may read');
});

it('reports a theme whose file the finder answers for is not there', function () {
    // A finder that answers a path for a file that has since gone: the theme is not
    // there, and the library says so rather than reading nothing.
    $finder = new class implements FileFinder {
        public function read(string $name, ?string $role = null): ?string
        {
            return null;
        }

        public function path(string $name, ?string $role = null): ?string
        {
            return '/mdpresentation-not-here/theme.yml';
        }

        public function themes(): array
        {
            return [];
        }

        public function files(string $theme): array
        {
            return [];
        }
    };

    $loader = new ThemeLoader(new PathScope([ThemeDirectory::create([], 'gone')]));

    expect(fn () => $loader->load('house', 'house', $finder))
        ->toThrow(ThemeNotFound::class, 'is not a theme: it has no theme.yml');
});

it('lets a theme directory without a manifest shadow a later one', function () {
    // The theme's directory is found first and the manifest is looked for inside it, so
    // a directory that exists but holds no manifest is the theme: the manifest decides
    // whether it is one, and the library says so rather than falling through to a theme
    // of another name.
    $first = ThemeDirectory::create([], 'first');
    $second = ThemeDirectory::create(['house/theme.yml' => "name: House\n"], 'second');

    mkdir($first . '/house');

    expect(fn () => finderResolver(roots: [$first, $second])->resolve('house'))
        ->toThrow(ThemeNotFound::class, 'is not a theme: it has no theme.yml');
});

it('lists the themes that resolve, so a failure can name them', function () {
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'zebra/theme.yml' => "name: Zebra\n",
    ]);

    expect(finderResolver($finder)->available())->toBe(['house', 'zebra']);
});

it('names the themes that exist when a name is not one of them', function () {
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
    ]);

    expect(fn () => finderResolver($finder)->resolve('huse'))
        ->toThrow(ThemeNotFound::class, 'There is no theme named "huse". Available: house.');
});
