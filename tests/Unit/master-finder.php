<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnservedTemplate;
use MarkdownPresentation\Files\DiskFinder;
use MarkdownPresentation\Files\FileFinder;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Tests\Support\MemoryFinder;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\ThemeLoader;
use MarkdownPresentation\Theme\ThemeResolver;

/*
 * The master a theme carries, read through the finder.
 *
 * A master is a `.pptx`, and a `.pptx` is opened as a file — so a source that holds only
 * its bytes has them staged into one. What matters is that the deck is drawn in the
 * master's geometry either way, and that nothing of the library's is left on disk when
 * the render is over.
 */

/**
 * The theme that ships a `template.pptx`, committed rather than written moments earlier.
 *
 * A reader that only works on a file its own test made is a reader nobody has shown
 * works on a template.
 */
function masterTheme(): string
{
    return dirname(__DIR__, 2) . '/tests/fixtures/themes/templated';
}

/**
 * A resolver whose themes come from the source given.
 *
 * The fixture's own directory is a root, because a theme is held to the directories the
 * resolver may read — and a theme this library ships is one of them.
 */
function masterResolver(?FileFinder $files = null): ThemeResolver
{
    return new ThemeResolver(
        workingDirectory: Scratch::directoryPath('working'),
        builtinDirectory: Scratch::directoryPath('builtin'),
        roots: [dirname(masterTheme())],
        files: $files,
    );
}

/**
 * A source that holds the theme's files as bytes, and cannot serve its master.
 *
 * The case the listing of a theme's files exists for: the theme carries a
 * `template.pptx`, and this source cannot hand it over. A null answer on its own would
 * say nothing — the theme might carry no master at all — so the listing settles it.
 */
final class MasterlessSource implements FileFinder
{
    /**
     * @param array<string, string> $files The files it holds, by name.
     */
    public function __construct(private readonly array $files = [])
    {
    }

    public function read(string $name, ?string $role = null): ?string
    {
        return str_ends_with($name, 'template.pptx') ? null : ($this->files[$name] ?? null);
    }

    public function path(string $name, ?string $role = null): ?string
    {
        return null;
    }

    public function themes(): array
    {
        return ['house'];
    }

    public function files(string $theme): array
    {
        return ['layouts.yml', 'template.pptx', 'theme.yml'];
    }
}

/**
 * A source that holds the theme's files as paths, and cannot serve the master.
 *
 * The other way a source cannot serve a master: it answers a path for one and the file is
 * not there. The listing says the theme carries a master, and the path says where it
 * should be.
 */
final class UnmountedSource implements FileFinder
{
    /**
     * @param string $directory Where the theme's files are said to be.
     */
    public function __construct(private readonly string $directory)
    {
    }

    public function read(string $name, ?string $role = null): ?string
    {
        return null;
    }

    public function path(string $name, ?string $role = null): ?string
    {
        return $this->directory . '/' . $name;
    }

    public function themes(): array
    {
        return ['house'];
    }

    public function files(string $theme): array
    {
        return ['layouts.yml', 'template.pptx', 'theme.yml'];
    }
}

/**
 * A theme's own files, written where a source says a theme's files are.
 *
 * The master is left out on purpose: the source cannot serve it, and the path it answers
 * for it is the one that is not there.
 */
function unmountedTheme(string $prefix = 'mounted'): string
{
    $directory = Scratch::directoryPath($prefix);

    mkdir($directory . '/house');

    file_put_contents($directory . '/house/theme.yml', (string) file_get_contents(masterTheme() . '/theme.yml'));
    file_put_contents($directory . '/house/layouts.yml', (string) file_get_contents(masterTheme() . '/layouts.yml'));

    return $directory;
}

it('draws a deck in the geometry of a master the source holds as a file', function () {
    // The source holds the theme's files where they are, so the master is opened where
    // it is. The title lands where the template puts it rather than where this library
    // would: that is the geometry the master states.
    $path = Scratch::file('master.pptx', '');

    (new PresentationRenderer(themes: masterResolver(new DiskFinder(theme: [dirname(masterTheme())]))))
        ->render(
            (new DeckParser())->parse("---\ntheme: templated\n---\n# One\n\nText.\n"),
            $path,
        );

    expect(Pptx::open($path)->positionOf('title')['x'])->toBe(66.0);
});

it('draws a deck in the geometry of a master the source holds as bytes', function () {
    // The source the finder exists for: a plugin that keeps its themes in a database and
    // never had a directory to hand over. The master is a package, so its bytes are
    // staged into one — and the deck is drawn in the same geometry all the same.
    $finder = new MemoryFinder([
        'house/theme.yml' => (string) file_get_contents(masterTheme() . '/theme.yml'),
        'house/layouts.yml' => (string) file_get_contents(masterTheme() . '/layouts.yml'),
        'house/template.pptx' => (string) file_get_contents(masterTheme() . '/template.pptx'),
    ]);

    $path = Scratch::file('master-bytes.pptx', '');

    (new PresentationRenderer(themes: masterResolver($finder)))
        ->render(
            (new DeckParser())->parse("---\ntheme: house\n---\n# One\n\nText.\n"),
            $path,
        );

    expect(Pptx::open($path)->positionOf('title')['x'])->toBe(66.0);
});

