<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;

/**
 * A run of paragraphs and lists, drawn as one text frame.
 *
 * One shape per block is what a renderer falls into: it measures a block, writes a box, moves
 * down to the next one. It is also what makes the result miserable to edit in PowerPoint.
 * Clicking the text selects the box rather than the sentence under the pointer, moving one
 * box leaves the rest of the slide where they were, and — because each box is placed from its
 * own measured height — two of them can be drawn over one another. A frame cannot: PowerPoint
 * lays its paragraphs out itself, inside the one box the height was measured for.
 *
 * A list is in the frame for the same reason. A bullet list is paragraphs, and the marker is
 * a property of a paragraph rather than a shape of its own, so a reader editing one types
 * into the frame instead of into a box that happens to be next to it.
 *
 * Everything else is a shape of its own, and ends the run. A heading is a different size,
 * colour and face; a table, a picture and a code block are not text at all; and a quote is
 * drawn a gutter in from the margin at another size in another colour, which is one frame's
 * paragraphs cannot each be while sharing one box.
 *
 * The two unions here are not the same on purpose: a paragraph and a list reach a slide only
 * inside a frame, so nothing downstream has to handle one of them on its own.
 *
 * @phpstan-type FrameParagraph Paragraph|array{level: int, runs: list<Run>, ordered: bool, start: ?int}
 * @phpstan-type DrawnBlock Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table
 * @phpstan-type Drawable Code|Heading|Image|Quote|Rule|Table|TextFrame
 */
final class TextFrame
{
    /** The name the shape this frame is written as carries in the file. */
    public const NAME = 'text';

    /**
     * @param non-empty-list<Paragraph|ListBlock> $blocks What the frame holds, in reading
     *        order. A list is one block here and several paragraphs in {@see self::paragraphs()},
     *        because it is one thing the deck wrote and several lines of type.
     */
    public function __construct(
        private readonly array $blocks,
    ) {
    }

    /**
     * The name this frame's shape carries.
     *
     * `text` rather than the name of the first block in it, because a frame of a paragraph and
     * a list is neither: the name is what a deck is read back off `ppt/slides/slideN.xml` as,
     * and a name that misdescribes the shape under it is worse than a general one.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Every paragraph of the frame, a list item being a paragraph of its own — which is what
     * it is in the file, and what makes the gap between two of them one question rather than
     * one per block.
     *
     * @return list<FrameParagraph>
     */
    public function paragraphs(): array
    {
        $paragraphs = [];

        foreach ($this->blocks as $block) {
            if ($block instanceof ListBlock) {
                foreach ($block->items as $item) {
                    $paragraphs[] = $item;
                }

                continue;
            }

            $paragraphs[] = $block;
        }

        return $paragraphs;
    }

    /**
     * The blocks of a column as the shapes they are drawn as: each run of text as one frame,
     * and every other block as itself.
     *
     * Runs rather than whole blocks, and the distinction is the point: a heading, a picture or
     * a code block drawn *inside* the frame of the paragraphs around it would be a shape the
     * reader cannot move on its own and the frame's own height would say nothing about where
     * it goes.
     *
     * @param list<DrawnBlock> $blocks
     *
     * @return list<Drawable>
     */
    public static function group(array $blocks): array
    {
        $drawn = [];
        $text = [];

        foreach ($blocks as $block) {
            if (!$block instanceof Paragraph && !$block instanceof ListBlock) {
                if ($text !== []) {
                    $drawn[] = new TextFrame($text);
                    $text = [];
                }

                $drawn[] = $block;

                continue;
            }

            $text[] = $block;
        }

        if ($text !== []) {
            $drawn[] = new TextFrame($text);
        }

        return $drawn;
    }
}
