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

    /**
     * The name of a template, in the frontmatter of the slide that defines it.
     *
     * Without a slide number, because a template definition is not a slide of the deck and
     * has no number: it is furniture, and the number a reader would look for is the number
     * of the slide it is drawn on.
     */
    public static function notATemplateName(): self
    {
        return new self(sprintf(
            'A template definition names the template it defines, and the name is a string: template: brand.',
        ));
    }
}
