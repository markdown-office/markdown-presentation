<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

/**
 * A stretch of text inside a paragraph, with what it is emphasised as.
 *
 * Emphasis is per run rather than per paragraph because a paragraph that says
 * `**one** of three` has to survive: dropping the bold would leave the slide saying
 * something its author did not.
 *
 * A property that is null is one the run says nothing about, and it takes whatever the
 * block it is in already gave it — the colour of the `:::content` box it is in, the size
 * of the paragraph. That is what makes a text style a style rather than a set of
 * defaults: a style that sets a colour and nothing else changes the colour of a word and
 * leaves its weight, its size and its face to the block around it.
 *
 * A weight, a slant, an underline and a strike are a flag rather than a property: a style
 * that says `bold: false` says that the word carries no weight of its own, and the word
 * takes the block's — which is the same thing as saying nothing about it, and is what
 * makes `false` the way to take the weight off a style that has it.
 */
final class Run
{
    /**
     * @param ?string $link   Where the run goes when it is clicked, or null when it is text.
     * @param ?string $colour The ink, as `#rrggbb`, or null when the run says nothing about it.
     * @param ?int    $size   The size of the type, in points, or null when the run says nothing about it.
     * @param ?string $font   The family, or null when the run says nothing about it.
     */
    public function __construct(
        public readonly string $text,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly bool $code = false,
        public readonly ?string $link = null,
        public readonly bool $strike = false,
        public readonly ?string $colour = null,
        public readonly ?int $size = null,
        public readonly ?string $font = null,
        public readonly bool $underline = false,
    ) {
    }

    /**
     * This run, with another's properties filled in where this one says nothing about them.
     *
     * The run's own wins: a word that carries a colour of its own keeps it, and one that
     * carries none takes the other's. That is what makes the box a default rather than an
     * override.
     *
     * @param array{colour?: ?string, size?: ?int, font?: ?string} $gaps
     */
    public function filled(array $gaps): self
    {
        return new self(
            $this->text,
            $this->bold,
            $this->italic,
            $this->code,
            $this->link,
            $this->strike,
            $this->colour ?? ($gaps['colour'] ?? null),
            $this->size ?? ($gaps['size'] ?? null),
            $this->font ?? ($gaps['font'] ?? null),
            $this->underline,
        );
    }
}
