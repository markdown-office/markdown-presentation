<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

use MarkdownPresentation\Render\Run;

/**
 * A list: one shape, whatever mix of markers it was written with.
 *
 * `ordered` is per item rather than per list because a deck mixes the markers — `- a`
 * followed by `1. b` is one list with a bullet and a number in it — and a flag taken from
 * the first item draws all three as whatever that first item happened to be.
*/
final class ListBlock implements Block
{
    /**
     * Each item's text is itself a list of runs, because a list item can carry the same
     * inline markup a paragraph can and dropping it would be the same lie.
     *
     * `start` is the number an item was written with, or null for one that carries on from
     * the numbering above it. It is kept because a deck that opens a list at `3.` is
     * numbering it from three, and because the writer's own continuation rule keeps
     * `1. 1. 1.` reading as `1. 2. 3.`
     *
     * @param list<array{level: int, runs: list<Run>, ordered: bool, start: ?int}> $items
    */
    public function __construct(
        public readonly array $items,
        public readonly int $step,
    ) {
    }

    public function step(): int
    {
        return $this->step;
    }
}
