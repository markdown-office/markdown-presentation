<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

use MarkdownPresentation\Render\Run;

/**
 * A GFM pipe table: a header row, the alignment row that makes it a table, and body rows.
 *
 * Cells keep their inline runs, because a cell is a paragraph's worth of text: a table
 * written `| **Name** | [docs](https://example.com) |` and drawn as characters is a table
 * of asterisks.
*/
final class Table implements Block
{
    /**
     * @param list<list<list<Run>>> $cells Every cell of every row, as the runs it is
     *                                     drawn from: row 0 is the header row, then one
     *                                     list of runs per cell.
     * @param list<string>     $align One of `left`, `center` or `right` per column, from
     *                                the alignment row.
    */
    public function __construct(
        public readonly array $cells,
        public readonly array $align,
        public readonly int $step,
    ) {
    }

    public function name(): string
    {
        return 'table';
    }

    public function step(): int
    {
        return $this->step;
    }
}
