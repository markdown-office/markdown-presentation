<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * An inline style whose name the theme does not carry.
 *
 * A deck that writes `:brand[Bakery]` is asking for a style the theme has to have, and a
 * theme that has none has said nothing about the words it draws. Drawing the directive as
 * its own source text would be the outcome this library exists to avoid: a deck that
 * renders, showing the mistake as prose.
 *
 * The message names the styles the theme does have, because the fix is in the message.
 */
final class UnknownTextStyle extends InvalidInput
{
    /**
     * @param list<string> $styles
     */
    public static function named(int $slide, string $name, array $styles): self
    {
        return new self(sprintf(
            'Slide %d has the text style ":%s", which is not a style this theme has. %s',
            $slide,
            $name,
            $styles === []
                ? 'The theme has no text styles; a theme names them in its "text-styles.yml".'
                : sprintf('The styles are: %s.', implode(', ', $styles)),
        ));
    }
}
