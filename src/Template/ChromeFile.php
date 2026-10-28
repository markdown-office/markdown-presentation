<?php

declare(strict_types=1);

namespace MarkdownPresentation\Template;

use MarkdownPresentation\Exception\ChromeNotFound;
use MarkdownPresentation\Exception\ChromeTooLarge;
use MarkdownPresentation\Filesystem\PathScope;

/**
 * The chrome file a deck names, read from beside the deck.
 *
 * A path a deck writes, so it is resolved and held to the directories this render may
 * read — the same rule an image path has to pass, because both are paths a deck wrote and
 * neither is worth a way out of the directory the deck was given.
 */
final class ChromeFile
{
    /**
     * The largest a chrome file may be.
     *
     * A chrome file is the furniture of a deck — a handful of shapes for each of its
     * templates — and a megabyte of it is far past any file of chrome. It is the one file
     * this library reads whole, so this is what bounds the memory a deck's frontmatter can
     * ask for.
     */
    public const MAX_BYTES = 1024 * 1024;

    /**
     * @param string $directory The directory the deck is in, which is where a relative path
     *                          in the deck is written from.
     */
    public function __construct(
        private readonly string $directory,
        private readonly PathScope $files,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {
    }

    /**
     * The Markdown of the chrome file a deck names.
     *
     * @throws ChromeNotFound
     * @throws ChromeTooLarge
     */
    public function read(string $written): string
    {
        if (PathScope::isUrl($written)) {
            throw ChromeNotFound::aUrl($written);
        }

        $joined = str_starts_with($written, '/') ? $written : rtrim($this->directory, '/') . '/' . $written;

        if (!is_file($joined)) {
            throw ChromeNotFound::at($joined);
        }

        // `realpath()` rather than the path as written, because `..` and a symlink both walk
        // out of a directory that a prefix test says they are inside.
        $resolved = PathScope::canonical($joined);

        if ($resolved === null || !$this->files->holds($resolved)) {
            throw ChromeNotFound::outside($written, $resolved ?? $joined, $this->files->roots());
        }

        // The read only happens where the file can be read: `file_get_contents()` warns
        // when it cannot, and a warning is not a failure anybody can act on.
        //
        // With a length, so that a file over the ceiling is abandoned rather than read: the
        // size a file reports is the size it has now, and a file that grows between the
        // measurement and the read is a file this library does not have room for.
        $length = max(0, $this->maxBytes) + 1;

        $markdown = is_readable($resolved)
            ? file_get_contents($resolved, false, null, 0, $length)
            : false;

        if ($markdown === false) {
            throw ChromeNotFound::at($resolved);
        }

        if (strlen($markdown) > $this->maxBytes) {
            throw ChromeTooLarge::of($written, $this->size($resolved), $this->maxBytes);
        }

        return $markdown;
    }

    /**
     * The size of the file, as the filesystem reports it.
     *
     * Asked for when the file is over the ceiling, so that the message says how far over it
     * is rather than how many bytes were read before the read was abandoned.
     */
    private function size(string $resolved): int
    {
        $size = filesize($resolved);

        return $size === false ? $this->maxBytes + 1 : $size;
    }
}
