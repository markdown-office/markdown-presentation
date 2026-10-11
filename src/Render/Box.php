<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

/**
 * A rectangle on the slide, in the pixels PHPPresentation works in.
 *
 * 9144000 × 5143500 EMU — the 16:9 slide `DocumentLayout::LAYOUT_SCREEN_16X9` gives — is
 * 960 × 540 of these, because a pixel is 9525 EMU at the 96 dpi the library converts at.
 * Every number in {@see SlideGeometry} is therefore readable as a fraction of a slide.
*/
final class Box
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
