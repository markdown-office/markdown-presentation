<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\ThemeResolver;

function resolverIn(string $workingDirectory): ThemeResolver
{
    return new ThemeResolver($workingDirectory, ThemeDirectory::create([
        'builtin/theme.yml' => "name: Builtin\n",
    ], 'builtin'));
}

it('finds a theme beside the deck first', function () {
    $resolver = resolverIn(ThemeDirectory::withAcme());

    expect($resolver->resolve('acme')->theme()->name())->toBe('Acme');
});

it('finds a theme that ships with the library when there is none beside the deck', function () {
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    expect($resolver->resolve('builtin')->theme()->name())->toBe('Builtin');
});

it('refuses a theme path outside the directories the resolver was given', function () {
    // This test used to read "resolves a path, so a theme can live anywhere" and asserted
    // the vulnerability: a deck naming any directory on the disk had it loaded. What a
    // theme load reaches is not only colours -- `theme.yml`'s `author:` is copied into
    // `docProps/core.xml` of the deck when the deck names no author of its own, so a deck
    // naming somebody else's theme.yml is a way to put a file's contents into a download.
    $outside = ThemeDirectory::minimal('Stolen');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    expect(fn () => $resolver->resolve($outside))
        ->toThrow(ThemeNotFound::class, 'is a directory at "' . $outside . '" rather than one this render may read');
});

it('resolves a relative path against the deck, not against the process', function () {
    $resolver = resolverIn(ThemeDirectory::withAcme());

    expect($resolver->resolve('./themes/acme')->theme()->name())->toBe('Acme');
});

it('refuses an absolute theme path outside the directories the resolver was given', function () {
    // A spelling, not a permission: this used to read "resolves an absolute path as it
    // stands", and the containment is the same test whichever way a deck wrote the path.
    $outside = ThemeDirectory::minimal('Absolute');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    expect(fn () => $resolver->resolve($outside))->toThrow(ThemeNotFound::class, 'rather than one this render may read');
});

it('resolves an absolute theme path inside the working directory', function () {
    // The other half of that test: an absolute path is not refused because of its shape.
    $working = ThemeDirectory::create(['vendor/theme.yml' => "name: Vendored\n"], 'working');
    $resolver = resolverIn($working);

    expect($resolver->resolve($working . '/vendor')->theme()->name())->toBe('Vendored');
});

it('reads a theme from a root the calling program allowed', function () {
    // A shared library of themes beside the project is a real case, so the resolver takes
    // the roots as an argument: the program rendering the deck decides, before it reads
    // one, and a deck never can.
    $library = ThemeDirectory::minimal('Shared');
    $working = ThemeDirectory::create([], 'working');
    $builtin = ThemeDirectory::create(['builtin/theme.yml' => "name: Builtin\n"], 'builtin');
    $resolver = new ThemeResolver($working, $builtin, roots: [$library]);

    expect($resolver->resolve($library)->theme()->name())->toBe('Shared');
});

it('does not put a theme outside the roots into the file it writes', function () {
    // The finding end to end, rather than as a refusal: a deck naming a theme it may not
    // read, naming no author of its own, and the other theme's `author:` in
    // `docProps/core.xml`. A theme is the only other place a deck's text reaches a file it
    // was not pointed at, and it reached it through a path -- so a deck was a way to ask
    // for an arbitrary file's `author:` line and be handed it in the download.
    $outside = ThemeDirectory::create(['theme.yml' => "name: Stolen\nauthor: root\n"], 'outside');
    $working = ThemeDirectory::create([], 'working');
    $deck = (new DeckParser())->parse(sprintf("---\ntheme: %s\n---\n# One\n", $outside));
    $path = Scratch::file('deck.pptx', '');

    expect(fn () => (new PresentationRenderer($working, new ThemeResolver($working)))->render($deck, $path))
        ->toThrow(ThemeNotFound::class, 'rather than one this render may read')
        ->and(filesize($path))->toBe(0);
});

it('credits a theme in a root the resolver was given', function () {
    // The other side of the pair above: with the root allowed, the theme is read and its
    // author does reach the file. Without this the test above would also pass if the author
    // were never copied at all.
    $library = ThemeDirectory::create([
        'theme.yml' => "name: Shared\nauthor: Somebody\n",
        'layouts.yml' => "layouts:\n  title: Title Slide\n  content: Title and Content\n",
    ], 'library');
    $working = ThemeDirectory::create([], 'working');
    $builtin = ThemeDirectory::create([], 'builtin');
    $deck = (new DeckParser())->parse(sprintf("---\ntheme: %s\n---\n# One\n", $library));
    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer($working, new ThemeResolver($working, $builtin, roots: [$library])))->render($deck, $path);

    expect(Pptx::open($path)->part('docProps/core.xml'))->toContain('<dc:creator>Somebody</dc:creator>');
});

it('refuses a theme name that is a URL rather than a path', function () {
    // `phar://` and `http://` are names PHP hands to the stream wrapper they say; a theme
    // is a directory with a theme.yml in it and there is nothing for a deck to mean by one.
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    expect(fn () => $resolver->resolve('phar://payload.phar/theme'))
        ->toThrow(ThemeNotFound::class, 'is a URL rather than a path');
});

it('lists every theme that resolves, so an unknown name can name them', function () {
    $resolver = resolverIn(ThemeDirectory::withAcme([
        'themes/zeta/theme.yml' => "name: Zeta\n",
        'themes/not-a-theme/readme.md' => 'no manifest here',
    ]));

    expect($resolver->available())->toBe(['acme', 'builtin', 'zeta']);
});

it('fails with every theme that does resolve, rather than falling back to one', function () {
    $resolver = resolverIn(ThemeDirectory::withAcme());

    $resolver->resolve('corprate-dark');
})->throws(ThemeNotFound::class, 'There is no theme named "corprate-dark". Available: acme, builtin');

it('says so when there is no theme at all to name', function () {
    $resolver = new ThemeResolver(ThemeDirectory::create([], 'working'), ThemeDirectory::create([], 'builtin'));

    $resolver->resolve('default');
})->throws(ThemeNotFound::class, 'Available: (none)');

it('says a path that is not there is not there, rather than that it may not be read', function () {
    // The two are different problems with different fixes, so the message differs: this one
    // is a typo in a deck rather than a deck reaching for something.
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    $resolver->resolve('/tmp/there-is-no-theme-here');
})->throws(ThemeNotFound::class, 'There is no theme directory at "/tmp/there-is-no-theme-here"');

it('says a path that is not there is not there, without listing the names', function () {
    $working = ThemeDirectory::create([], 'working');
    $resolver = resolverIn($working);

    $resolver->resolve('./themes/nope');
})->throws(ThemeNotFound::class, 'There is no theme directory at "');

it('finds the themes that ship with the library by default', function () {
    $resolver = new ThemeResolver(ThemeDirectory::create([], 'working'));

    expect($resolver->available())->toBe(['corporate-dark', 'default', 'minimal'])
        ->and($resolver->resolve('default')->theme()->name())->toBe('Default')
        ->and($resolver->resolve('corporate-dark')->theme()->colour('accent1'))->toBe('#89B4FA');
});

it('gives a local theme the same weight as a built-in one', function () {
    $resolver = new ThemeResolver(ThemeDirectory::create([
        'themes/default/theme.yml' => "name: Local\n",
    ], 'working'));

    expect($resolver->resolve('default')->theme()->name())->toBe('Local');
});
