<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;

/**
 * How a picture fills the box the deck placed it in.
 *
 * Two, because that is what PHPPresentation can express: `setResizeProportional()` is a flag,
 * so a picture either keeps its ratio and is fitted inside the box or takes the box exactly.
 * `cover` is the one every author reaches for and it is not one of them — it is a crop, and a
 * crop is a `<a:srcRect>` written into the picture's own `<p:blipFill>` after the package has
 * been closed. It is refused by name, with what it would cost, rather than drawn as
 * `contain` and left to be noticed.
 */
final class Pictures
{
    /** Keep the ratio and fit inside the box, centred — what every picture in a column gets. */
    public const CONTAIN = 'contain';

    /** Take the box exactly, ratio kept or not. */
    public const STRETCH = 'stretch';

    /** @var list<string> */
    private const FITS = [self::CONTAIN, self::STRETCH];

    /**
     * @throws MalformedShape
     */
    public static function fit(int $slide, string $name, DirectiveAttributes $attributes): string
    {
        $written = strtolower(Length::written($slide, $name, 'fit', $attributes, self::CONTAIN));

        if (in_array($written, self::FITS, true)) {
            return $written;
        }

        if ($written === 'cover') {
            throw MalformedShape::coverFit($slide, $name);
        }

        throw MalformedShape::attributeValue(
            $slide,
            $name,
            'fit',
            $written,
            sprintf('A fit is one of: %s.', implode(', ', self::FITS)),
        );
    }
}
