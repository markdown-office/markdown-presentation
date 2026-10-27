<?php

declare(strict_types=1);

namespace MarkdownPresentation\Template;

use MarkdownPresentation\Exception\ChromeNotFound;
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
     * @param string $directory The directory the deck is in, which is where a relative path
     *                          in the deck is written from.
     */
    public function __construct(
        private readonly string $directory,
        private readonly PathScope $files,
    ) {
    }

    /**
     * The Markdown of the chrome file a deck names.
     *
     * @throws ChromeNotFound
     */
    public function read(string $written): string
    {
        if (PathScope::isUrl($written)) {
            throw ChromeNotFound::aUrl($written);
        }

        $joined = str_starts_with($written, '/') ? $written : rtrim($this->directory, '/') . '/' . $written;

        if (!is_file($joined)) {
            throw ChromeNotFound::at($written, $joined);
        }

        // `realpath()` rather than the path as written, because `..` and a symlink both walk
        // out of a directory that a prefix test says they are inside.
        $resolved = PathScope::canonical($joined);

        if ($resolved === null || !$this->files->holds($resolved)) {
            throw ChromeNotFound::outside($written, $resolved ?? $joined, $this->files->roots());
        }

        // The read only happens where the file can be read: `file_get_contents()` warns
        // when it cannot, and a warning is not a failure anybody can act on.
        $markdown = is_readable($resolved) ? file_get_contents($resolved) : false;

        if ($markdown === false) {
            throw ChromeNotFound::at($written, $resolved);
        }

        return $markdown;
    }
}
