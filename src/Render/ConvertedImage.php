<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

/**
 * An image that had to be re-encoded to go into the package, and the size it had before.
 *
 * The size travels with the bytes because the adapter that carries bytes measures
 * nothing: `Drawing\File` fills a shape's width and height from the file it was pointed
 * at, and `Drawing\Base64` has no file to read. Without the size here, a picture that
 * was converted is stretched to its box where one that was embedded untouched is fitted
 * to it, and the two are the same shape on the same slide.
 */
final class ConvertedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}