<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Color;

/**
 * How the type inside a free text block is set, as the values PHPPresentation takes.
 *
 * Resolved to PHPPresentation's own constants here rather than carrying the words `center` and
 * `middle` down to the writer, because those are two vocabularies for one thing and the writer
 * is where the second one would have to be translated — which is the place a translation goes
 * wrong on the third shape of a deck and not on the first two.
 *
 * Every default is the theme's own, so a free text block that says nothing about itself looks
 * like the block a `content` layout would have drawn: the same body size, the same face, the
 * same ink, and the same insets, which are the ones PHPPresentation already writes and
 * {@see \MarkdownPresentation\Render\SlideGeometry::INSET_X} rounds up to.
 */
final class TextStyle
{
    /** How a block's text lines up across it, and what each word maps onto. */
    private const HORIZONTAL = [
        'left' => Alignment::HORIZONTAL_LEFT,
        'center' => Alignment::HORIZONTAL_CENTER,
        'right' => Alignment::HORIZONTAL_RIGHT,
        'justify' => Alignment::HORIZONTAL_JUSTIFY,
    ];

    /** How a block's text sits in it. `center` and `middle` are both the middle. */
    private const VERTICAL = [
        'top' => Alignment::VERTICAL_TOP,
        'middle' => Alignment::VERTICAL_CENTER,
        'center' => Alignment::VERTICAL_CENTER,
        'bottom' => Alignment::VERTICAL_BOTTOM,
    ];

    /**
     * @param Color|null $colour   The ink, or null for the body's own.
     * @param int|null   $size     In points, or null for the body's own.
     * @param string|null $face    A family name, or null for the body's own.
     * @param float|null $padding  In pixels on all four sides, or null to leave the insets be.
     */
    private function __construct(
        public readonly ?Color $colour,
        public readonly ?int $size,
        public readonly ?string $face,
        public readonly string $align,
        public readonly string $verticalAlign,
        public readonly ?float $padding,
    ) {
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
        return new self(
            Colour::read($slide, $name, 'color', $attributes, $theme, 'ink'),
            self::size($slide, $name, $attributes, $theme),
            self::face($slide, $name, $attributes, $theme),
            self::one($slide, $name, $attributes, 'align', self::HORIZONTAL, 'left'),
            self::one($slide, $name, $attributes, 'vertical-align', self::VERTICAL, 'top'),
            self::padding($slide, $name, $attributes),
        );
    }

    /**
     * @throws MalformedShape
     */
    private static function size(int $slide, string $name, DirectiveAttributes $attributes, Theme $theme): ?int
    {
        if (!$attributes->has('font-size')) {
            return null;
        }

        $written = Length::written($slide, $name, 'font-size', $attributes, (string) $theme->style()->body());
        $size = Length::points($slide, $name, 'font-size', $written);

        // `sz` is in hundredths of a point and PowerPoint treats a zero-height line of text as
        // text it cannot draw, so a size below half a point is a box with nothing in it rather
        // than a very small box. Rounded because {@see \PhpOffice\PhpPresentation\Style\Font}
        // takes an integer and a type size of 17.4 points is not a thing anybody asked for.
        if ($size < 0.5) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                'font-size',
                $written,
                'A size of type is at least half a point, and is written in points: font-size=18, font-size=18pt.',
            );
        }

        return (int) round($size);
    }

    /**
     * A family name: one of the theme's own slots, or a literal.
     *
     * A slot the theme has not set falls through to being read as a literal, which is the
     * outcome rather than a refusal — `font-family=Inter` is a real answer whether or not the
     * theme names it, and a theme that has not made it wrong has not.
     *
     * The quotes are stripped because a bare attribute value runs to the next space, so a
     * family name with a space in it has to arrive quoted. An unquoted one that arrives in
     * pieces is not silently joined: the second word is a separate attribute and is refused by
     * name, which is a better message than a guess about what was meant.
     *
     * @throws MalformedShape
     */
    private static function face(int $slide, string $name, DirectiveAttributes $attributes, Theme $theme): ?string
    {
        $written = Length::written($slide, $name, 'font-family', $attributes, '');

        if ($written === '') {
            return null;
        }

        $literal = trim($written, "\"'");

        return $theme->face($literal);
    }

    /**
 * One of a fixed set of words, with the default the library uses elsewhere.
 *
 * `$default` is the word rather than what the word maps onto, because it is what an attribute
 * absent would have been written as — and a failure that said `align` was not one of `l, ctr, r`
 * would be describing the file rather than the deck.
 *
 * @param array<string, string> $words
 *
 * @throws MalformedShape
 */
    private static function one(
        int $slide,
        string $name,
        DirectiveAttributes $attributes,
        string $attribute,
        array $words,
        string $default,
    ): string {
        $written = strtolower(Length::written($slide, $name, $attribute, $attributes, $default));

        if (!isset($words[$written])) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                sprintf('It is one of: %s.', implode(', ', array_keys($words))),
            );
        }

        return $words[$written];
    }

    /**
     * @throws MalformedShape
     */
    private static function padding(int $slide, string $name, DirectiveAttributes $attributes): ?float
    {
        if (!$attributes->has('padding')) {
            return null;
        }

        return Length::measure(
            $slide,
            $name,
            'padding',
            Length::written($slide, $name, 'padding', $attributes, '0'),
            null,
        );
    }
}
