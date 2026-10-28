<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A slide that carries more shapes than one may.
 *
 * The shapes of a template are multiplied by the slides that draw them: the chrome of a
 * template is read once and drawn on every slide that names it, so a template of two hundred
 * shapes on sixty slides is twelve thousand shapes and ten megabytes of slide XML. The
 * slide count is bounded by
 * {@see \MarkdownPresentation\Render\PresentationRenderer::MAX_SLIDES}, and this is the
 * other half of that bound.
 *
 * The number is in the message because the fix is a number: only the deck's author can say
 * which of the shapes on the slide was meant to be one of the others.
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
