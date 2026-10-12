<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A deck that asks for more slides than a presentation is.
 *
 * Not a step: a step is bounded where it is written, by
 * {@see \MarkdownPresentation\Render\BlockParser::MAX_STEP}, and a deck of steps that are
 * each inside it passes every one of those checks and still writes one slide. This is the
 * total, counted before the first slide is built rather than while they are — and unlike
 * when a step was a slide of its own, it is a deck that really is that long rather than a
 * few lines that multiplied.
 *
 * The number is in the message because the fix is a number: only the deck's author can say
 * which of its slides was meant to be one of the others.
 */
final class TooManySlides extends InvalidInput
{
    public static function in(int $slides, int $maximum): self
    {
        return new self(sprintf(
            'This deck asks for %d slides, and one presentation may carry %d. Give the deck fewer '
            . 'slides, or split it.',
            $slides,
            $maximum,
        ));
    }
}
