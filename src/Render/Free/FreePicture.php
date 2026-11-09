<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownPresentation\Render\Block\Image;

/**
 * A picture the deck placed itself rather than one a column drew.
 *
 * The {@see Image} is the same block a picture in the flow is, so the conversion, the path
 * scope, the description a screen reader reads and the refusal when the file is not there are
 * all one set of rules rather than two: `::image{src="logo.png"}` and `![Logo](logo.png)` find
 * the same file through the same {@see \MarkdownPresentation\Filesystem\PathScope}, and a
 * picture outside it fails the same way.
 *
 * The kind is the picture's own and is not asked for: a free shape that is a picture is
 * {@see ShapeKind::Image}, and the ten ink shapes are the ones that are asked for.
 */
final class FreePicture extends FreeShape
{
    /**
     * @param string $fit {@see self::CONTAIN} or {@see self::STRETCH}; see
     *                     {@see \MarkdownPresentation\Render\Free\Pictures::fit()} for what a
     *                     third would cost.
     * @param Mask|null $mask The shape the picture is drawn in, or null where it is drawn in
     *                         the rectangle a picture is drawn in.
     */
    private function __construct(
        Placement $placement,
        int $step,
        Paint $paint,
        public readonly Image $image,
        public readonly string $fit,
        public readonly ?Mask $mask,
        ?Shadow $shadow,
    ) {
        parent::__construct(ShapeKind::Image, $placement, $step, $paint, '', $shadow);
    }

    public static function at(
        Placement $placement,
        int $step,
        Paint $paint,
        Image $image,
        string $fit,
        ?Mask $mask,
        ?Shadow $shadow,
    ): self {
        return new self($placement, $step, $paint, $image, $fit, $mask, $shadow);
    }
}
