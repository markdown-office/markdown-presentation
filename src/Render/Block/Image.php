<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

use MarkdownPresentation\Render\ConvertedImage;

/**
 * An image a slide draws, with the path the deck wrote rather than the one it resolved to.
 *
 * Both are kept: the shape carries the resolved path so the file it embeds can be found,
 * and the shape's description carries the written one so the deck's own spelling survives
 * into the slide XML.
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