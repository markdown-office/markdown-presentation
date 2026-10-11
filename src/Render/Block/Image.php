<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

use MarkdownPresentation\Render\ConvertedImage;

/**
 * An image a slide draws, with the path the deck wrote rather than the one it resolved to.
 *
 * Both are kept: the shape carries the resolved path so the file it embeds can be found,
 * and the deck's own spelling survives in `$written` as the description of a picture the
 * deck gave no alt text to.
 *
 * `$alt` is the deck's `![…]` and is what the shape's description carries where there is
 * one: `descr` is what a screen reader reads out, and a file path read aloud to a room is
 * not a description of the picture.
 *
 * `$converted` is null for an image the package carries as it is, and holds the bytes and
 * the size to draw it at otherwise — see {@see ConvertedImage} for why both are needed.
 */
final class Image implements Block
{
    public function __construct(
        public readonly string $written,
        public readonly string $resolved,
        public readonly ?ConvertedImage $converted,
        public readonly string $alt,
        public readonly int $step,
    ) {
    }

    public function name(): string
    {
        return 'image';
    }

    public function step(): int
    {
        return $this->step;
    }
}
