<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use MarkdownPresentation\Exception\UnreadableDeck;
use MarkdownPresentation\Exception\UnpatchedDependency;
use MarkdownPresentation\Fixes;
use MarkdownPresentation\Render\ImageConverter;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use ZipArchive;

/**
 * A `.pptx`, opened and bounded before anything is read out of it.
 *
 * A `.pptx` is a zip of XML parts, and a zip says how its contents are laid
 * out and nothing about how much room they will take up: forty kilobytes of a
 * slide part is forty megabytes of paragraph, and the entry count is whatever
 * the writer felt like. So {@see Options::$maxPartBytes} and
 * {@see Options::$maxEntries} bound what the package may hold before it is
 * inflated, and they are read out of the central directory, which costs no
 * decompression at all.
 *
 * The archive is opened here and by the reader underneath, and the two do not
 * see the same bytes if the file changes between them. That is the file
 * changing under a reader that has already begun, and it is a narrower problem
 * than the one the bounds are for.
 */
final class Package
{
    private ?string $scratchPath = null;

    private function __construct(private readonly PhpPresentation $deck)
    {
    }

    public static function open(string $path, Options $options = new Options()): self
    {
        self::bounds($path, $options);

        return new self(self::load($path));
    }

    public static function fromString(string $bytes, Options $options = new Options()): self
    {
        $path = self::writeScratchFile($bytes);

        try {
            $package = self::open($path, $options);
        } catch (\Throwable $failure) {
            // Nothing took ownership of the scratch file, so it is removed here
            // rather than being left for a clean-up that is never registered.
            @unlink($path);

            throw $failure;
        }

        $package->scratchPath = $path;

        return $package;
    }

    public function deck(): PhpPresentation
    {
        return $this->deck;
    }

    /**
     * Release the package, removing the scratch file if one was needed.
     *
     * Safe to call more than once: the destructor calls it after the caller has
     * already closed the package.
     */
    public function close(): void
    {
        if ($this->scratchPath !== null) {
            @unlink($this->scratchPath);
            $this->scratchPath = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Refuse a package that is too large to be worth reading.
     *
     * The sizes are the ones the central directory declares, which costs one
     * lookup per part and no decompression. A part that lies about its size
     * downward is caught by the reader's own consistency check, and one that
     * lies upward is caught here.
     */
    private static function bounds(string $path, Options $options): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw UnreadableDeck::cannotOpen($path);
        }

        try {
            if ($zip->numFiles > $options->maxEntries) {
                throw UnreadableDeck::tooManyParts($path, $zip->numFiles, $options->maxEntries);
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index) ?: ['name' => '', 'size' => 0];
                $name = (string) $stat['name'];

                if ((int) $stat['size'] > $options->maxPartBytes) {
                    throw UnreadableDeck::partTooLarge(
                        $path,
                        $name,
                        (int) $stat['size'],
                        $options->maxPartBytes,
                    );
                }

                self::boundsPicture($path, $name, $zip);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Refuse a picture that is larger than a picture is allowed to be.
     *
     * The reader decodes every picture in the deck as it reads it, and a
     * picture is allocated by the canvas its header declares rather than by
     * the bytes it is made of. That allocation is GD's, so it is outside the
     * limit PHP enforces: the process is killed rather than the deck refused.
     * The size is read from the header without decoding the picture, so the
     * cost of refusing one is the cost of reading a few bytes.
     *
     * The ceiling is the one the forward direction writes pictures under, so a
     * deck this library wrote can be read back.
     */
    private static function boundsPicture(string $path, string $name, ZipArchive $zip): void
    {
        if (! str_starts_with($name, 'ppt/media/')) {
            return;
        }

        $bytes = $zip->getFromName($name);

        // A media part that is not a raster — a vector, a sound — has no size to
        // read, and neither has one the archive cannot hand back at all.
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);

        if ($size === false) {
            return;
        }

        if ($size[0] * $size[1] > ImageConverter::MAX_PIXELS) {
            throw UnreadableDeck::pictureTooLarge(
                $path,
                $name,
                $size[0],
                $size[1],
                ImageConverter::MAX_PIXELS,
            );
        }
    }

    /**
     * The deck, read by the reader that knows the format.
     *
     * @throws UnreadableDeck when the reader refuses the package.
     * @throws UnpatchedDependency when the reader is not the one this library needs.
     */
    private static function load(string $path): PhpPresentation
    {
        Fixes::require();

        try {
            return IOFactory::createReader('PowerPoint2007')->load($path);
        } catch (\Throwable $failure) {
            throw UnreadableDeck::cannotRead($path, $failure->getMessage());
        }
    }

    /**
     * Write the bytes to a scratch file so the archive can be opened.
     *
     * `ext-zip` only opens files, and a library has no business writing into
     * the tree it was installed in, so a deck in memory goes to the system temp
     * directory — which is also the only place that is writable when the
     * install is a read-only phar.
     *
     * A file that could not be written is left as it is. The archive open that
     * follows refuses it, and the error names the file, so there is nothing
     * here that says it better.
     */
    private static function writeScratchFile(string $bytes): string
    {
        $path = @tempnam(sys_get_temp_dir(), 'mdpresentation-');

        if ($path !== false) {
            @file_put_contents($path, $bytes);
        }

        // A file that could not be made leaves a path that is not there, and
        // the archive open that follows refuses it and says so.
        return $path === false ? sys_get_temp_dir() . '/mdpresentation' : $path;
    }
}
