<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownPresentation\Exception\UnknownDirective;

/**
 * The three things a slide cannot carry and this library is not told about by the grammar:
 * an HTML comment, a `v-*` element and a `::note` nothing closed.
 *
 * A slide is a Slidev deck without the parts a `.pptx` cannot draw, so a directive, an
 * element or a comment in the body of one is a deck written for another tool. Every one of
 * them used to be painted onto the slide as the literal text of the directive, which is the
 * worst of both outcomes: the deck renders, and what it renders is the mistake.
 *
 * Each refusal is looked for over the whole slide before any of it is read, in the order the
 * messages name them, so that a comment on the last line is the mistake reported whatever
 * is wrong above it.
 */
final class Refusals
{
    /**
     * An HTML comment: the whole of it where it is closed, and the rest of the line where it
     * is not.
     */
    private const COMMENT = '/<!--.*?-->|<!--.*?(?:\n|\z)/s';

    /**
     * An HTML tag: capture 1 is its name, capture 2 its attributes.
     *
     * Every tag and not only the ones refused, so that telling a directive from ordinary
     * text is a question about the tag rather than about what was found.
     */
    private const TAG = '~</?([A-Za-z][A-Za-z0-9:._-]*)((?:\s[^<>]*)?)/?>~';

    /**
     * A `v-*` directive in a tag's attributes, which is what `v-if` is.
     */
    private const ATTRIBUTE = '/\sv-[A-Za-z]/';

    /**
     * The note a slide carries nowhere: `::note` and nothing else on its line.
     *
     * The exact spelling rather than the name alone, because a directive written `::note`
     * with a label on it is not the note this library means and a deck that means one writes
     * it as {@see \MarkdownPresentation\Document\DeckParser} reads it.
     */
    private const NOTE = 'note';

    /**
     * The leaf directives a slide draws.
     *
     * A note is not among them because a note is not a leaf: it is `::note`, its text, and a
     * closing `::`. {@see self::notes()} has already taken a bare one off the content or
     * refused it as unclosed, so a leaf still carrying the name at this point is one written
     * with a label or attributes on it, which is not the note this library means.
     * @var list<string>
     */
    public const LEAF = ['click', 'column'];

    /**
     * @throws UnknownDirective
     */
    public static function guard(Node $node, SlideState $state): void
    {
        self::comments($node, $state);
        self::elements($node, $state);
        self::notes($node, $state);
        self::leaves($node, $state);
    }

    /**
     * A line as a refusal quotes it: one line, however many it was written in, and with the
     * runs of spaces in it reduced to one.
     *
     * One home for it, because a message and the string inside it have to agree and the two
     * are reached from different walks.
     */
    public static function oneLine(string $written): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $written));
    }

    /**
     * @throws UnknownDirective
     */
    private static function comments(Node $node, SlideState $state): void
    {
        foreach ($node->children() as $child) {
            if (
                ($child instanceof HtmlBlock || $child instanceof HtmlInline)
                && ($found = self::comment($child->getLiteral())) !== null
            ) {
                throw UnknownDirective::comment($state->slide, $found);
            }

            // A comment nothing closes is not a node: the parser cannot tell where it ends,
            // so it stays in the text — which is the one case where a comment would be drawn
            // as prose, and the prose would be `<!--`.
            if (
                $child instanceof Text
                && str_contains($child->getLiteral(), '<!--')
                && ($found = self::comment($child->getLiteral())) !== null
            ) {
                throw UnknownDirective::comment($state->slide, $found);
            }

            self::comments($child, $state);
        }
    }

    /**
     * @throws UnknownDirective
     */
    private static function elements(Node $node, SlideState $state): void
    {
        foreach ($node->children() as $child) {
            if ($child instanceof HtmlBlock || $child instanceof HtmlInline) {
                preg_match_all(self::TAG, $child->getLiteral(), $tags, PREG_SET_ORDER);

                foreach ($tags as $tag) {
                    // The name or an attribute: `<v-click>` and `<div v-if="x">` are the
                    // same mistake, and the second is written like ordinary HTML.
                    if (str_starts_with(strtolower($tag[1]), 'v-') || preg_match(self::ATTRIBUTE, $tag[2]) === 1) {
                        throw UnknownDirective::element($state->slide, $tag[0]);
                    }
                }
            }

            self::elements($child, $state);
        }
    }

    /**
     * A `::note` whose closing `::` is not on the slide: a note has been taken off the
     * content by the time it gets here, so the one that arrives is one nothing closed.
     *
     * @throws UnknownDirective
     */
    private static function notes(Node $node, SlideState $state): void
    {
        foreach ($node->children() as $child) {
            if (
                $child instanceof DirectiveBlock
                && !$child->isContainer()
                && $child->getName() !== null
                && strtolower($child->getName()) === self::NOTE
                && $child->getLabel() === null
                && count($child->getAttributes()) === 0
            ) {
                throw UnknownDirective::unclosedNote($state->slide);
            }

            self::notes($child, $state);
        }
    }

    /**
     * A leaf directive under a name this library does not draw.
     *
     * The other three refusals each have one spelling to look for, and a leaf has a name
     * rather than a spelling: `::bogus`, `::note[label]`, `::anything=2`. Drawn as text each of
     * them is the outcome {@see UnknownDirective::comment()} exists to prevent — a deck that
     * renders, showing the mistake as prose — reached by the one form the three above never
     * look at.
     *
     * Into a container and not into a quotation, which is where a step marker is read from
     * too. A `:::` region is raw lines in the slide's content, so a directive inside one is a
     * line this library sees; a quote and a list item write their marker in front of every
     * line, so a directive written in one is text inside it and is drawn as text, exactly as
     * `::click` is drawn as text there. Refusing it in the one place and not the other would
     * make this the only directive whose being nested is an error.
     *
     * Last, so that a `::note` keeps the message about the `::` that would close it rather
     * than being told its name is not one this library draws.
     *
     * @throws UnknownDirective
     */
    private static function leaves(Node $node, SlideState $state): void
    {
        foreach ($node->children() as $child) {
            if ($child instanceof DirectiveBlock) {
                $name = $child->getName();

                if (!$child->isContainer() && !in_array(strtolower($name ?? ''), self::LEAF, true)) {
                    throw UnknownDirective::leaf($state->slide, self::oneLine($state->written($child)));
                }
            }

            // A `:::click` region is a {@see Marker} rather than a directive, so it is the
            // other kind of node a directive line can be written inside.
            if ($child instanceof DirectiveBlock || $child instanceof Marker) {
                self::leaves($child, $state);
            }
        }
    }

    /**
     * The first HTML comment in a piece of literal text, as one line, or null where there is
     * none.
     *
     * One line because a comment written across four of them still has to be quotable, and
     * a message that spans lines cannot be matched against the line in the deck it is about.
     */
    private static function comment(string $literal): ?string
    {
        if (preg_match(self::COMMENT, $literal, $match) !== 1) {
            return null;
        }

        return trim((string) preg_replace('/\s+/', ' ', $match[0]));
    }
}
