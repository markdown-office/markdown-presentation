<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A slide that carries more shapes than one may.
 *
 * The ceiling is {@see \MarkdownPresentation\Render\Furniture::MAX_SHAPES}, and the number
 * is in the message because the fix is a number: only the deck's author can say which of
 * the shapes on the slide was meant to be one of the others.
 */
final class TooManyShapes extends InvalidInput
{
    public static function on(int $slide, int $shapes, int $maximum): self
    {
        return new self(sprintf(
            'Slide %d carries %d shapes, and a slide may carry at most %d. Draw fewer shapes on it.',
            $slide,
            $shapes,
            $maximum,
        ));
    }
}
