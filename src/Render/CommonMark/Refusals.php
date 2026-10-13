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
     * @throws UnknownDirective
     */
    public static function guard(Node $node, SlideState $state): void
    {
        self::comments($node, $state);
        self::elements($node, $state);
        self::notes($node, $state);
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
