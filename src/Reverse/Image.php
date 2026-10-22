<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

/**
 * A picture read out of a deck, and where it is going.
 *
 * The bytes travel with the picture rather than a path, because the path is
 * decided when the file is written out and the writer is what writes it.
 */
final class Image
{
    public function __construct(
        public readonly string $alt,
        public readonly string $target,
        public readonly string $contents,
    ) {
    }
}
