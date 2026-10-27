<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock as LeagueList;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use MarkdownPresentation\Exception\UnsupportedLink;
use MarkdownPresentation\Render\Block\ListBlock;

/**
 * The blocks a {@see \MarkdownPresentation\Render\TextFrame} holds, read off league's tree.
 *
 * A heading, a paragraph, a quote and a list are what a text frame is made of, and two readers
 * need them: {@see BlockTranslator} for a column of type, and {@see \MarkdownPresentation\Render\Free\ShapeReader}
 * for the Markdown between a `:::content`'s fences. A second copy of the list walk would be a
 * second answer to what nesting depth a bullet is at, and the two would disagree the first time
 * either was edited.
 *
 * So this is where "what does this block become" is answered once, for the blocks that have one
 * answer, and the classes that answer it keep only what is specific to them.
 */
final class FrameReader
{
    /**
     * A quoted line: three spaces of indentation, a `>` and one space of it. Whatever is
     * left is the sentence.
     *
     * Written out rather than read off the parser's own quote block because a quote on a slide
     * is the deck's own text with one marker taken off each line — and a nested quote, a list
     * inside a quote and a sentence CommonMark reads as part of one are all the same thing
     * here: words, one level in.
     */
    private const QUOTE = '/\A {0,3}>[ \t]?(.*)\z/';

    public function __construct(
        private readonly InlineTranslator $inline,
    ) {
    }

    /**
     * The items of a list and of every list inside it, at the depth each was nested at.
     *
     * The depth comes off the tree rather than off an indent, which is where a list written
     * `1. a` and continued at the item's own indent agrees with the hand-written walk, and
     * where a list that mixes its markers does not: CommonMark starts a second list there,
     * and that walk keeps one list with a bullet and a number in it.
     *
     * @param list<array{level: int, runs: list<\MarkdownPresentation\Render\Run>, ordered: bool, start: ?int}> $items
     *
     * @return list<array{level: int, runs: list<\MarkdownPresentation\Render\Run>, ordered: bool, start: ?int}>
     *
     * @throws UnsupportedLink
     */
    public function items(LeagueList $node, int $level, array $items, SlideState $state): array
    {
        $data = $node->getListData();
        $ordered = $data->type === LeagueList::TYPE_ORDERED;

        // A numbering that begins at one needs nothing: no `startAt` is written for it at all,
        // and every reader counts on from one. One that begins at any other number has the same
        // `startAt` written on each of its paragraphs — which is what PowerPoint expects, and
        // continues itself — and LibreOffice restarts at each one instead, so `7.` and `8.` read
        // as `7.` and `7.` there. Every item of such a list therefore carries its own number,
        // and nothing is left to a reader to infer.
        $explicit = $ordered && $data->start !== 1;
        $number = $data->start;
        $first = true;

        foreach ($node->children() as $item) {
            // The number the list was opened with, on its first item alone: an item that carries
            // on from the one above it has no number of its own to draw. A numbering that
            // begins above one is the exception, and every one of its items carries its own.
            $start = $first ? $data->start : null;

            if ($explicit) {
                $start = $number;
            }

            $items[] = [
                'level' => $level,
                'runs' => $this->itemRuns($item, $state),
                'ordered' => $ordered,
                'start' => $ordered ? $start : null,
            ];

            $first = false;
            $number++;

            foreach ($item->children() as $child) {
                if ($child instanceof LeagueList) {
                    $items = $this->items($child, $level + 1, $items, $state);
                }
            }
        }

        return $items;
    }

    /**
     * A list, as the block a text frame holds it in.
     *
     * @throws UnsupportedLink
     */
    public function list(LeagueList $node, SlideState $state): ListBlock
    {
        return new ListBlock($this->items($node, 1, [], $state), $state->step);
    }

    /**
     * The words of a quoted block, one `>` at a time.
     *
     * A line that is not marked ends the quotation — CommonMark's rule and this one's — and
     * whatever followed it is content again rather than part of the quotation.
     *
     * @return list<string>
     */
    public function quoted(BlockQuote $node, SlideState $state): array
    {
        $lines = $state->lines($node);
        $quoted = [];
        $offset = 0;

        while (isset($lines[$offset]) && preg_match(self::QUOTE, $lines[$offset], $match) === 1) {
            $quoted[] = $match[1];
            $offset++;
        }

        return $quoted;
    }

    /**
     * The words of one list item, without the lists nested inside it.
     *
     * Read before them rather than after, because a bullet is drawn before the bullets
     * under it: the deck writes the parent first and the tree holds its children first, and
     * a slide that showed them the other way round would read bottom up.
     *
     * @return list<\MarkdownPresentation\Render\Run>
     *
     * @throws UnsupportedLink
     */
    private function itemRuns(Node $item, SlideState $state): array
    {
        $runs = [];

        foreach ($item->children() as $child) {
            if ($child instanceof LeagueList) {
                continue;
            }

            if ($child instanceof Paragraph || $child instanceof \League\CommonMark\Extension\Table\Table) {
                array_push($runs, ...$this->inline->runs($child, $state->slide, $state->theme, ' '));

                continue;
            }

            // A shape written inside a bullet — a fence, a block of HTML — is not one
            // a slide draws there, so it is read as the words it carries. Dropping it would be
            // drop the words the author wrote, which is the one outcome neither parser is
            // allowed to offer.
            $lines = implode("\n", array_map(trim(...), $state->lines($child)));

            array_push($runs, ...$this->inline->runsIn($lines, $state->slide, $state->theme, ' '));
        }

        return $runs;
    }
}
