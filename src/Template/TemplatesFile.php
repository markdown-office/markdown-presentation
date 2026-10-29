<?php

declare(strict_types=1);

namespace MarkdownPresentation\Template;

use MarkdownPresentation\Exception\TemplatesFileNotFound;
use MarkdownPresentation\Exception\TemplatesFileTooLarge;
use MarkdownPresentation\Files\FileFinder;
use MarkdownPresentation\Filesystem\PathScope;

/**
 * The templates file a deck names, read from beside the deck.
 *
 * A path a deck writes, so it is resolved and held to the directories this render may
 * read — the same rule an image path has to pass, because both are paths a deck wrote and
 * neither is worth a way out of the directory the deck was given.
 *
 * **The templates file comes from the source the deck's files come from.** A source that
 * holds it as a file answers a path, and the path is held to the scope as any other path
 * is. A source that holds the contents answers no path, and the contents are taken on the
 * caller's word: there is no path to hold to the scope, and a caller that brings a source
 * of its own vouches for what is in it.
 *
 * The ceiling is where it was: with the read. A file over it is abandoned rather than
 * read whole, which is what keeps the memory a deck's frontmatter can ask for bounded.
 */
final class TemplatesFile
{
    /**
     * The largest a templates file may be.
     *
     * A templates file is the furniture of a deck — a handful of shapes for each of its
     * templates — and a megabyte of it is far past any file of furniture. It is the one file
     * this library reads whole, so this is what bounds the memory a deck's frontmatter can
     * ask for.
     */
    public const MAX_BYTES = 1024 * 1024;

    /**
     * @param string $directory The directory the deck is in, which is where a relative path
     *                          in the deck is written from.
     * @param FileFinder|null $files Where the deck's files come from. Null for the disk,
     *                               which is where a templates file was found before there
     *                               was a source.
     */
    public function __construct(
        private readonly string $directory,
        private readonly PathScope $scope,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly ?FileFinder $files = null,
    ) {
    }

    /**
     * The Markdown of the templates file a deck names.
     *
     * @throws TemplatesFileNotFound
     * @throws TemplatesFileTooLarge
     */
    public function read(string $written): string
    {
        if (PathScope::isUrl($written)) {
            throw TemplatesFileNotFound::aUrl($written);
        }

        $fromSource = $this->fromSource($written);

        if ($fromSource !== null) {
            return $fromSource;
        }

        $joined = str_starts_with($written, '/') ? $written : rtrim($this->directory, '/') . '/' . $written;

        if (!is_file($joined)) {
            throw TemplatesFileNotFound::at($joined);
        }

        // `realpath()` rather than the path as written, because `..` and a symlink both walk
        // out of a directory that a prefix test says they are inside.
        $resolved = PathScope::canonical($joined);

        if ($resolved === null || !$this->scope->holds($resolved)) {
            throw TemplatesFileNotFound::outside($written, $resolved ?? $joined, $this->scope->roots());
        }

        return $this->readFile($written, $resolved);
    }

    /**
     * The templates file in the source the deck's files come from, or null when it has none.
     *
     * A path the source answers is held to the scope, exactly as a path on disk is: a
     * `templates-file:` is written by the deck, and what is allowed is where it lands.
     */
    private function fromSource(string $written): ?string
    {
        if ($this->files === null) {
            return null;
        }

        $path = $this->files->path($written, 'document');

        if ($path !== null) {
            if (!is_file($path)) {
                throw TemplatesFileNotFound::at($path);
            }

            $resolved = PathScope::canonical($path);

            if ($resolved === null || !$this->scope->holds($resolved)) {
                throw TemplatesFileNotFound::outside($written, $resolved ?? $path, $this->scope->roots());
            }

            return $this->readFile($written, $resolved);
        }

        $markdown = $this->files->read($written, 'document');

        if ($markdown === null) {
            return null;
        }

        if (strlen($markdown) > $this->maxBytes) {
            throw TemplatesFileTooLarge::of($written, strlen($markdown), $this->maxBytes);
        }

        return $markdown;
    }

    /**
     * A file's Markdown, up to the ceiling.
     *
     * The read only happens where the file can be read: `file_get_contents()` warns when
     * it cannot, and a warning is not a failure anybody can act on. And it happens with a
     * length, so that a file over the ceiling is abandoned rather than read whole.
     *
     * @throws TemplatesFileNotFound
     * @throws TemplatesFileTooLarge
     */
    private function readFile(string $written, string $resolved): string
    {
        $length = max(0, $this->maxBytes) + 1;

        $markdown = is_readable($resolved)
            ? file_get_contents($resolved, false, null, 0, $length)
            : false;

        if ($markdown === false) {
            throw TemplatesFileNotFound::at($resolved);
        }

        if (strlen($markdown) > $this->maxBytes) {
            throw TemplatesFileTooLarge::of($written, $this->size($resolved, strlen($markdown)), $this->maxBytes);
        }

        return $markdown;
    }

    /**
     * The size of the file, as the filesystem reports it.
     *
     * The bytes read are its floor: a file that grew between the two is at least that big.
     */
    private function size(string $resolved, int $read): int
    {
        $size = filesize($resolved);

        return max($size === false ? 0 : $size, $read);
    }
}
