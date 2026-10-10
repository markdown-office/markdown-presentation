<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A slide that opens with a fenced `yaml` block.
 *
 * Slidev reads that as the slide's frontmatter. This library does not, and
 * silently disagreeing with the dialect it claims to implement would render a
 * configuration block as a code block — or a code block as configuration, which is
 * worse. Refusing the ambiguous shape names both spellings instead.
*/
final class FencedFrontmatter extends InvalidInput
{
    public static function at(int $slide): self
    {
        return new self(sprintf(
            'Slide %d opens with a fenced yaml block. Slidev reads that as frontmatter; this library does not, '
            . 'so it is refused rather than read as a code block. Delimit the frontmatter with "---" instead.',
            $slide,
        ));
    }
}