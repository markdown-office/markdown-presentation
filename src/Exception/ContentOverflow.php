<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A slide whose content is taller than the layout gives it.
 *
 * `InvalidInput` rather than a failure of this library's own side, because the deck's author
 * is the one who can act on it: the block has to get shorter, or go on a slide of its own.
 *
 * Only raised when the renderer was asked to be strict. Otherwise the block is clamped to
 * the room left and recorded by
 * {@see \MarkdownPresentation\Render\PresentationRenderer::overflows()}, which is the whole
 * of the trade: a file either way, and a list of what did not fit.
 */
final class ContentOverflow extends InvalidInput
{
    public static function on(int $slide, int $step, int $column, string $block, int $wanted, int $available): self
    {
        return new self(sprintf(
            'Slide %d, step %d, column %d: its %s needs %dpx of height and %dpx are left. '
            . 'Shorten it, put it on a slide of its own, or render without the strict check to have it clamped.',
            $slide,
            $step,
            $column + 1,
            $block,
            $wanted,
            $available,
        ));
    }
}
