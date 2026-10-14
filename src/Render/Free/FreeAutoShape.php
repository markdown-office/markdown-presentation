<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

/**
 * One of the ten ink-only shapes, drawn as a PHPPresentation autoshape.
 *
 * Its own class because it is written differently from the other two: an autoshape's stroke is
 * an {@see \PhpOffice\PhpPresentation\Style\Outline} of one colour and one width, and it is the
 * only one of the three with a text body of its own — which is what {@see FreeShape::$label}
 * is written into.
 */
final class FreeAutoShape extends FreeShape
{
    public static function at(ShapeKind $kind, Placement $placement, int $step, Paint $paint, string $label): self
    {
        return new self($kind, $placement, $step, $paint, $label);
    }
}
