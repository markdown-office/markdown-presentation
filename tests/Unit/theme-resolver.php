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

it('finds a theme beside the deck when the working directory is somewhere else', function () {
    // The theme of a deck is in the deck's own directory, and the process rendering the
    // deck is somewhere else entirely: a build script that renders a deck by its path
    // must not have to change directory first.
    $deck = ThemeDirectory::create(['themes/bakery/theme.yml' => "name: Bakery\n"], 'deck');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect($resolver->resolve('bakery')->theme()->name())->toBe('Bakery');
});

it('falls back to the working directory when the deck has no theme of that name', function () {
    $deck = ThemeDirectory::create(['themes/other/theme.yml' => "name: Other\n"], 'deck');
    $resolver = resolverIn(ThemeDirectory::withAcme())->beside($deck);

    expect($resolver->resolve('acme')->theme()->name())->toBe('Acme');
});

it('lists the themes of the deck and of the library together', function () {
    // A failure names every theme that resolves, so the list has to hold the deck's own
    // themes as well as the ones that ship with the library.
    $deck = ThemeDirectory::create(['themes/bakery/theme.yml' => "name: Bakery\n"], 'deck');
    $resolver = (new ThemeResolver(ThemeDirectory::create([], 'working')))->beside($deck);

    expect($resolver->available())->toBe([
        'bakery',
        'corporate-dark',
        'default',
        'dense',
        'minimal',
        'pitch',
    ]);
});

it('reads a theme from the manifest file a path names', function () {
    // `theme: ./theme.yml` is the manifest rather than the directory holding it, and the
    // rest of the theme — the layouts, the styles, the template — is read from beside it.
    $directory = ThemeDirectory::create([
        'theme.yml' => "name: Manifest\n",
        'layouts.yml' => "layouts:\n  content: Title and Content\n",
    ], 'manifest');
    $resolver = resolverIn($directory);

    $theme = $resolver->resolve('./theme.yml');

    expect($theme->theme()->name())->toBe('Manifest')
        ->and($theme->directory())->toBe(realpath($directory))
        ->and($theme->layouts())->toBe(['content' => 'Title and Content']);
});

it('says a file that is not a theme manifest is not one', function () {
    // The other half of the manifest rule: a file beside a theme is not a theme, and a
    // deck naming one is told so rather than handed a theme it did not ask for.
    $directory = ThemeDirectory::create(['notes.txt' => 'not a theme'], 'manifest');
    $resolver = resolverIn($directory);

    $resolver->resolve('./notes.txt');
})->throws(ThemeNotFound::class, 'is not a theme: it has no theme.yml');

it('reads a manifest that is a link to a file of another name', function () {
    // The name the deck wrote is what makes a file the manifest of a theme, so a
    // `theme.yml` that is a link to a file called something else is the manifest the
    // deck asked for. The file it reaches is inside the roots, so it is read — and the
    // rest of the theme is read from beside the file it reached.
    $deck = ThemeDirectory::create([
        'theme.yml' => "name: Manifest\n",
        'other.yml' => "name: Linked\n",
        'layouts.yml' => "layouts:\n  content: Title and Content\n",
    ], 'manifest');
    ThemeDirectory::link($deck . '/theme.yml', $deck . '/other.yml');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    $theme = $resolver->resolve('./theme.yml');

    expect($theme->theme()->name())->toBe('Linked')
        ->and($theme->directory())->toBe(realpath($deck))
        ->and($theme->layouts())->toBe(['content' => 'Title and Content']);
});

it('refuses a manifest that is a link to a file outside the roots', function () {
    // The scope is about where the file is, not what it is called: a manifest that
    // reaches out of the roots is refused whatever the deck called it, and the refusal
    // names the file it found.
    $outside = ThemeDirectory::create(['other.yml' => "name: Stolen\n"], 'outside');
    $deck = ThemeDirectory::create(['theme.yml' => "name: Acme\n"], 'deck');
    ThemeDirectory::link($deck . '/theme.yml', $outside . '/other.yml');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect(fn () => $resolver->resolve('./theme.yml'))
        ->toThrow(ThemeNotFound::class, 'is at "' . realpath($outside . '/other.yml') . '" rather than one this render may read');
});

it('refuses a theme file outside the directories the resolver was given', function () {
    // A manifest is a path a deck wrote, and it is held to the roots like any other one:
    // the `author:` of a theme file is copied into the deck it renders.
    $outside = ThemeDirectory::create(['theme.yml' => "name: Stolen\n"], 'outside');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    expect(fn () => $resolver->resolve($outside . '/theme.yml'))
        ->toThrow(ThemeNotFound::class, 'rather than one this render may read');
});

it('refuses a theme outside the roots when the deck has a directory of its own', function () {
    // The deck's directory is one more root, not a licence to reach the whole disk: a
    // theme outside every root is still a theme this render may not read.
    $outside = ThemeDirectory::minimal('Stolen');
    $deck = ThemeDirectory::create(['themes/bakery/theme.yml' => "name: Bakery\n"], 'deck');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect(fn () => $resolver->resolve($outside))
        ->toThrow(ThemeNotFound::class, 'is at "' . $outside . '" rather than one this render may read');
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
        ->toThrow(ThemeNotFound::class, 'is at "' . $outside . '" rather than one this render may read');
});

