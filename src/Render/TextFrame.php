<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Render\Block\Block;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;

/**
 * A run of the blocks that are only type, drawn as one text frame.
 *
 * One shape per block is what a renderer falls into: it measures a block, writes a box, moves
 * down to the next one. It is also what makes the result miserable to edit in PowerPoint.
 * Clicking the text selects the box rather than the sentence under the pointer, moving one
 * box leaves the rest of the slide where they were, and — because each box is placed from its
 * own measured height — two of them can be drawn over one another. A frame cannot: PowerPoint
 * lays its paragraphs out itself, inside the one box the height was measured for.
 *
 * That is also why the frame is worth widening as far as it goes. A block of type the reader
 * cannot lift out of its box is a block the renderer got to position wrongly with no way to
 * be wrong twice: the measurement is made once, for the whole run, and `normAutofit` is
 * behind all of it. One estimate the renderer can be short by, rather than one per block.
 *
 * A list is in the frame for the same reason. A bullet list is paragraphs, and the marker is
 * a property of a paragraph rather than a shape of its own, so a reader editing one types
 * into the frame instead of into a box that happens to be next to it. A heading is a
 * paragraph with its own runs, and a quote is a paragraph indented and italic: neither needs
 * a box of its own to say what a box of its own could say, and markdown-word has always said
 * it as `Heading1` and `IntenseQuote` — paragraph styles, positioned by the reader rather
 * than by us.
 *
 * What is left is what is not type, or is type with something painted behind it. A table, a
 * picture and a rule are shapes, and a code block is a filled one — a fill or a border over
 * part of a text frame is not something the format can express — so each of those is a shape
 * of its own, and each ends the run.
 *
 * The two unions here are not the same on purpose: the blocks that reach a slide only inside a
 * frame are {@see FrameBlock}, so nothing downstream has to handle one of them on its own.
 *
 * @phpstan-type FrameParagraph Heading|Paragraph|Quote|array{level: int, runs: list<Run>, ordered: bool, start: ?int}
 * @phpstan-type FrameBlock Heading|Paragraph|Quote|ListBlock
 * @phpstan-type DrawnBlock Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table
 * @phpstan-type Drawable Code|Image|Rule|Table|TextFrame
 */
final class TextFrame
{
    /** The name the shape this frame is written as carries in the file. */
    public const NAME = 'text';

    /**
     * @param non-empty-list<FrameBlock> $blocks What the frame holds, in reading order. A
     *        list is one block here and several paragraphs in the shape it is written as,
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
     * The step the frame's shapes appear on.
     *
     * Every block in a frame is on the same one — {@see self::group()} ends a run at a step
     * boundary because a frame is one shape and a shape appears on one step — so this is its
     * first block's, and it is what the build is written from.
     */
    public function firstStep(): int
    {
        return $this->blocks[0]->step();
    }

    /**
     * The blocks the frame holds, in reading order, a list being one of them.
     *
     * The air between two paragraphs of a frame is a property of the two *blocks* they belong
     * to rather than of the paragraphs: the gap after a list is the gap after a list whatever
     * how many lines its items took. So the measure and the draw both walk this list and expand
     * a list into its items underneath, rather than a flattened list of paragraphs that has
     * forgotten which block each of them came from.
     *
     * @return non-empty-list<FrameBlock>
     */
    public function blocks(): array
    {
    return $this->blocks;
    }

/**
 * The block at the top of the frame, which is what carries the air above it.
 *
 * A frame opens on whatever the deck wrote first, and that is what decides how much room
 * the shape above it has to leave: a frame that opens on a heading has to clear the
 * heading's own air, and one that opens on a paragraph has nothing to clear but the
 * block above it. {@see \MarkdownPresentation\Render\SlideGeometry::spaceAbove()} asks
 * this rather than being told what the frame holds, so that the gap between two shapes
 * and the gap between two paragraphs of a frame cannot be two different answers to the
 * same question.
 *
 * @return FrameBlock
 */
    public function leading(): Block
    {
        return $this->blocks[0];
    }

    /**
     * The block at the foot of the frame, which is what carries the air below it.
     *
     * The mirror of {@see self::leading()}, for the same reason.
     *
     * @return FrameBlock
     */
    public function trailing(): Block
    {
        return $this->blocks[count($this->blocks) - 1];
    }

    /**
     * The blocks of a column as the shapes they are drawn as: each run of text as one frame,
     * and every other block as itself.
     *
     * Runs rather than whole blocks, and the distinction is the point: a table, a picture or a
     * code block drawn *inside* the frame of the type around it would be a shape the reader
     * cannot move on its own and the frame's own height would say nothing about where it
     * goes.
     *
     * A step ends a run as surely as a table does, and this is the one place that has to know
     * it: a frame is one shape and an animation reveals one shape on one click, so a frame
     * holding a step's paragraphs and the next step's would put two clicks' worth of text on
     * the slide at the first of them. Splitting there costs nothing in the file —
     * {@see \MarkdownPresentation\Render\ShapeWriter::textFrame()} writes a frame's air at its
     * first block and its last, and zeroes it between the blocks of the frame, because those are
     * paragraphs of one thing rather than blocks in their own right; two frames of one paragraph
     * each are given that same air by the column's own arithmetic — and the reader editing
     * the result gets one box per click, which is the unit a build-up is walked in.
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
            if (
                $block instanceof Code
                || $block instanceof Image
                || $block instanceof Rule
                || $block instanceof Table
            ) {
                if ($text !== []) {
                    $drawn[] = new TextFrame($text);
                    $text = [];
                }

                $drawn[] = $block;

                continue;
            }

            // A step ends a run as surely as a table does: a frame is one shape and an
            // animation reveals one shape on one click, so a frame holding a step's
            // paragraphs and the next step's would put two clicks' worth of text on the slide
            // at the first of them.
            if ($text !== [] && $text[0]->step() !== $block->step()) {
                $drawn[] = new TextFrame($text);
                $text = [];
            }

            $text[] = $block;
        }

        if ($text !== []) {
            $drawn[] = new TextFrame($text);
        }

        return $drawn;
    }

}
