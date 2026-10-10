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
     * `start` is the number the item was written with, or null for an item that begins one
     * to carry on. It is kept because a deck that opens a list at `3.` is numbering it from
     * three: numbering every list from one is a lie about the deck, and the writer's own
     * continuation rule keeps `1. 1. 1.` reading as `1. 2. 3.`.
     *
     * @param list<array{level: int, runs: list<Run>, start: ?int}> $items
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