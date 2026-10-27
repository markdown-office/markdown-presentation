<?php

declare(strict_types=1);

namespace MarkdownPresentation\Document;

use MarkdownPresentation\Template\Definition;

/**
 * A split deck: its slides, in order, and the raw Markdown they came out of.
 *
 * The deck's own frontmatter is not a block of its own. Slidev reads it as the
 * frontmatter of the first slide, which is what {@see self::frontmatter()} returns,
 * and a deck that opens with a `---` block and then a slide is one slide carrying
 * both rather than a configuration followed by an empty slide. A caller that wants
 * the configuration does not have to know that.
 *
 * A template definition is not a slide of the deck and is kept beside them rather than
 * among them: it is furniture, it is not drawn on a slide of its own, and it does not
 * have a number. The slides are numbered over themselves, so a deck with a definition
 * in it numbers its slides as though the definition were not there, which is what
 * makes one definition and many slides add up to one slide each.
 */
final class Deck
{
    /**
     * @param list<Slide>      $slides
     * @param list<Definition> $definitions
     */
    public function __construct(
        private readonly string $raw,
        private readonly array $slides,
        private readonly array $definitions = [],
    ) {
    }

    /** The Markdown the deck was split out of, unchanged. */
    public function raw(): string
    {
        return $this->raw;
    }

    /**
     * @return list<Slide>
     */
    public function slides(): array
    {
        return $this->slides;
    }

    /**
     * The templates the deck defines, in the order they were written.
     *
     * @return list<Definition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * The deck's frontmatter, which is its first slide's. Empty for a deck with no
     * slides, which is a deck a caller built rather than one that was parsed.
     *
     * @return array<string, mixed>
     */
    public function frontmatter(): array
    {
        return $this->slides === [] ? [] : $this->slides[0]->frontmatter();
    }

    /**
     * One key of the deck's frontmatter, or `$default` when it is not there.
     */
    public function frontmatterValue(string $key, mixed $default = null): mixed
    {
        return $this->slides === [] ? $default : $this->slides[0]->frontmatterValue($key, $default);
    }

    public function count(): int
    {
        return count($this->slides);
    }
}
