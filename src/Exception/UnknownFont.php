<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A face a theme or a deck named that this renderer has no table of advance widths for.
 *
 * `InvalidInput` rather than a failure of this library's own side, because the deck's author
 * is the one who can act on it: the theme has to name a face there is a table for, or the
 * deck has to name one the theme names.
 *
 * Only raised when the renderer was asked to be strict. Otherwise the text is measured
 * with the reference face's widths and recorded by
 * {@see \MarkdownPresentation\Render\PresentationRenderer::unknownFonts()}, which is the
 * whole of the trade: a file either way, and a list of the faces it could not measure.
 */
final class UnknownFont extends InvalidInput
{
    /**
     * @param string      $where Where the face was named: a slot of the theme, a text style
     *                           of it, a box of the deck, a box of a template's chrome, the
     *                           number of a slide, or an attribute of a word.
     * @param list<string> $known The faces there are tables for.
     */
    public static function named(string $face, string $where, array $known): self
    {
        return new self(sprintf(
            'The face "%s" has no table of advance widths, so its text cannot be measured '
            . 'with its own. It is named by %s. The faces with one are: %s. Name one of them, '
            . 'or render without the strict check to have the text measured with the reference '
            . 'face instead.',
            $face,
            $where,
            implode(', ', $known),
        ));
    }
}
