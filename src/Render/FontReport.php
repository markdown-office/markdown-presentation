<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnknownFont;

/**
 * Every face a theme or a deck named that this renderer has no table of advance widths for.
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
 *
 * The place the face was named is kept beside it, because "there is no table for Comic Sans
 * MS" is only half an answer: the reader has to be told where to change it. A face named in
 * the same place twice is one entry — a theme that names it in two slots is two places and
 * two entries, but a deck that writes it on twenty words is one place.
 */
final class FontReport
{
    /**
     * @var list<array{face: string, where: string}>
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
     * @param string      $where Where the face was named: a slot of the theme, a text style
     *                           of it, a box of the deck, or an attribute of a word.
     * @param list<string> $known The faces there are tables for, which the refusal names.
     *
     * @throws UnknownFont
     */
    public function record(string $face, string $where, array $known): void
    {
        if ($this->strict) {
            throw UnknownFont::named($face, $where, $known);
        }

        foreach ($this->recorded as $entry) {
            if ($entry['face'] === $face && $entry['where'] === $where) {
                return;
            }
        }

        $this->recorded[] = ['face' => $face, 'where' => $where];
    }

    /**
     * Everything recorded, in the order the faces were named.
     *
     * @return list<array{face: string, where: string}>
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
