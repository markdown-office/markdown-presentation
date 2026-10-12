<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;

/**
 * One slide's blocks, the column each of them was written for, and how many `::column`
 * markers the deck wrote.
 *
 * A parallel list rather than a property on the blocks because nothing about a block depends
 * on where it is drawn: the same paragraph is the same paragraph in either column, so the
 * number would be carried by eight classes, a text frame, two synthetic headings and every
 * construction site of all of them, for one reader. The lists are checked against each other
 * here instead, once, rather than at every `visible()`.
 *
 * The columns are zero-based and are not necessarily contiguous: a slide that opens on
 * `::column` has no block in column zero, and the renderer draws that column empty rather
 * than moving a block back into it.
 *
 * The marker count is beside the columns because a marker with nothing after it leaves no
 * block to show for it, and the renderer has to refuse it all the same: a rule that depended
 * on what happened to follow the marker would make the spelling of a deck depend on the last
 * line of a slide.
 *
 * {@see \MarkdownPresentation\Render\PresentationRenderer} owns the role and the geometry and
 * decides whether the slide can honour them; this only records what the deck asked for.
 */
final class SlideBlocks
{
    /**
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks In reading order.
     * @param list<int>                                                      $columns The column each block was written for, index by index.
     * @param int                                                            $columnMarkers How many `::column` markers the deck wrote, whether or not a block follows each one.
     *
     * @throws Exception When the two lists are not the same length, or when the marker count
     *                   is below the column a block was written for — a caller that built the
     *                   value wrongly rather than a deck that cannot be drawn, so
     *                   {@see \MarkdownPresentation\Exception\InvalidInput}, whose contract is
     *                   that the deck's author can fix it, would be the wrong type here.
     */
    public function __construct(
        private readonly array $blocks,
        private readonly array $columns,
        private readonly int $columnMarkers,
    ) {
        if (count($blocks) !== count($columns)) {
            throw new Exception(sprintf(
                'A slide carries %d blocks and %d columns; a column is the column of the block at '
                . 'the same index, so there has to be one of them for every block.',
                count($blocks),
                count($columns),
            ));
        }

        $widest = $columns === [] ? 0 : max($columns);

        if ($columnMarkers < $widest) {
            throw new Exception(sprintf(
                'A slide carries %d "::column" markers and a block written in column %d; a marker '
                . 'moves what follows it into the next column, so there cannot be fewer markers '
                . 'than the column a block is in.',
                $columnMarkers,
                $widest,
            ));
        }
    }

    /**
     * The blocks, in reading order.
     *
     * @return list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * The column each block was written for, in the same order.
     *
     * @return list<int>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * How many `::column` markers the deck wrote on this slide.
     *
     * What the deck asked for rather than what it left behind: a marker with nothing after it
     * is still a marker, and this is the count the renderer measures the layout's columns
     * against.
     */
    public function columnMarkers(): int
    {
        return $this->columnMarkers;
    }
}
