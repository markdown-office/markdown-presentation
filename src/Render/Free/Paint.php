<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\Style\Color;

/**
 * What a free-placement directive paints: the fill, the stroke around it, how solid it is and
 * how wide the stroke is.
 *
 * Three of the four are optional in the same way and for the same reason — a shape with no fill
 * is not a shape whose fill was never named — so `none` is a value rather than an absence, and
 * {@see Colour} answers it with null for the shape writer to write `a:noFill`.
 *
 * There is no transparency beyond the one whole-shape alpha, and no dash, and neither is
 * refused by name. PHPPresentation's autoshapes are written with an {@see
 * \PhpOffice\PhpPresentation\Style\Outline} of one colour and one width — `a:ln` with a single
 * fill — so a dash or a double line is not a shape this library can draw, and offering a
 * `stroke-dash` that was quietly dropped would be worse than not offering it.
 */
final class Paint
{
    private function __construct(
        public readonly ?Color $fill,
        public readonly ?Color $stroke,
        public readonly float $strokeWidth,
        public readonly int $radius,
    ) {
    }

    /**
 * Nothing painted, for a directive that takes no paint at all.
 *
 * `::image` is the one: a picture is drawn by its own bytes, and offering `fill` on it would be
 * an attribute whose only effect is to say that nothing is there.
 */
public static function none(): self
    {
        return new self(null, null, 1.0, 0);
    }

    /**
     * @throws MalformedShape
     */
    public static function read(
        int $slide,
        string $name,
        DirectiveAttributes $attributes,
        Theme $theme,
    ): self {
        $fill = Colour::read($slide, $name, 'fill', $attributes, $theme);
        $stroke = Colour::read($slide, $name, 'stroke', $attributes, $theme);

        // One alpha for the shape rather than one per colour, because that is what the format
        // carries where a `a:alpha` can be had: on the colour itself. Applied to both, so
        // `alpha=80` is a shape seen slightly through the page rather than a fill and an
        // outline that disagree about how solid they are.
        $alpha = self::alpha($slide, $name, $attributes);

        if ($alpha !== null) {
            $fill?->setAlpha($alpha);
            $stroke?->setAlpha($alpha);
        }

        return new self(
            $fill,
            $stroke,
            Length::points($slide, $name, 'stroke-width', Length::written($slide, $name, 'stroke-width', $attributes, '1')),
            self::radius($slide, $name, $attributes),
        );
    }

    /**
     * The shape's opacity, or null where the deck named none and every colour stays whole.
     *
     * Null rather than 100 so that a shape drawn at full opacity is written with the six
     * characters of colour a reader has seen in every other shape on the slide, rather than
     * with an `<a:alpha>` at the end of it that changes nothing.
     *
     * @throws MalformedShape
     */
    private static function alpha(int $slide, string $name, DirectiveAttributes $attributes): ?int
    {
        $written = Length::written($slide, $name, 'alpha', $attributes, '100');

        if (preg_match('/\A\d+\z/', $written) !== 1 || (int) $written > 100) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                'alpha',
                $written,
                'An alpha is a whole number of percent from 0 to 100: alpha=80.',
            );
        }

        return $written === '100' ? null : (int) $written;
    }

    /**
     * @throws MalformedShape
     */
    private static function radius(int $slide, string $name, DirectiveAttributes $attributes): int
    {
        if (!$attributes->has('border-radius')) {
            return 0;
        }

        return (int) round(Length::measure(
            $slide,
            $name,
            'border-radius',
            Length::written($slide, $name, 'border-radius', $attributes, '0'),
            null,
        ));
    }
}