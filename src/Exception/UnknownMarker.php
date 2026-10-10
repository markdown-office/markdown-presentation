<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A `v-click` marker this library cannot act on.
 *
 * A step this renderer drops would leave a deck whose slides do not say what the author
 * wrote, so both ways of getting it wrong — a number that goes backwards, and a marker
 * whose argument is not a number — are refused with the form that would have worked.
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