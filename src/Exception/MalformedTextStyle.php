<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A text style written in a way this library cannot draw.
 *
 * Separate from {@see UnknownTextStyle} because the two failures have opposite fixes: a
 * name the theme does not have needs a different name, and an attribute or a value nobody
 * read needs a different attribute. One message covering both would send an author to edit
 * the half that was already right.
 *
 * Every message names what would have worked instead, for the reason
 * {@see \MarkdownPresentation\Exception\UnknownDirective} gives: the writer of a deck is
 * somebody who can act on that and nobody else.
 */
final class MalformedTextStyle extends InvalidInput
{
    /**
     * An attribute a text style does not read.
     *
     * @param list<string> $attributes
     */
    public static function attribute(int $slide, string $name, string $attribute, array $attributes): self
    {
        return new self(sprintf(
            'Slide %d gives the text style ":%s" an attribute "%s", which it does not read. The attributes are: %s.',
            $slide,
            $name,
            $attribute,
            implode(', ', $attributes),
        ));
    }

    /**
     * An attribute whose value is not what that attribute holds.
     *
     * `$advice` is a sentence rather than a noun phrase, because what a value has to be is
     * rarely one thing — the same reason {@see \MarkdownPresentation\Exception\MalformedShape}
     * takes a sentence.
     */
    public static function value(
        int $slide,
        string $name,
        string $attribute,
        string $written,
        string $advice,
    ): self {
        return new self(sprintf(
            'Slide %d gives the text style ":%s" a %s of "%s". %s',
            $slide,
            $name,
            $attribute,
            $written,
            $advice,
        ));
    }

    /**
     * A label that holds Markdown.
     *
     * The label of an inline directive is a string rather than Markdown, so a word that
     * came out as `**Bakery**` would be a deck that renders and shows something the author
     * did not write.
     *
     * `$wayOut` is a sentence rather than a noun phrase, because which file the way out is in
     * depends on the form the style was written in.
     */
    public static function label(int $slide, string $name, string $label, string $wayOut): self
    {
        return new self(sprintf(
            'Slide %d writes the text style ":%s" around "%s", which is Markdown. A style\'s text is plain text: %s '
            . 'or write the emphasis in the paragraph around it.',
            $slide,
            $name,
            $label,
            $wayOut,
        ));
    }

    /**
     * A label with nothing in it.
     */
    public static function noText(int $slide, string $name): self
    {
        return new self(sprintf(
            'Slide %d writes the text style ":%s" with no text. A style needs some text.',
            $slide,
            $name,
        ));
    }

    /**
     * The raw form written without a single attribute.
     */
    public static function noAttributes(int $slide): self
    {
        return new self(sprintf(
            'Slide %d writes ":style" with no attributes, which says nothing. Write the properties: '
            . '":style[Bakery]{color=00B4D8}".',
            $slide,
        ));
    }

    /**
     * An attribute this half does not draw.
     */
    public static function highlight(int $slide, string $name): self
    {
        return new self(sprintf(
            'Slide %d gives the text style ":%s" a highlight, which the slides half does not draw: a highlight is a '
            . 'colour behind the word, and this library has no way to write one.',
            $slide,
            $name,
        ));
    }
}
