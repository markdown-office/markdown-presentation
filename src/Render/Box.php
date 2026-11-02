<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

/**
 * A rectangle on the slide, in the pixels PHPPresentation works in.
 *
 * 9144000 × 5143500 EMU — the 16:9 slide `DocumentLayout::LAYOUT_SCREEN_16X9` gives — is
 * 960 × 540 of these, because a pixel is 9525 EMU at the 96 dpi the library converts at.
 * Every number in {@see SlideGeometry} is therefore readable as a fraction of a slide.
 *
 * `$textAnchor` is where the text sits in the rectangle — `t`, `ctr` or `b` — and it is
 * null for a box that says nothing about it, which is every box but one read out of a
 * template's placeholder. It is the text's anchor and not the box's: the anchor of a free
 * shape's own placement is a different thing with a different vocabulary, and this one is
 * the `anchor` attribute of an `a:bodyPr`.
*/
final class Box
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly ?string $textAnchor = null,
    ) {
    }
}
