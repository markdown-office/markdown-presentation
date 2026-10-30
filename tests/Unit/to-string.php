<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\Staging;
use MarkdownPresentation\Tests\Support\MemoryFinder;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/*
 * The deck, as bytes.
 *
 * The counterpart of the Word half's `toDocx()`: a deck that comes back as bytes rather
 * than as a path. A `.pptx` is a package, and PHP's `ZipArchive` cannot write one to
 * memory, so the deck is written to a file of the library's own and read back from it.
 *
 * Nothing here compares the bytes of two archives: a zip carries two-second timestamps and
 * `docProps/core.xml` carries the clock, so two correct renders of one deck differ. The
 * parts are what a test reads.
 */

/**
 * The files of this library in the system temp directory, as they are now.
 *
 * Every one of them: a staged deck, a staged master and a staged picture all carry this
 * half's prefix, and a file of the library's is left on the disk by nobody.
 *
 * @return list<string>
 */
function stagedFiles(): array
{
    return glob(sys_get_temp_dir() . '/mdpres_*') ?: [];
}

it('returns the bytes of a deck that opens as one', function () {
    // The bytes are a deck, and they are the deck: compared by a part rather than by the
    // archive, because a zip carries two-second timestamps and `docProps/core.xml` carries
    // the clock.
    $deck = (new DeckParser())->parse("# One\n\nSome text.\n");

    $written = Scratch::file('written.pptx', '');
    (new PresentationRenderer())->render($deck, $written);

    $read = Scratch::file('read.pptx', '');
    file_put_contents($read, (new PresentationRenderer())->toString($deck));

    expect(Pptx::open($read)->part('ppt/slides/slide1.xml'))
        ->toBe(Pptx::open($written)->part('ppt/slides/slide1.xml'));
});

it('leaves nothing on the disk behind it', function () {
    // The deck was written to a file of the library's own, and the file is given back.
    $before = stagedFiles();

    (new PresentationRenderer())->toString((new DeckParser())->parse("# One\n"));

    expect(stagedFiles())->toBe($before);
});

it('leaves nothing on the disk behind it when the deck is refused', function () {
    // A deck that cannot be drawn is a failure with no file behind it — not even the
    // temporary one the bytes would have been read from.
    $before = stagedFiles();

    expect(fn () => (new PresentationRenderer())->toString((new DeckParser())->parse("---\nlayout: nonsense\n---\n\n# One\n")))
        ->toThrow(UnknownLayoutRole::class);

    expect(stagedFiles())->toBe($before);
});

it('leaves nothing on the disk behind it, master and all', function () {
    // The master the theme carries was staged for the render, and it goes with it: the
    // render is over, and a file of the library's own does not outlive it.
    $before = stagedFiles();

    $finder = new MemoryFinder([
        'house/theme.yml' => (string) file_get_contents(masterTheme() . '/theme.yml'),
        'house/layouts.yml' => (string) file_get_contents(masterTheme() . '/layouts.yml'),
        'house/template.pptx' => (string) file_get_contents(masterTheme() . '/template.pptx'),
    ]);

    (new PresentationRenderer(themes: masterResolver($finder)))
        ->toString((new DeckParser())->parse("---\ntheme: house\n---\n\n# One\n"));

    expect(stagedFiles())->toBe($before);
});

it('says so when the deck it wrote cannot be read back', function () {
    // A deck that was not written is a failure and not an empty deck: bytes that open
    // as nothing are the one answer a caller cannot act on.
    expect(fn () => Staging::of(function (string $path): void {
        unlink($path);
    }))->toThrow(Exception::class, 'Unable to read the generated deck.');
});
