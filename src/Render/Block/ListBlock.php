<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

use MarkdownPresentation\Render\Run;

final class ListBlock implements Block
{
    /**
     *
     * Each item's text is itself a list of runs, because a list item can carry the same
     * inline markup a paragraph can and dropping it would be the same lie.
     *
     * @param list<array{level: int, runs: list<Run>}> $items
    */
    public function __construct(
        public readonly array $items,
        public readonly bool $ordered,
        public readonly int $step,
    ) {
    }

    public function name(): string
    {
        return 'list';
    }

    public function step(): int
    {
        return $this->step;
    }
}