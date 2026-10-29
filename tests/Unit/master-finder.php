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
