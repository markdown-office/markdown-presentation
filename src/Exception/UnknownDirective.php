<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A directive this library does not act on, in the frontmatter or in the body of a slide.
 *
 * A directive nobody reads is not a setting that happens to have no effect yet — it is a
 * setting that will be ignored forever while the deck looks rendered. Slidev accepts dozens of
 * them, so refusing is a real cost, and the message is the whole of what makes it
 * affordable: it names every directive that does something, so the author edits the front
 * matter rather than re-reading the page to find out which half of the library is missing.
 *
 * The body of a slide is closed on the same terms. Slidev's in-slide vocabulary is the step
 * comment, the `v-*` elements and the `:::` container fences, and any of the other two lands
 * where a person would read prose. Every one of them used to be painted onto the slide as the
 * literal text of the directive, which is the worst of both outcomes: the deck renders, and
 * what it renders is the mistake. A plain HTML tag is not in that vocabulary and is still
 * text — `<b>` is not a directive, and this library has no opinion about what a deck says
 * about HTML.
*/
final class UnknownDirective extends InvalidInput
{
    /**
     * @param list<string> $directives
    */
    public static function named(int $slide, string $key, array $directives): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d names "%s", which is not a directive. The directives are: %s.',
            $slide,
            $key,
            implode(', ', $directives),
        ));
    }

    /**
     * A deck-wide directive written on a later slide. It is refused rather than read from
     * slide 1 as well, because two slides that both name a theme is a deck that renders
     * one of them and says nothing about the other.
    */
    public static function misplaced(int $slide, string $key): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d names "%s", which is a deck directive and belongs in the frontmatter of slide 1.',
            $slide,
            $key,
        ));
    }

    /**
     * An HTML comment in a slide's content that is neither a step nor its last line.
     *
     * A step is honoured on a line of its own, so a marker carrying text is this rather than
     * a marker: `<!-- v-click -->` after a word is a deck asking for a step nobody can say
     * where it goes, and refusing is the whole of the difference from drawing the comment on
     * the slide.
     */
    public static function comment(int $slide, string $comment): self
    {
        return new self(sprintf(
            'Slide %d has the HTML comment %s, which is neither a step nor a note. A step is '
            . '"<!-- v-click -->" on a line of its own, and a note is a comment after the last '
            . 'line of a slide. To show a comment rather than run it, write it in a fenced code block.',
            $slide,
            $comment,
        ));
    }

    /**
     * A `v-*` element, or any element carrying a `v-*` directive.
     *
     * Named as it was written, because `<v-click>` and `<div v-if="x">` are two spellings of
     * one mistake and the second reads as ordinary HTML right up until it does not.
     */
    public static function element(int $slide, string $tag): self
    {
        return new self(sprintf(
            'Slide %d has the element "%s", which is not something a slide draws. A step is the '
            . 'comment "<!-- v-click -->" on a line of its own, and there is no other element.',
            $slide,
            $tag,
        ));
    }

    /**
     * A `:::` container fence, which Slidev nests content in.
     */
    public static function container(int $slide, string $fence): self
    {
        return new self(sprintf(
            'Slide %d opens the container "%s", which is not something a slide draws. Divide a '
            . 'deck with a "---" line, write a section as a heading, and write a note as a quote: "> ".',
            $slide,
            $fence,
        ));
    }
}