it('writes a relative path from the working directory when the deck has none of its own', function () {
    // A deck handed in as a string has no directory beside it, so the working directory
    // is what a path in it is written from — the same rule a relative image path
    // follows, and the only one a deck without a directory can follow.
    $resolver = resolverIn(ThemeDirectory::withAcme());

    expect($resolver->resolve('./themes/acme')->theme()->name())->toBe('Acme');
});

it('writes a relative path from the deck\'s own directory', function () {
    // The theme of a deck is written next to the deck, and the process rendering the
    // deck is somewhere else entirely: a build script that renders a deck by its path
    // must not have to change directory first. The path used to be written from the
    // working directory, so a deck whose theme sat beside it was not found at all.
    $deck = ThemeDirectory::create(['theme.yml' => "name: Beside\n"], 'deck');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect($resolver->resolve('./theme.yml')->theme()->name())->toBe('Beside');
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

it('finds a theme in a root the calling program allowed, by its name', function () {
    // The other half of that test: a root is a directory of themes, and a name is looked
    // for in one of them the way it is in the themes that ship with the library. A
    // shared library of themes is no use if a name is never looked for in it.
    $library = ThemeDirectory::create(['shared/theme.yml' => "name: Shared\n"], 'library');
    $working = ThemeDirectory::create([], 'working');
    $builtin = ThemeDirectory::create(['builtin/theme.yml' => "name: Builtin\n"], 'builtin');
    $resolver = new ThemeResolver($working, $builtin, roots: [$library]);

    expect($resolver->resolve('shared')->theme()->name())->toBe('Shared');
});

it('lists the themes of the roots as well', function () {
    // A failure names every theme that resolves, so a theme in a root has to be in the
    // list: a name the resolver would accept and not list is a name the reader of the
    // failure is left to guess at.
    $library = ThemeDirectory::create(['shared/theme.yml' => "name: Shared\n"], 'library');
    $working = ThemeDirectory::create([], 'working');
    $builtin = ThemeDirectory::create([], 'builtin');
    $resolver = new ThemeResolver($working, $builtin, roots: [$library]);

    expect($resolver->available())->toBe(['shared']);
});

it('refuses a theme in a root that resolves outside the roots', function () {
    // A root is searched now, and a search is not a permission: a theme in one that
    // resolves outside every root is still a theme this render may not read. The link
    // is what tells the two apart — the name is inside the root and the file it
    // reaches is not.
    $outside = ThemeDirectory::minimal('Stolen');
    $library = ThemeDirectory::create([], 'library');
    symlink($outside, $library . '/stolen');
    $working = ThemeDirectory::create([], 'working');
    $builtin = ThemeDirectory::create([], 'builtin');
    $resolver = new ThemeResolver($working, $builtin, roots: [$library]);

    expect(fn () => $resolver->resolve('stolen'))
        ->toThrow(ThemeNotFound::class, 'is at "' . $outside . '" rather than one this render may read');
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

    expect($resolver->available())->toBe(['corporate-dark', 'default', 'dense', 'minimal', 'pitch'])
        ->and($resolver->resolve('default')->theme()->name())->toBe('Default')
        ->and($resolver->resolve('corporate-dark')->theme()->colour('accent1'))->toBe('#89B4FA');
});

it('gives a local theme the same weight as a built-in one', function () {
    $resolver = new ThemeResolver(ThemeDirectory::create([
        'themes/default/theme.yml' => "name: Local\n",
    ], 'working'));

    expect($resolver->resolve('default')->theme()->name())->toBe('Local');
});

it('refuses a theme whose manifest is a link out of the roots', function () {
    // The directory is held to the roots; the files inside it were not. A `theme.yml`
    // that is a link out of the tree is read as readily as one inside it, and the
    // `author:` it carries is copied into `docProps/core.xml` of the deck — the same
    // way out as the path itself was.
    $outside = ThemeDirectory::create(['theme.yml' => "name: Stolen\nauthor: root\n"], 'outside');
    $deck = ThemeDirectory::create(['themes/acme/theme.yml' => "name: Acme\n"], 'deck');
    ThemeDirectory::link($deck . '/themes/acme/theme.yml', $outside . '/theme.yml');

    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect(fn () => $resolver->resolve('acme'))
        ->toThrow(ThemeNotFound::class, 'is at "' . realpath($outside . '/theme.yml') . '" rather than one this render may read');
});

it('refuses a theme whose styles file is a link out of the roots', function () {
    $outside = ThemeDirectory::create(['styles.yml' => "heading.1: ReportTitle\n"], 'outside');
    $deck = ThemeDirectory::create(['themes/acme/theme.yml' => "name: Acme\n"], 'deck');
    ThemeDirectory::link($deck . '/themes/acme/styles.yml', $outside . '/styles.yml');

    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect(fn () => $resolver->resolve('acme'))
        ->toThrow(ThemeNotFound::class, 'is at "' . realpath($outside . '/styles.yml') . '" rather than one this render may read');
});

it('refuses a theme whose layouts file is a link out of the roots', function () {
    $outside = ThemeDirectory::create(['layouts.yml' => "layouts:\n  content: Title and Content\n"], 'outside');
    $deck = ThemeDirectory::create(['themes/acme/theme.yml' => "name: Acme\n"], 'deck');
    ThemeDirectory::link($deck . '/themes/acme/layouts.yml', $outside . '/layouts.yml');

    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect(fn () => $resolver->resolve('acme'))
        ->toThrow(ThemeNotFound::class, 'is at "' . realpath($outside . '/layouts.yml') . '" rather than one this render may read');
});

it('refuses a theme whose template is a link out of the roots', function () {
    // The loader does not open a `.pptx`; the renderer does, at render time, with
    // whatever path the theme named. A template that is a link out of the roots is a
    // file outside them, opened under a name that is inside.
    $outside = ThemeDirectory::create(['template.pptx' => 'not really a template'], 'outside');
    $deck = ThemeDirectory::create(['themes/acme/theme.yml' => "name: Acme\n"], 'deck');
    ThemeDirectory::link($deck . '/themes/acme/template.pptx', $outside . '/template.pptx');

    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect(fn () => $resolver->resolve('acme'))
        ->toThrow(ThemeNotFound::class, 'is at "' . realpath($outside . '/template.pptx') . '" rather than one this render may read');
});

it('names the path and the roots when a file of a theme is out of them', function () {
    // The refusal has to say what would have worked, the way the refusal for a path
    // out of the roots does: the file it found and the roots it may read.
    $outside = ThemeDirectory::create(['styles.yml' => "heading.1: ReportTitle\n"], 'outside');
    $deck = ThemeDirectory::create(['themes/acme/theme.yml' => "name: Acme\n"], 'deck');
    ThemeDirectory::link($deck . '/themes/acme/styles.yml', $outside . '/styles.yml');
    $working = ThemeDirectory::create([], 'working');
    $builtin = ThemeDirectory::create(['builtin/theme.yml' => "name: Builtin\n"], 'builtin');
    $resolver = (new ThemeResolver($working, $builtin))->beside($deck);

    expect(fn () => $resolver->resolve('acme'))->toThrow(
        ThemeNotFound::class,
        sprintf(
            'The theme "acme" is at "%s" rather than one this render may read (%s).',
            realpath($outside . '/styles.yml'),
            implode(', ', [realpath($deck), realpath($working), realpath($builtin)]),
        ),
    );
});

it('reads a theme whose files are all inside the roots', function () {
    // The other half of the refusals above: a theme whose own files are where it says
    // they are is a theme that reads.
    $deck = ThemeDirectory::create([
        'themes/acme/theme.yml' => "name: Acme\n",
        'themes/acme/styles.yml' => "heading.1: ReportTitle\n",
        'themes/acme/layouts.yml' => "layouts:\n  content: Title and Content\n",
    ], 'deck');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    $theme = $resolver->resolve('acme');

    expect($theme->theme()->name())->toBe('Acme')
        ->and($theme->styles())->toBe(['heading.1' => 'ReportTitle'])
        ->and($theme->layouts())->toBe(['content' => 'Title and Content']);
});

it('reads a theme whose own file is a link that stays inside the roots', function () {
    // A link is not a threat; where it points is. A theme laid out with links — one
    // file shared by two themes — is a theme that works.
    $deck = ThemeDirectory::create([
        'themes/shared/styles.yml' => "heading.1: ReportTitle\n",
        'themes/acme/theme.yml' => "name: Acme\n",
    ], 'deck');
    ThemeDirectory::link($deck . '/themes/acme/styles.yml', $deck . '/themes/shared/styles.yml');

    $resolver = resolverIn(ThemeDirectory::create([], 'working'))->beside($deck);

    expect($resolver->resolve('acme')->styles())->toBe(['heading.1' => 'ReportTitle']);
});

it('does not open a template outside the roots', function () {
    // The finding end to end rather than as a refusal: a deck whose theme names a
    // template outside the roots, and a file written with it. The template is read at
    // render time, by whoever renders the deck, so the path has to be held to the
    // scope before it is handed over.
    $outside = ThemeDirectory::create(['template.pptx' => 'not really a template'], 'outside');
    $deck = ThemeDirectory::create([
        'themes/acme/theme.yml' => "name: Acme\n",
        'themes/acme/layouts.yml' => "layouts:\n  content: Title and Content\n",
    ], 'deck');
    ThemeDirectory::link($deck . '/themes/acme/template.pptx', $outside . '/template.pptx');
    $working = ThemeDirectory::create([], 'working');
    $source = (new DeckParser())->parse("---\ntheme: ./themes/acme\n---\n# One\n");
    $path = Scratch::file('deck.pptx', '');

    expect(fn () => (new PresentationRenderer($deck, new ThemeResolver($working)))->render($source, $path))
        ->toThrow(ThemeNotFound::class, 'rather than one this render may read')
        ->and(filesize($path))->toBe(0);
});