it('leaves nothing behind when it stages a master', function () {
    // The master was staged, so the staged file has to go: the render is over. The
    // renderer is held — a caller keeps it — so the file is gone because the render gave
    // it back and not because something was collected.
    $before = glob(sys_get_temp_dir() . '/mdpres_master_*') ?: [];

    $finder = new MemoryFinder([
        'house/theme.yml' => (string) file_get_contents(masterTheme() . '/theme.yml'),
        'house/layouts.yml' => (string) file_get_contents(masterTheme() . '/layouts.yml'),
        'house/template.pptx' => (string) file_get_contents(masterTheme() . '/template.pptx'),
    ]);

    $renderer = new PresentationRenderer(themes: masterResolver($finder));

    $renderer->render(
        (new DeckParser())->parse("---\ntheme: house\n---\n# One\n\nText.\n"),
        Scratch::file('master-staged.pptx', ''),
    );

    expect(glob(sys_get_temp_dir() . '/mdpres_master_*') ?: [])->toBe($before);
});

it('leaves nothing behind when the render fails', function () {
    // A render that fails has staged a master all the same, and the file is the
    // library's: a failure is no reason to leave it on the disk.
    $before = glob(sys_get_temp_dir() . '/mdpres_master_*') ?: [];

    $finder = new MemoryFinder([
        'house/theme.yml' => (string) file_get_contents(masterTheme() . '/theme.yml'),
        'house/layouts.yml' => (string) file_get_contents(masterTheme() . '/layouts.yml'),
        'house/template.pptx' => (string) file_get_contents(masterTheme() . '/template.pptx'),
    ]);

    $renderer = new PresentationRenderer(themes: masterResolver($finder));

    expect(fn () => $renderer->render(
        (new DeckParser())->parse("---\ntheme: house\nlayout: nonsense\n---\n# One\n"),
        Scratch::file('master-failed.pptx', ''),
    ))
        ->toThrow(UnknownLayoutRole::class);

    expect(glob(sys_get_temp_dir() . '/mdpres_master_*') ?: [])->toBe($before);
});

it('refuses a master the source cannot serve, by name', function () {
    // The theme carries a master and the source cannot hand it over. Drawing the deck
    // without it would draw it in the wrong design with nothing saying so, so it is
    // refused — and the deck is not drawn at all.
    $finder = new MasterlessSource([
        'house/theme.yml' => (string) file_get_contents(masterTheme() . '/theme.yml'),
        'house/layouts.yml' => (string) file_get_contents(masterTheme() . '/layouts.yml'),
    ]);

    $path = Scratch::file('master-unserved.pptx', '');

    expect(fn () => (new PresentationRenderer(themes: masterResolver($finder)))
        ->render(
            (new DeckParser())->parse("---\ntheme: house\n---\n# One\n"),
            $path,
        ))
        ->toThrow(UnservedTemplate::class, 'The theme "house" carries a "template.pptx" that the source of this render cannot serve');

    // And no deck was written: the refusal came before anything was built.
    expect(filesize($path))->toBe(0);
});

it('refuses a master the source answers a path for and cannot serve', function () {
    // The source names the master and answers a path for it, and the file is not there:
    // the same refusal as a source that answers nothing for it, said the other way
    // round. A deck drawn without the master would be in the wrong design with nothing in
    // it saying so.
    $finder = new UnmountedSource(unmountedTheme());

    expect(fn () => (new ThemeLoader())->load('house', 'house', $finder))
        ->toThrow(UnservedTemplate::class, 'The theme "house" carries a "template.pptx" that the source of this render cannot serve');
});

it('draws no deck when the source answers a path for a master it cannot serve', function () {
    // The refusal came before anything was built, so there is no file behind it.
    $directory = unmountedTheme('unmounted');

    $resolver = new ThemeResolver(
        workingDirectory: Scratch::directoryPath('working'),
        builtinDirectory: Scratch::directoryPath('builtin'),
        roots: [$directory],
        files: new UnmountedSource($directory),
    );

    $path = Scratch::file('master-unmounted.pptx', '');

    expect(fn () => (new PresentationRenderer(themes: $resolver))
        ->render(
            (new DeckParser())->parse("---\ntheme: house\n---\n# One\n"),
            $path,
        ))
        ->toThrow(UnservedTemplate::class);

    expect(filesize($path))->toBe(0);
});

it('draws a deck in a theme that carries no master, and says nothing', function () {
    // A theme without a master is a theme that works: the deck is drawn in the geometry
    // this library puts it at, and nothing is said about a master that was never there.
    $finder = new MemoryFinder([
        'house/theme.yml' => (string) file_get_contents(masterTheme() . '/theme.yml'),
        'house/layouts.yml' => (string) file_get_contents(masterTheme() . '/layouts.yml'),
    ]);

    $path = Scratch::file('no-master.pptx', '');

    (new PresentationRenderer(themes: masterResolver($finder)))
        ->render(
            (new DeckParser())->parse("---\ntheme: house\n---\n# One\n"),
            $path,
        );

    expect(Pptx::open($path)->positionOf('title')['x'])
        ->toBe((float) (new SlideGeometry())->title(LayoutRole::Content)['box']->x);
});

it('draws a deck in a theme the caller named, again', function () {
    // The theme is kept, and so is the master it carries: the file the library staged for
    // it is given back at the end of the render, and the render after that writes it again.
    $finder = new MemoryFinder([
        'house/theme.yml' => (string) file_get_contents(masterTheme() . '/theme.yml'),
        'house/layouts.yml' => (string) file_get_contents(masterTheme() . '/layouts.yml'),
        'house/template.pptx' => (string) file_get_contents(masterTheme() . '/template.pptx'),
    ]);

    $renderer = (new PresentationRenderer(themes: masterResolver($finder)))->withTheme('house');

    $first = Scratch::file('kept-first.pptx', '');
    $second = Scratch::file('kept-second.pptx', '');

    $renderer->render((new DeckParser())->parse("# One\n"), $first);
    $renderer->render((new DeckParser())->parse("# Two\n"), $second);

    expect(Pptx::open($first)->positionOf('title')['x'])->toBe(66.0)
        ->and(Pptx::open($second)->positionOf('title')['x'])->toBe(66.0);
});
