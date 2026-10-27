<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

/**
 * One named style's properties, as a theme's `text-styles.yml` writes them.
 *
 * A property that is null is one the style does not set, and a run it is applied to
 * inherits that property from the block it is in — a colour from the `:::content` box, a
 * size and a face from the same. Nothing here is reset to a default, so a style that sets
 * one property and leaves the other six alone changes one thing about a word.
 *
 * The colours are as they were written rather than as they resolve, because a role and a
 * theme slot are only a colour once the theme they belong to is known — and the theme
 * that reads this file is the one that resolves them.
 */
final class TextStyle
{
    /**
     * The attributes a text style may set, in the order a message names them.
     *
     * The vocabulary is the one both halves of the suite use, so it names eight. The
     * eighth is refused by name rather than drawn — see {@see self::UNSUPPORTED}.
     */
    public const ATTRIBUTES = ['color', 'size', 'font', 'bold', 'italic', 'underline', 'strike', 'highlight'];

    /**
     * The attribute this half does not draw.
     *
     * A highlight is a colour behind the word rather than in it, and PHPPresentation's
     * `Style\Font` has no setter for one: in the file it is an element of the run's own
     * properties, which means a pass over the written package. Until there is one, the
     * attribute is refused by name rather than accepted and quietly dropped.
     */
    public const UNSUPPORTED = 'highlight';

    /**
     * The name a theme may not give a style of its own.
     *
     * `style` is the word the raw form of a text style is written with — `:style[Bakery]`
     * — and a theme that defined a style of that name would have two things called by one
     * word.
     */
    public const RESERVED = 'style';

    /**
     * @param ?string $colour The ink, as written: a role, a theme slot or a hex value.
     * @param ?int    $size   The size of the type, in points.
     * @param ?string $font   The family, as written.
     */
    public function __construct(
        public readonly ?string $colour = null,
        public readonly ?int $size = null,
        public readonly ?string $font = null,
        public readonly ?bool $bold = null,
        public readonly ?bool $italic = null,
        public readonly ?bool $underline = null,
        public readonly ?bool $strike = null,
    ) {
    }

    /**
     * This style's properties, with another's laid over it.
     *
     * The other wins, property by property, and a property the other does not set is left
     * as this one had it. That is what an attribute on a style does: it overrides the
     * style it is written on, and says nothing about the properties it does not name.
     *
     * @param array{colour?: ?string, size?: ?int, font?: ?string, bold?: ?bool, italic?: ?bool, underline?: ?bool, strike?: ?bool} $over
     */
    public function with(array $over): self
    {
        return new self(
            $over['colour'] ?? $this->colour,
            $over['size'] ?? $this->size,
            $over['font'] ?? $this->font,
            $over['bold'] ?? $this->bold,
            $over['italic'] ?? $this->italic,
            $over['underline'] ?? $this->underline,
            $over['strike'] ?? $this->strike,
        );
    }
}
