<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownPresentation\Render\TextFrame;

/**
 * Markdown the deck placed itself, in a box the deck sized.
 *
 * One shape, which is the whole of the reason the body is only the blocks a {@see TextFrame}
 * holds. A table, a code block, a picture and a rule are each a shape of their own, and
 * {@see \MarkdownPresentation\Render\PresentationRenderer} counts the shapes it writes by
 * position — one directive, one shape — so a body that could produce two would put the click
 * chain's shape ids and the ones in `ppt/slides/slideN.xml` out of step with each other.
 * Those blocks are refused by name instead, and the message says which.
 */
final class FreeText extends FreeShape
{
    private function __construct(
        ShapeKind $kind,
        Placement $placement,
        int $step,
        Paint $paint,
        public readonly TextFrame $frame,
        public readonly TextStyle $style,
    ) {
        parent::__construct($kind, $placement, $step, $paint);
    }

    public static function at(
        ShapeKind $kind,
        Placement $placement,
        int $step,
        Paint $paint,
        TextFrame $frame,
        TextStyle $style,
    ): self {
        return new self($kind, $placement, $step, $paint, $frame, $style);
    }
}
