<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A `v-click` marker this library cannot act on.
 *
 * A step this renderer drops would leave a deck whose slides do not say what the author
 * wrote, so every way of getting it wrong — a number that goes backwards, a marker
 * whose argument is not a number — are refused with the form that would have worked. So
 * is a number larger than one slide could carry, because a step is a slide.
*/
final class UnknownMarker extends InvalidInput
{
    public static function backwards(int $slide, int $number, int $previous): self
    {
        return new self(sprintf(
            'Slide %d has a v-click step %d after step %d. Steps run forwards: write each step number once, in order.',
            $slide,
            $number,
            $previous,
        ));
    }

    /**
     * @param int $maximum The most steps one slide may carry, which the message repeats:
     *                     a refusal that does not say what would have worked leaves the
     *                     author to guess the number rather than to look it up.
     */
    public static function tooLarge(int $slide, string $argument, int $maximum): self
    {
        return new self(sprintf(
            'Slide %d has a v-click step "%s", which is past the %d steps one slide may carry: every step is '
            . 'written as a slide of its own. Write "<!-- v-click -->" for the next step, or name one up to %d.',
            $slide,
            $argument,
            $maximum,
            $maximum,
        ));
    }

    public static function notANumber(int $slide, string $argument): self
    {
        return new self(sprintf(
            'Slide %d has a v-click step "%s", which is not a number. Write "<!-- v-click -->" for the next step, '
            . 'or "<!-- v-click=2 -->" for a named one.',
            $slide,
            $argument,
        ));
    }

    public static function empty(int $slide): self
    {
        return new self(sprintf(
            'Slide %d has a "<!-- v-click= -->" step marker with no number. Write "<!-- v-click -->" for the next step.',
            $slide,
        ));
    }
}