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
 */
final class FreePicture extends FreeShape
{
    /**
     * @param string $fit {@see self::CONTAIN} or {@see self::STRETCH}; see
     *                     {@see \MarkdownPresentation\Render\Free\Pictures::fit()} for what a
     *                     third would cost.
     */
    private function __construct(
        ShapeKind $kind,
        Placement $placement,
        int $step,
        Paint $paint,
        public readonly Image $image,
        public readonly string $fit,
        ?Shadow $shadow,
    ) {
        parent::__construct($kind, $placement, $step, $paint, '', $shadow);
    }

    public static function at(
        ShapeKind $kind,
        Placement $placement,
        int $step,
        Paint $paint,
        Image $image,
        string $fit,
        ?Shadow $shadow,
    ): self {
        return new self($kind, $placement, $step, $paint, $image, $fit, $shadow);
    }
}
