<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

/**
 * One inline run read back out of a deck.
 *
 * A `.pptx` stores emphasis as properties of a run rather than as a nesting of
 * containers, so the tree is deliberately flat: a run carries the three things
 * Markdown can express inline, and the writer rebuilds the nesting from them.
 *
 * Underline is the one that does not survive. PowerPoint has it, Markdown has
 * no way to say it, and a deck whose emphasis is underlined rather than
 * emphasised comes back with the emphasis gone — see
 * {@see \MarkdownPresentation\PowerPointToMarkdown} for what the round trip
 * does not keep.
 */
final class Inline
{
    public const TEXT = 'text';
    public const BREAK = 'break';
    public const LINK = 'link';

    /**
     * @param string       $kind One of the `TEXT`, `BREAK` or `LINK` constants.
     * @param string       $text The literal characters, for a text run.
     * @param bool         $bold Whether the run is set in a bold face.
     * @param bool         $italic Whether the run is set in an italic face.
     * @param bool         $strike Whether the run is struck through.
     * @param string       $url Where a link points, for a link.
     * @param list<Inline> $children The label of a link, which may itself
     *        contain formatting.
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $text = '',
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly bool $strike = false,
        public readonly string $url = '',
        public readonly array $children = [],
    ) {
    }

    public static function text(
        string $text,
        bool $bold = false,
        bool $italic = false,
        bool $strike = false,
    ): self {
        return new self(self::TEXT, $text, $bold, $italic, $strike);
    }

    /** A line break within a paragraph, as opposed to the end of one. */
    public static function break(): self
    {
        return new self(self::BREAK);
    }

    /**
     * @param list<Inline> $children The label, which may contain emphasis.
     */
    public static function link(string $url, array $children): self
    {
        return new self(self::LINK, url: $url, children: $children);
    }
}
