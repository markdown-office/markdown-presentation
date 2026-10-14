<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

/**
 * One of the nine ink-only shapes, drawn as a PHPPresentation autoshape.
 *
 * Its own class rather than nothing at all because it is written differently: an autoshape's
 * stroke is an {@see \PhpOffice\PhpPresentation\Style\Outline} of one colour and one width, so
 * {@see \MarkdownPresentation\Render\ShapeWriter} writes the paint through `getOutline()` and
 * not through the {@see \PhpOffice\PhpPresentation\Style\Border} a text box and a picture take.
 */
final class FreeAutoShape extends FreeShape
{
    private function __construct(ShapeKind $kind, Placement $placement, int $step, Paint $paint)
    {
        parent::__construct($kind, $placement, $step, $paint);
    }

    public static function at(ShapeKind $kind, Placement $placement, int $step, Paint $paint): self
    {
        return new self($kind, $placement, $step, $paint);
    }
}
