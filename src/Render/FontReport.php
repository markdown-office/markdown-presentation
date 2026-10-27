<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnknownFont;

/**
 * Every face a theme named that this renderer has no table of advance widths for.
 *
 * Its own object rather than an array on the renderer because "strict" is a fact about the
 * report rather than about the deck: the same measurements decide whether to say so and
 * carry on, or to refuse — the same trade {@see Overflows} makes.
 *
 * A face with no table is not a failure. A deck naming a face nobody has measured still
 * renders: its text is measured with the reference face's widths, which are the widths
 * every face was measured with before there were tables of their own, and this is where
 * that is said. A caller that would rather have no file than a file measured with the wrong
 * face asks for the strict renderer and gets {@see UnknownFont} instead.
 */
final class FontReport
{
    /**
     * @var list<array{face: string, slot: string}>
     */
    private array $recorded = [];

    /**
     * @param bool $strict Raise {@see UnknownFont} rather than record. A caller that would
     *                     rather have no file than a file whose text was measured with
     *                     another face's widths.
     */
    public function __construct(
        private readonly bool $strict = false,
    ) {
    }

    /**
     * @param list<string> $known The faces there are tables for, which the refusal names.
     *
     * @throws UnknownFont
     */
    public function record(string $face, string $slot, array $known): void
    {
        if ($this->strict) {
            throw UnknownFont::named($face, $slot, $known);
        }

        $this->recorded[] = ['face' => $face, 'slot' => $slot];
    }

    /**
     * Everything recorded, in the order the faces were named.
     *
     * @return list<array{face: string, slot: string}>
     */
    public function all(): array
    {
        return $this->recorded;
    }

    /**
     * Forget the last render, because a layout belongs to one deck: a caller converting
     * several in a loop reads the last one's, not all of them.
     */
    public function forget(): void
    {
        $this->recorded = [];
    }
}
