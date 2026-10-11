<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

use Throwable;

/**
 * A frontmatter block that is not YAML, or is YAML that is not a mapping.
 *
 * A deck is written by a person or a model, so the failure belongs with the input
 * rather than with the machine: the two are told apart because only this one is
 * worth writing a different document to fix.
*/
final class MalformedFrontmatter extends InvalidInput
{
    public static function notYaml(int $slide, Throwable $previous): self
    {
        return new self(
            sprintf('The frontmatter of slide %d is not valid YAML: %s', $slide, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function notAMapping(int $slide): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d is not a mapping of keys to values, so there is nothing to read configuration from.',
            $slide,
        ));
    }

    public static function wrongType(int $slide, string $key, string $wanted): self
    {
        return new self(sprintf(
            'The frontmatter of slide %d has a "%s" that is not %s.',
            $slide,
            $key,
            $wanted,
        ));
    }
}
