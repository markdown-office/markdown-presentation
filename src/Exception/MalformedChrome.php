<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A template that holds the wrong thing.
 *
 * A template holds chrome — the free shapes it draws — and nothing else, so a body that
 * holds ordinary Markdown, a column marker, or nothing at all is a mistake in the template
 * rather than in the deck that draws it.
 *
 * Kept apart from {@see UnknownTemplate} because the two failures have opposite fixes: a
 * name nobody has needs a different name, and a body that holds the wrong thing needs a
 * different body. One message covering both would send an author to edit the half that was
 * already right.
 */
final class MalformedChrome extends InvalidInput
{
    /**
     * A template whose body holds ordinary Markdown.
     *
     * `$what` names what was found, because "a template holds chrome, not content" is only
     * half an answer: the author has to be told which paragraph or heading they wrote into
     * the template to find it again.
     */
    public static function body(string $name, string $what): self
    {
        return new self(sprintf(
            'The template "%s" holds %s. A template holds chrome, not content: the free shapes it draws, and the '
            . 'words a ":::content" block carries.',
            $name,
            $what,
        ));
    }

    /**
     * A template whose body holds a column marker.
     *
     * A column is where a block is drawn, and a template draws no blocks: it is furniture,
     * and furniture is placed rather than laid out.
     */
    public static function marker(string $name): self
    {
        return new self(sprintf(
            'The template "%s" holds a column marker. A template holds chrome, not content, and a column is where a '
            . 'block of a slide is drawn.',
            $name,
        ));
    }

    /**
     * A template that draws nothing.
     */
    public static function empty(string $name): self
    {
        return new self(sprintf(
            'The template "%s" draws nothing. A template holds chrome: the free shapes it draws on every slide that '
            . 'names it.',
            $name,
        ));
    }

    /**
     * A mistake in a template, told with the message the mistake came with.
     *
     * The shape of the template is not the mistake: a picture that is not there, an
     * attribute nobody reads and a box off the slide are all the same kind of thing here —
     * a template that cannot be drawn — and the message that says which one it is has
     * already been written.
     */
    public static function in(string $name, string $message): self
    {
        return new self(sprintf('The template "%s" cannot be drawn: %s', $name, $message));
    }
}
