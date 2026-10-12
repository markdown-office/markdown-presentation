<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A marker this library cannot act on.
 *
 * A step this renderer drops would leave a deck whose slides do not say what the author
 * wrote, so every way of getting it wrong — a number that goes backwards, a marker whose
 * argument is not a number, a region nothing closes — are refused with the form that would
 * have worked. So is a number past the ceiling on one slide's click chain, because a step is
 * a position in a build-up and a position in the billions is not one anybody can mean.
 *
 * A `::column` is here rather than under {@see UnsupportedColumn} because an argument is a
 * marker that is not one: the marker itself is refused, on the grounds that a column cannot be
 * named, where a well-formed marker the slide's layout cannot offer is the layout's failure.
 *
 * Every marker a refusal quotes is quoted the way the deck wrote it, and the parser hands the
 * line over for that rather than the spelling its patterns can be rebuilt from: all three are
 * read in either case, so `::Column=2` and `::column=2` are the same marker and only one of
 * them is a line in the author's file. {@see \MarkdownPresentation\Render\BlockParser::spelling()}
 * is what takes the whitespace and the control characters out of that quote.
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

    /**
     * A step marker's argument that is not a number.
     *
     * @param string $argument The argument as written, with the control characters a deck can
     *                          put on that line taken out of it: a refusal is read in a
     *                          terminal, and one carrying an escape sequence or a carriage
     *                          return repaints the line it is printed on.
     */
    public static function notANumber(int $slide, string $argument): self
    {
        return new self(sprintf(
            'Slide %d has a step "%s", which is not a number. Write "::click" for the next step, '
            . 'or "::click=2" for a named one.',
            $slide,
            $argument,
        ));
    }

    /**
     * A marker that names a step and nothing.
     *
     * @param string $marker The marker as the deck wrote it, which is not always `::click=`:
     *                       every marker here is read in either case and in three colons.
     */
    public static function empty(int $slide, string $marker): self
    {
        return new self(sprintf(
            'Slide %d has a "%s" step marker with no number. Write "::click" for the next step.',
            $slide,
            $marker,
        ));
    }

    /**
     * A `::column` carrying an argument.
     *
     * Refused rather than drawn as the text of a paragraph: `::column=2` on a slide is a
     * marker somebody thought they had read, and two colons on their own put the mistake on
     * the slide rather than in front of the author who wrote it.
     *
     * @param string $marker The marker as the deck wrote it, quoted whole rather than put back
     *                        together from its argument: `COLUMN` matches a capital `::Column`
     *                        as readily as a lower-case one, so the capture cannot say which of
     *                        the two is the line in the author's file.
     */
    public static function argument(int $slide, string $marker): self
    {
        return new self(sprintf(
            'Slide %d has a "%s", which is not a column marker: the marker takes no '
            . 'argument. Write "::column" on a line of its own to move the blocks after it into '
            . 'the next column.',
            $slide,
            $marker,
        ));
    }

    /**
     * A `:::` that closes nothing.
     *
     * Refused rather than drawn as the text of a paragraph: three colons on a slide read as
     * a marker, and a deck that meant a region and wrote only the end of one has said which
     * of the two it meant by writing the mark up.
     *
     * The line is not handed in, and `CLOSES_REGION` is why: it admits three colons and
     * nothing but whitespace, so every line it matches trims to the one spelling quoted here.
     * There is nothing a caller could add that would make it another marker.
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
     *
     * @param string $opener The opener as the deck wrote it: `:::Click=3` and `:::click` are
     *                        both refused here, and naming the second is naming a line its
     *                        author may not have written.
     */
    public static function unclosed(int $slide, string $opener): self
    {
        return new self(sprintf(
            'Slide %d opens a "%s" region that nothing closes. A region ends at a ":::" on a line of its own.',
            $slide,
            $opener,
        ));
    }
}
