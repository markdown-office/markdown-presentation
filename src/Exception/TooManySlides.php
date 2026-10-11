<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A deck that asks for more slides than a presentation is.
 *
 * Not a step: a step is bounded where it is written, by
 * {@see \MarkdownPresentation\Render\BlockParser::MAX_STEP}, and a deck of steps that are
 * each inside it passes every one of those checks. This is the total, counted before the
 * first slide is built rather than while they are — 32 bytes of Markdown naming one step
 * is a denial of service rather than a deck, and so is forty lines naming forty steps.
 *
 * The number is in the message because the fix is a number: a deck over the ceiling is one
 * whose build-ups are steps where slides were meant, and only its author can say which.
 */
final class TooManySlides extends InvalidInput
{
    public static function in(int $slides, int $maximum): self
    {
        return new self(sprintf(
            'This deck asks for %d slides, and one presentation may carry %d. Every "<!-- v-click -->" is '
            . 'written as a slide of its own: give the deck fewer steps, or split it.',
            $slides,
            $maximum,
        ));
    }
}
