<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A `::click` marker this library cannot act on.
 *
 * A step this renderer drops would leave a deck whose slides do not say what the author
 * wrote, so every way of getting it wrong — a number that goes backwards, a marker whose
 * argument is not a number, a region nothing closes — are refused with the form that would
 * have worked. So is a number past the ceiling on one slide's click chain, because a step is
 * a position in a build-up and a position in the billions is not one anybody can mean.
 */
final class UnknownMarker extends InvalidInput
{
    public static function backwards(int $slide, int $number, int $previous): self
    {
        return new self(sprintf(
            'Slide %d has a step %d after step %d. Steps run forwards: write each step number once, in order.',
            $slide,
            $number,
            $previous,
        ));
    }

    /**
     * @param int $maximum The most steps one slide may name, which the message repeats:
     *                     a refusal that does not say what would have worked leaves the
     *                     author to guess the number rather than to look it up.
     */
    public static function tooLarge(int $slide, string $argument, int $maximum): self
    {
        return new self(sprintf(
            'Slide %d has a step "%s", which is past the %d steps one slide may build up in. '
            . 'Write "::click" for the next step, or name one up to %d.',
            $slide,
            $argument,
            $maximum,
            $maximum,
        ));
    }

    public static function notANumber(int $slide, string $argument): self
    {
        return new self(sprintf(
            'Slide %d has a step "%s", which is not a number. Write "::click" for the next step, '
            . 'or "::click=2" for a named one.',
            $slide,
            $argument,
        ));
    }

    public static function empty(int $slide): self
    {
        return new self(sprintf(
            'Slide %d has a "::click=" step marker with no number. Write "::click" for the next step.',
            $slide,
        ));
    }

    /**
     * A `:::` that closes nothing.
     *
     * Refused rather than drawn as the text of a paragraph: three colons on a slide read as
     * a marker, and a deck that meant a region and wrote only the end of one has said which
     * of the two it meant by writing the mark up.
     */
    public static function strayCloser(int $slide): self
    {
        return new self(sprintf(
            'Slide %d has a ":::" that closes nothing. A region opens at ":::click" and closes at a '
            . '":::" on a line of its own; a step is "::click".',
            $slide,
        ));
    }

    /**
     * A `:::click` region nothing closes.
     *
     * Refused rather than read to the end of the slide, because everything after it would
     * then be one step and a deck that meant three would be a deck of one.
     */
    public static function unclosed(int $slide): self
    {
        return new self(sprintf(
            'Slide %d opens a ":::click" region that nothing closes. A region ends at a ":::" on a line of its own.',
            $slide,
        ));
    }
}
