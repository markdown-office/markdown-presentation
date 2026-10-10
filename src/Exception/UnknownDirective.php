<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A frontmatter key this library does not act on.
 *
 * A key nobody reads is not a setting that happens to have no effect yet — it is a setting
 * that will be ignored forever while the deck looks rendered. Slidev accepts dozens of
 * them, so refusing is a real cost, and the message is the whole of what makes it
 * affordable: it names every directive that does something, so the author edits the front
 * matter rather than re-reading the page to find out which half of the library is missing.
*/
final class UnknownDirective extends InvalidInput
{
    /**
     * @param list<string> $directives
    */
    public static function named(int $slide, string $key, array $directives): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d names "%s", which is not a directive. The directives are: %s.',
            $slide,
            $key,
            implode(', ', $directives),
        ));
    }

    /**
     * A deck-wide directive written on a later slide. It is refused rather than read from
     * slide 1 as well, because two slides that both name a theme is a deck that renders
     * one of them and says nothing about the other.
    */
    public static function misplaced(int $slide, string $key): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d names "%s", which is a deck directive and belongs in the frontmatter of slide 1.',
            $slide,
            $key,
        ));
    }
}