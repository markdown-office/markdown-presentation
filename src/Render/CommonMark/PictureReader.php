<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\Block\Image as ImageBlock;
use MarkdownPresentation\Render\ImageConverter;

/**
 * A picture a deck named, as the block it is drawn from.
 *
 * Its own class because two callers name a picture in two different ways — `![alt](logo.png)`
 * and `::image{src="logo.png"}` — and they must go through the *same* {@see PathScope}, the same
 * conversion and the same refusals. One of them reaching the filesystem on its own would be a
 * picture a deck could point outside the directory it was given, written in a directive rather
 * than in Markdown, which is the one spelling nobody reads the tests for.
 *
 * The two entry points differ only in where the path and the description come from, which is why
 * they are {@see self::node()} and {@see self::path()} rather than one method with two
 * callers each supplying both.
 *
 * **A picture comes from the source the deck's files come from.** A source that holds a
 * picture as a file gives a path, and the picture is used where it is — held to the scope
 * first, exactly as a picture found on disk is. A source that holds the bytes gives no path,
 * and the bytes are staged into a file of the library's own, which the deck embeds the
 * picture from.
 */
final class PictureReader
{
    /** The prefix of a staged picture, so a file in flight is recognisable as one. */
    private const PREFIX = 'mdpres_pic_';

    /**
     * The files this reader staged and has not given back yet.
     *
     * @var list<string>
     */
    private array $staged = [];

    public function __construct(
        private readonly ImageConverter $images = new ImageConverter(),
    ) {
    }

    /**
     * The picture an inline `![…](…)` names, described by the label's own words.
     *
     * The description is the label's words with its markup taken out, which is what a screen
     * reader reads. The hand-written walk takes the label as the raw text between the brackets,
     * so a `![*a picture*](x.png)` is described as `a picture` here and as `*a picture*` there.
     *
     * @throws ImageNotFound
     * @throws UnsupportedImageFormat
     */
    public function node(Image $node, SlideState $state, InlineTranslator $inline): ImageBlock
    {
        // Decoded, because that is how a name with a space in it is written: CommonMark has no
        // bare space in a destination, so `my picture.png` is a paragraph and `my%20picture.png`
        // is the picture. league hands the target over encoded, and looking for the encoded
        // spelling on disk is looking for a file nobody has.
        return $this->path(rawurldecode($node->getUrl()), InlineTranslator::text($inline->runs($node, $state->slide, $state->theme)), $state);
    }

    /**
     * The picture a path names, described by whatever the deck called it.
     *
     * @throws ImageNotFound
     * @throws UnsupportedImageFormat
     */
    public function path(string $written, string $alt, SlideState $state): ImageBlock
    {
        if (PathScope::isUrl($written)) {
            throw ImageNotFound::aUrl($state->slide, $written);
        }

        $resolved = $this->resolve($written, $state);

        return new ImageBlock(
            $written,
            $resolved,
            $this->images->png($state->slide, $written, $resolved),
            $alt,
            $state->step,
        );
    }

    /**
     * Give back the files this reader staged.
     *
     * A staged picture is the library's own file, and it goes when the render is over: the
     * deck has been written by then, and nothing has to survive it.
     */
    public function release(): void
    {
        foreach ($this->staged as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->staged = [];
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * The picture a deck named, and where it is.
     *
     * The source is asked first: a source that holds the picture as a file gives a path,
     * which is held to the scope as any other path is, and one that holds the bytes gives
     * the picture itself, which is staged into a file of the library's own. The disk is
     * where a picture is found when the source has none.
     *
     * @throws ImageNotFound
     */
    private function resolve(string $written, SlideState $state): string
    {
        $path = $state->files?->path($written, 'picture');

        if ($path !== null) {
            return $this->held($path, $written, $state);
        }

        $bytes = $state->files?->read($written, 'picture');

        if ($bytes !== null) {
            return $this->stage($bytes, $written);
        }

        // Where a path is written *from* says nothing about whether it is allowed: what is
        // allowed is where it lands.
        $joined = str_starts_with($written, '/') ? $written : rtrim($state->directory, '/') . '/' . $written;

        if (!is_file($joined)) {
            throw ImageNotFound::at($state->slide, $written, $joined);
        }

        return $this->held($joined, $written, $state);
    }

    /**
     * A path, resolved and held to the scope.
     *
     * `realpath()` rather than the path as written, because `..` and a symlink both walk
     * out of a directory that a prefix test says they are inside.
     *
     * @throws ImageNotFound
     */
    private function held(string $path, string $written, SlideState $state): string
    {
        if (!is_file($path)) {
            throw ImageNotFound::at($state->slide, $written, $path);
        }

        $resolved = PathScope::canonical($path);

        if ($resolved === null || !$state->scope->holds($resolved)) {
            throw ImageNotFound::outside($state->slide, $written, $resolved ?? $path, $state->scope->roots());
        }

        return $resolved;
    }

    /**
     * The picture's bytes, in a file of the library's own.
     *
     * The file is named after the picture, because the tools that read it — the format of
     * a raster, the drawing of a vector, the name of the part in the package — read the
     * name as well as the bytes, and a `.svgz` is a gzipped drawing rather than a broken
     * one.
     *
     * It is made with `tempnam()`, so its name is one nobody guessed, and its mode is
     * narrowed here, so that it is one nobody else on the machine can read. A file that
     * could not be written is left as it is: the reader that opens the picture refuses it
     * and says so, which is a better sentence than one about a temporary directory.
     */
    private function stage(string $bytes, string $written): string
    {
        // A file that could not be made leaves a path that is not there, and the reader
        // that opens the picture refuses it and says so.
        $temporary = @tempnam(sys_get_temp_dir(), self::PREFIX) ?: sys_get_temp_dir() . '/' . self::PREFIX . bin2hex(random_bytes(8));

        $extension = pathinfo($written, PATHINFO_EXTENSION);
        $path = $extension === '' ? $temporary : $temporary . '.' . $extension;

        // Renamed rather than written under a second name, so that the file keeps the mode
        // `tempnam()` gave it.
        @rename($temporary, $path);

        @file_put_contents($path, $bytes);

        // The mode is narrowed here: renaming keeps the mode `tempnam()` gave the file,
        // but a file created from scratch — the fallback path — is created at the process
        // umask, and a picture in flight through a shared temporary directory is then
        // readable by every account on the machine.
        @chmod($path, 0o600);

        // Both names are the library's to remove: the picture is under the second, and the
        // first is where the file is if the rename did not happen.
        $this->staged[] = $temporary;
        $this->staged[] = $path;

        return $path;
    }
}
