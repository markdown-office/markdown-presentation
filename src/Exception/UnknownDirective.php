<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

use MarkdownPresentation\Render\Free\FreeShapes;

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
 * comment, the `v-*` elements, the `:::` container fences and a leaf directive under any name
 * but the three this format reads, and all four land where a person would read prose. This
 * format has replaced the step comment with `::click`, so a comment here is a deck written
 * for a tool whose step marker this one does not read.
 * Every one of them used to be painted onto the slide as the
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
     * An HTML comment in a slide's content.
     *
     * This format has no HTML in it, so a comment is a deck written for another tool: a
     * step is `::click` and a note is `::note`, and both have been taken by the time the
     * content reaches here. Drawing one as text is the failure this message exists to stop,
     * because a slide carrying a Slidev marker reads as a rendered slide.
     */
    public static function comment(int $slide, string $comment): self
    {
        return new self(sprintf(
            'Slide %d has the HTML comment %s, which is not something a slide carries. A step is '
            . '"::click" on a line of its own and a note is "::note", its text, and a closing "::". '
            . 'To show HTML rather than run it, write it in a fenced code block.',
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
            'Slide %d has the element "%s", which is not something a slide draws. A step is '
            . '"::click" on a line of its own, and there is no other element.',
            $slide,
            $tag,
        ));
    }

    /**
 * A `:::` fence naming something this format does not draw.
 *
 * A `:::` line with no name in it — `:::`, `::: {.panel}` — never reaches the generic
 * directives extension at all, because {@see \MarkdownPresentation\Render\CommonMark\MarkerStartParser}
 * claims every three-colon line it does not stand aside for, so this is where it is refused.
 */
    public static function container(int $slide, string $fence): self
    {
        return new self(sprintf(
            'Slide %d opens the container "%s", which is not something a slide draws. Three colons '
            . 'open a step region as ":::click" and close it as ":::"; a placed text block is ":::content" '
            . 'with its Markdown between the fences; divide a deck with a "---" line, write a section as a '
            . 'heading, and write a note as a quote: "> ".',
            $slide,
            $fence,
        ));
    }

    /**
     * A `::note` whose closing `::` is not on the slide.
     *
     * A note is taken off the content by {@see \MarkdownPresentation\Document\DeckParser}
     * before it gets here, so the one reaching the renderer is one nothing closed — the
     * parser refuses the note that was closed and is not the last thing on the slide.
     * Reading the rest of the slide as its note would put text in the notes pane that the
     * deck wrote as prose.
     */
    public static function unclosedNote(int $slide): self
    {
        return new self(sprintf(
            'Slide %d opens a "::note" that nothing closes. A note is "::note" on a line of its own, '
            . 'its text, and a closing "::" on a line of its own.',
            $slide,
        ));
    }

    /**
     * A `::note` that was closed but is not the last thing on the slide.
     *
     * The note was closed and it was in the wrong place, and the message about a closer
     * that is missing sends the author looking for one that is there. The rule is the one
     * the note is read with: the closer is anchored to the end of the slide.
     */
    public static function misplacedNote(int $slide): self
    {
        return new self(sprintf(
            'Slide %d opens a "::note" that is not the last thing on the slide. A note is "::note" on a line of its own, '
            . 'its text, and a closing "::" on a line of its own, and it has to come after all the content on the slide.',
            $slide,
        ));
    }

    /**
     * A `::name` leaf under a name this format does not read.
 *
 * The container fence and the unclosed note each have a message of their own, so this one is
 * only reached by a name that is neither: `::bogus`, `::note[label]`. Drawn as text it is the
 * same failure {@see self::comment()} exists to prevent, reached by the spelling the grammar
 * admits and the other three refusals do not look at.
 *
     * Names the free shapes as well as the markers, because a leaf is far more often a
     * misspelt shape than a Slidev directive and the whole vocabulary fits in a sentence.
     */
    public static function leaf(int $slide, string $directive): self
    {
        return new self(sprintf(
            'Slide %d opens the directive "%s", which is not something a slide draws. The free shapes are: %s. '
            . 'Two colons mark a step as "::click" or a column as "::column" and three open a step region as '
            . '":::click"; a placed text block is ":::content" with its Markdown between the fences.',
            $slide,
            $directive,
            implode(', ', FreeShapes::names()),
        ));
    }
}
