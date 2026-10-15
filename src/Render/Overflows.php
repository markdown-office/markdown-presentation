<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\ContentOverflow;

/**
 * Every block that had to be made smaller than it asked to be, and the refusal that says so.
 *
 * Its own object rather than two arrays on the renderer because it is written from more than one
 * place — the layout's own stacking and the free shapes a deck placed itself — and because
 * "strict" is a fact about the report rather than about the deck: the same measurements decide
 * whether to clamp and say so, or to raise.
 *
 * Clamping without saying so is the one thing this library will not do: a slide with a block
 * quietly squeezed into the last pixel renders as a deck, and nobody looking at it can tell
 * what is missing.
 */
final class Overflows
{
    /**
     * @var list<array{slide: int, step: int, column: ?int, block: string, wanted: int, available: int}>
     */
    private array $recorded = [];

    /**
     * @param bool $strict Raise {@see ContentOverflow} rather than record. A caller that would
     *                     rather have no file than a file with a slide on it that is missing
     *                     something.
     */
    public function __construct(
        private readonly bool $strict = false,
    ) {
    }

    /**
     * @param ?int $column Null for a shape the deck placed itself, which is in no column at all,
     *                     and saying `column 0` would put a number where there is nothing.
     *
     * @throws ContentOverflow
     */
    public function record(int $slide, int $step, ?int $column, string $block, int $wanted, int $available): void
    {
        if ($this->strict) {
            throw ContentOverflow::on($slide, $step, $column, $block, $wanted, $available);
        }

        $this->recorded[] = [
            'slide' => $slide,
            'step' => $step,
            'column' => $column,
            'block' => $block,
            'wanted' => $wanted,
            'available' => $available,
        ];
    }

    /**
     * Everything recorded, in the order it was laid out.
     *
     * A placed text block is named as itself and its column is null, so an entry reads
     * `placed "content"` rather than a column number that is not there.
     *
     * @return list<array{slide: int, step: int, column: ?int, block: string, wanted: int, available: int}>
     */
    public function all(): array
    {
        return $this->recorded;
    }

    /**
     * Forget the last render, because a layout belongs to one deck: a caller converting several
     * in a loop reads the last one's, not all of them.
     */
    public function forget(): void
    {
        $this->recorded = [];
    }
}
