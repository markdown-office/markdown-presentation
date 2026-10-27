<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A template a slide names, and there is no template under that name.
 *
 * A template is furniture the deck writes once, and a slide that asks for furniture that
 * is not there would be drawn without it — a slide whose wordmark is missing because the
 * name was spelled differently. The names that do resolve are named in the failure, so the
 * fix is a name the deck already uses rather than a search through the deck for the one it
 * meant.
 */
final class UnknownTemplate extends InvalidInput
{
    /**
     * @param list<string> $available The templates the deck has.
     */
    public static function named(int $slide, string $name, array $available): self
    {
        return new self(sprintf(
            'Slide %d names the template "%s", which is not one. The templates are: %s.',
            $slide,
            $name,
            $available === [] ? '(none)' : implode(', ', $available),
        ));
    }
}
