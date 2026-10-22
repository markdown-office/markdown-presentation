<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

/**
 * One slide read back out of a deck.
 *
 * The shape a deck is written in: a title, some content, and a note. The
 * content is a tree of {@see Block}s rather than the shapes it came from,
 * because a slide is a set of positioned boxes and a deck is a sequence of
 * blocks, and the boxes have to become a sequence before anything can be
 * written down.
 */
final class Slide
{
    /**
     * @param int         $index The slide's place in the deck, counting from zero.
     * @param string|null $title The text of the slide's title placeholder, or null
     *        when the slide has none.
     * @param list<Block> $blocks The slide's content, in the order it is read.
     * @param string|null $note The speaker's note, or null when there is none.
     */
    public function __construct(
        public readonly int $index,
        public readonly ?string $title = null,
        public readonly array $blocks = [],
        public readonly ?string $note = null,
    ) {
    }
}
