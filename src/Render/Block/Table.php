<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

/**
 * A GFM pipe table: a header row, the alignment row that makes it a table, and body rows.
*/
final class Table implements Block
{
    /**
     * @param list<list<string>> $cells Every cell of every row, as written: row 0 is the
     *                                   header row.
     * @param list<string>       $align One of `left`, `center` or `right` per column, from
     *                                   the alignment row.
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