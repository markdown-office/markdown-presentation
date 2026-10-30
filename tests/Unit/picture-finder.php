<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Files\DiskFinder;
use MarkdownPresentation\Files\FileFinder;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\MemoryFinder;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/*
 * A picture a deck names comes from the finder.
 *
 * A source that holds a picture as a file gives a path, and the picture is drawn where it
 * is. A source that holds the bytes gives no path, and the bytes are staged into a file
 * of the library's own — which the deck embeds the picture from, and which goes when the
 * render is over.
 */

/**
 * A real PNG of a given size, on disk.
 *
 * Drawn rather than carried as a base64 blob so that the file really is a PNG: this
 * library measures a picture with `getimagesize()`, and a file that is not one fails the
 * first assertion that touches it rather than the last.
 */
function finderPng(int $width = 2, int $height = 2): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 10, 200, 30));

    $path = Scratch::file(sprintf('%dx%d.png', $width, $height), '');
    imagepng($image, $path);

    return $path;
}

/**
 * The media parts of a deck, by name.
 *
 * @return list<string>
 */
function pictureMedia(Pptx $deck): array
{
    return array_values(array_filter(
        $deck->parts(),
        static fn (string $part): bool => str_starts_with($part, 'ppt/media/'),
    ));
}

it('draws a picture the source holds as bytes', function () {
    $finder = new MemoryFinder(['logo.png' => (string) file_get_contents(finderPng())]);

    $path = Scratch::file('picture.pptx', '');

    (new PresentationRenderer(files: $finder))
        ->render((new DeckParser())->parse("![A logo](logo.png)\n"), $path);

    $deck = Pptx::open($path);
    $media = pictureMedia($deck);

    expect($media)->toHaveCount(1)
        // And the picture is the one that went in, rather than a placeholder.
        ->and($media[0])->toEndWith('.png')
        ->and($deck->part($media[0]))->toStartWith("\x89PNG");
});

it('draws a picture the source holds as a file', function () {
    // A source that holds the picture as a file gives a path, and the picture is used
    // where it is: the deck's own directory is the root it is read under.
    $directory = Scratch::directoryPath('pictures');
    copy(finderPng(), $directory . '/logo.png');

    $path = Scratch::file('picture-file.pptx', '');

    (new PresentationRenderer($directory, files: new DiskFinder(picture: [$directory])))
        ->render((new DeckParser())->parse("![A logo](logo.png)\n"), $path);

    expect(pictureMedia(Pptx::open($path)))->toHaveCount(1);
});

it('asks the source for the picture by name, as a picture', function () {
    // The name is the deck's own and the role says which world it is resolved in: a
    // picture asked for as a picture is not a document asked for as a document.
    $finder = new MemoryFinder(['logo.png' => (string) file_get_contents(finderPng())]);

    $path = Scratch::file('picture-asked.pptx', '');

    (new PresentationRenderer(files: $finder))
        ->render((new DeckParser())->parse("![A logo](logo.png)\n"), $path);

    expect($finder->asked())->toContain('logo.png')
        ->and($finder->askedFor('logo.png'))->toBe('picture');
});

it('refuses a picture the source holds out of reach, in the library\'s words', function () {
    // The finder answers the path; the library holds it to its own scope and refuses it.
    // The message is the library's, because the library is what knows what this render
    // may read.
    $outside = Scratch::directoryPath('outside');
    copy(finderPng(), $outside . '/logo.png');

    expect(fn () => (new PresentationRenderer(Scratch::directoryPath('deck'), files: new DiskFinder(picture: [$outside])))
        ->render((new DeckParser())->parse("![A logo](logo.png)\n"), Scratch::file('outside.pptx', '')))
        ->toThrow(ImageNotFound::class, 'rather than one this render may read');
});

it('refuses a picture the source answers a path for but does not have', function () {
    // A finder that answers a path for a file that has since gone: the picture is not
    // there, and the library says so rather than reading nothing.
    $finder = new class implements FileFinder {
        public function read(string $name, ?string $role = null): ?string
        {
            return null;
        }

        public function path(string $name, ?string $role = null): ?string
        {
            return '/mdpresentation-not-here/logo.png';
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
        ->render((new DeckParser())->parse("![A logo](logo.png)\n"), Scratch::file('gone.pptx', '')))
        ->toThrow(ImageNotFound::class, 'which is not a file');
});

it('leaves nothing behind when it stages a picture', function () {
    // The picture was drawn from a staged file, so the staged file has to go: the render
    // is over. The renderer is held — a caller keeps it — so the file is gone because the
    // render gave it back and not because something was collected.
    $before = glob(sys_get_temp_dir() . '/mdpres_pic_*') ?: [];

    $finder = new MemoryFinder(['logo.png' => (string) file_get_contents(finderPng())]);

    $renderer = new PresentationRenderer(files: $finder);

    $renderer->render(
        (new DeckParser())->parse("![A logo](logo.png)\n"),
        Scratch::file('picture-staged.pptx', ''),
    );

    expect(glob(sys_get_temp_dir() . '/mdpres_pic_*') ?: [])->toBe($before);
});
