<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * An image a slide names that is not a file this process can read.
 *
 * The path is resolved against the directory the renderer was given, and the message
 * repeats it: a deck written on one machine and converted on another resolves a relative
 * path against the wrong directory, and the fix is a path or a working directory.
*/
final class ImageNotFound extends InvalidInput
{
    public static function at(int $slide, string $path, string $resolved): self
    {
        return new self(sprintf(
            'Slide %d shows the image "%s", which is not a file. It resolved to "%s". '
            . 'Give an absolute path, or render the deck from the directory the path is relative to.',
            $slide,
            $path,
            $resolved,
        ));
    }
}