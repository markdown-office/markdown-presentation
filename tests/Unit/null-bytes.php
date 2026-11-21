<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\TemplatesFileNotFound;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnreadableDeck;
use MarkdownPresentation\Files\DiskFinder;
use MarkdownPresentation\Files\FileFinder;
use MarkdownPresentation\Filesystem\FileSystem;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\PowerPointToMarkdown;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Template\TemplatesFile;
use MarkdownPresentation\Theme\ThemeResolver;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * A name holding a null byte is answered, at every door the library has.
 *
 * The file system cannot answer about one: PHP 8.6 raises a `ValueError` from `is_file()`
 * and its neighbours, where earlier versions answered false. A deck can write one, so the
 * library answers rather than raises — the same answer it gives for a name that walks out
 * of its base and for a name that is a URL. An `Error` is not an `Exception` and would
 * escape every `catch` this library documents.
 */

it('answers about a name the file system cannot answer', function () {
    // The guard itself, because the promise is made there: a name holding a null byte is
    // answered "no", and the version of PHP does not decide it.
    expect(FileSystem::isFile("logo.png\0.txt"))->toBeFalse()
        ->and(FileSystem::isDirectory("logo.png\0.txt"))->toBeFalse()
        ->and(FileSystem::exists("logo.png\0.txt"))->toBeFalse()
        ->and(FileSystem::isReadable("logo.png\0.txt"))->toBeFalse()
        ->and(FileSystem::size("logo.png\0.txt"))->toBeNull();
});

it('answers about a name the file system can answer', function () {
    // The other side of the guard: a name that is there is answered as it is, or the
    // guard would answer "no" to everything and nothing would ever be found.
    $directory = Scratch::directoryPath('answered');
    file_put_contents($directory . '/logo.png', 'the bytes');

    expect(FileSystem::isFile($directory . '/logo.png'))->toBeTrue()
        ->and(FileSystem::isDirectory($directory))->toBeTrue()
        ->and(FileSystem::exists($directory . '/logo.png'))->toBeTrue()
        ->and(FileSystem::isReadable($directory . '/logo.png'))->toBeTrue()
        ->and(FileSystem::size($directory . '/logo.png'))->toBe(9)
        // And a name that is not there is answered too, rather than refused.
        ->and(FileSystem::isFile($directory . '/nothing.png'))->toBeFalse()
        ->and(FileSystem::size($directory . '/nothing.png'))->toBeNull();
});

it('answers the finder for a name holding a null byte', function () {
    // A finder never throws: a name that walks out of its base, a name that is a URL and
    // a name holding a null byte are names that resolve to nothing.
    $base = Scratch::directoryPath('nullbyte');
    file_put_contents($base . '/logo.png', 'the bytes');

    $finder = new DiskFinder(theme: [$base], picture: [$base], document: [$base]);

    expect($finder->read("logo.png\0.txt", 'picture'))->toBeNull()
        ->and($finder->path("logo.png\0.txt", 'picture'))->toBeNull()
        // A name that is already a path is the path, null byte and all.
        ->and($finder->path($base . "/logo\0.png", 'document'))->toBeNull()
        // And a theme whose name holds one has no files.
        ->and($finder->files("ho\0use"))->toBe([]);
});

it('answers the resolver for a name holding a null byte', function () {
    // A theme nobody has is a failure the author of the deck can act on — the themes that
    // do exist are named in it — rather than an error the program cannot catch.
    $resolver = new ThemeResolver(Scratch::directoryPath('themes'));

    expect(fn () => $resolver->resolve("ho\0use"))->toThrow(ThemeNotFound::class)
        ->and(fn () => $resolver->resolve("ho\0use/theme.yml"))->toThrow(ThemeNotFound::class);
});

it('answers the templates file for a name holding a null byte', function () {
    // A templates file is either there or it is not, and a name holding a null byte
    // names no file at all.
    $directory = Scratch::directoryPath('templates');
    $templates = new TemplatesFile($directory, new PathScope([$directory]));

    expect(fn () => $templates->read("templates\0.md"))->toThrow(TemplatesFileNotFound::class);
});

it('answers the renderer for a deck naming a null byte', function () {
    // The deck names the templates file, so the name reaches the file system through the
    // renderer rather than through the templates file alone.
    $directory = Scratch::directoryPath('deck');

    expect(fn () => (new PresentationRenderer($directory))
        ->render(
            (new DeckParser())->parse("---\ntemplates-file: \"t\0.md\"\n---\n\n# One\n"),
            Scratch::file('deck.pptx', ''),
        ))
        ->toThrow(TemplatesFileNotFound::class);
});

it('answers the renderer for a picture whose source holds a null byte', function () {
    // The source of a deck's files is the caller's, and a path it answers is asked about
    // as it stands — null byte and all.
    $finder = new class implements FileFinder {
        public function read(string $name, ?string $role = null): ?string
        {
            return null;
        }

        public function path(string $name, ?string $role = null): ?string
        {
            return "/mdpresentation-not-here/logo\0.png";
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

    expect(fn () => (new PresentationRenderer(files: $finder))
        ->render((new DeckParser())->parse("![A logo](logo.png)\n"), Scratch::file('picture.pptx', '')))
        ->toThrow(ImageNotFound::class, 'which is not a file');
});

it('answers the converter for a source holding a null byte', function () {
    // A deck that is not there is a deck that cannot be read, which is a sentence the
    // caller can act on.
    $directory = Scratch::directoryPath('deck');

    expect(fn () => (new PowerPointToMarkdown($directory . "/deck\0.pptx"))->convert())
        ->toThrow(UnreadableDeck::class);
});
