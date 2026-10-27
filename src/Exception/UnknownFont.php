<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A theme naming a face this renderer has no table of advance widths for.
 *
 * `InvalidInput` rather than a failure of this library's own side, because the deck's author
 * is the one who can act on it: the theme has to name a face there is a table for.
 *
 * Only raised when the renderer was asked to be strict. Otherwise the text is measured
 * with the reference face's widths and recorded by
 * {@see \MarkdownPresentation\Render\PresentationRenderer::unknownFonts()}, which is the
 * whole of the trade: a file either way, and a list of the faces it could not measure.
 */
final class UnknownFont extends InvalidInput
{
    /**
     * @param list<string> $known The faces there are tables for.
     */
    public static function named(string $face, string $slot, array $known): self
    {
        return new self(sprintf(
            'The theme\'s %s face "%s" has no table of advance widths, so its text cannot be measured '
            . 'with its own. The faces with one are: %s. Name one of them, or render without the strict '
            . 'check to have the text measured with the reference face instead.',
            $slot,
            $face,
            implode(', ', $known),
        ));
    }
}
