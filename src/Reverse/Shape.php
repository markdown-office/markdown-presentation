<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

/**
 * A free shape read out of a deck, as the directive that would draw it.
 *
 * The inverse of what {@see \MarkdownPresentation\Render\Free\ShapeReader} reads: a shape a
 * deck placed itself is written back as the free shape it is, with the box it was given and
 * the colour it was painted.
 *
 * What does not travel is what a shape is not: the theme slot a colour came from, the shadow
 * it casts, the corner radius of a rounded rectangle, the layer it was drawn on and the
 * anchor of the box. A colour that came from a theme is written as the colour it resolved
 * to, because the slot it came from is in the theme and the theme is not in the deck.
 */
final class Shape
{
    /**
     * @param string                $directive  The free-shape directive that draws it:
     *        `rectangle`, `roundrect`, `content` and the rest.
     * @param array<string, string> $attributes The attributes it is written with, in the
     *        order they are written in.
     * @param list<Block>           $body       The blocks a free text block holds, for the
     *        one directive that holds some.
     */
    public function __construct(
        public readonly string $directive,
        public readonly array $attributes = [],
        public readonly array $body = [],
    ) {
    }

    /**
     * Whether the shape holds a body rather than being one line.
     */
    public function hasBody(): bool
    {
        return $this->body !== [];
    }
}
