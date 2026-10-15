<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Shadow as WrittenShadow;

/**
 * The shadow a free shape is drawn with, as the values PHPPresentation takes.
 *
 * Five attributes rather than one shorthand, because a shorthand is a grammar: this library
 * refuses what it cannot read rather than parsing what it can and dropping the rest, and
 * `shadow="4 8 #000000"` is a grammar someone has to learn, guess at, and get a silent answer
 * from. Five attributes are five facts and each of them can be wrong in a way that says so.
 *
 * **Distance and blur are pixels, not points.** A shadow is a size on the slide and the slide is
 * measured in pixels, which is the same rule {@see \MarkdownPresentation\Render\SlideGeometry}
 * already states for a position and a size. It is also what
 * {@see \PhpOffice\PhpPresentation\Style\Shadow} takes, so nothing is converted and nothing is
 * rounded on the way through. PowerPoint's own UI offers shadow size in points, and that is a
 * deliberate departure from it rather than an oversight.
 *
 * **Direction is degrees**, wrapped the way {@see \MarkdownPresentation\Render\Free\Placement}
 * wraps `rotate`: a full turn is zero, and the writer is asked for no angle rather than for 360.
 * There is no x/y pair to place a shadow with, so an angle is the only way to put one off-axis,
 * and degrees are what `rotate` is already written in.
 *
 * The alpha is the shadow's own rather than the shape's, because one `alpha` on a shape would
 * have to mean two things at once and the two are not the same decision: a panel at 60% over the
 * page and a shadow at 60% are set for different reasons.
 */
final class Shadow
{
    private const ROTATION = '/\A(-?\d+)(deg)?\z/';

    private function __construct(
        public readonly Color $colour,
        public readonly int $distance,
        public readonly int $blur,
        public readonly int $direction,
        public readonly int $alpha,
    ) {
    }

    /**
     * The shadow the attributes name, or null where the deck named none of them.
     *
     * Null rather than a default, so a shape with no shadow attributes is written with no
     * `<a:effectLst>` at all rather than with one that says the shape has no shadow — the same
     * reason `Paint::none()` is a thing that exists.
     *
     * @throws MalformedShape
     */
    public static function read(
        int $slide,
        string $name,
        DirectiveAttributes $attributes,
        Theme $theme,
    ): ?self {
        if (!self::written($attributes)) {
            return null;
        }

        $alpha = self::alpha($slide, $name, $attributes);
        $colour = Colour::read($slide, $name, 'shadow-color', $attributes, $theme, 'ink') ?? new Color('000000');

        return new self(
            $colour,
            self::length($slide, $name, 'shadow-distance', $attributes),
            self::length($slide, $name, 'shadow-blur', $attributes),
            self::direction($slide, $name, $attributes),
            $alpha,
        );
    }

    /**
     * The shadow as PHPPresentation writes it.
     *
     * Not chained onto the shape: {@see \PhpOffice\PhpPresentation\Shape\AbstractShape::setShadow()}
     * answers the shadow rather than the shape, so a chain through it ends on the wrong object.
     */
    public function apply(AbstractShape $target): void
    {
        $target->setShadow(
            (new WrittenShadow())
                ->setVisible(true)
                ->setType(WrittenShadow::TYPE_SHADOW_OUTER)
                ->setColor($this->colour)
                ->setDistance($this->distance)
                ->setBlurRadius($this->blur)
                ->setDirection($this->direction)
                // The shadow's own alpha, not the colour's: `Style\Shadow` defaults to 50 and the
                // writer takes the alpha from the shadow rather than from the colour it holds.
                ->setAlpha($this->alpha)
                ->setAlignment(WrittenShadow::SHADOW_BOTTOM_RIGHT),
        );
    }

    private static function written(DirectiveAttributes $attributes): bool
    {
        foreach (['shadow-color', 'shadow-distance', 'shadow-blur', 'shadow-direction', 'shadow-alpha'] as $attribute) {
            if ($attributes->has($attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A distance or a blur, in pixels, and never negative.
     *
     * Negative refused rather than taken as its magnitude: a negative blur is not a softer
     * shadow, it is a different thing that DrawingML has no room for, and silently flipping it
     * would draw something the deck did not ask for.
     *
     * @throws MalformedShape
     */
    private static function length(int $slide, string $name, string $attribute, DirectiveAttributes $attributes): int
    {
        $written = Length::written($slide, $name, $attribute, $attributes, '0');

        if (preg_match('/\A-?\d+\z/', $written) !== 1) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                sprintf('A shadow %s is a whole number of pixels, and cannot be negative: %s=0, %s=12.', str_starts_with($attribute, 'shadow-blur') ? 'blur' : 'distance', $attribute, $attribute),
            );
        }

        $pixels = (int) $written;

        if ($pixels < 0) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                sprintf('A shadow %s cannot be negative. Leave it off for none: %s=0.', str_starts_with($attribute, 'shadow-blur') ? 'blur' : 'distance', $attribute),
            );
        }

        return $pixels;
    }

    /**
     * The direction in degrees, wrapped into a turn.
     *
     * @throws MalformedShape
     */
    private static function direction(int $slide, string $name, DirectiveAttributes $attributes): int
    {
        $written = strtolower(Length::written($slide, $name, 'shadow-direction', $attributes, '90'));

        if (preg_match(self::ROTATION, $written, $match) !== 1) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                'shadow-direction',
                $written,
                'A shadow direction is a whole number of degrees, measured clockwise from the right: '
                . 'shadow-direction=0, shadow-direction=90, shadow-direction=-45, shadow-direction=45deg.',
            );
        }

        // PHP's `%` keeps the sign of the dividend, so `-45 % 360` is `-45` rather than the 315
        // it names, and a full turn has to be zero rather than 360. See
        // {@see \MarkdownPresentation\Render\Free\Placement::rotation()}.
        return (((int) $match[1] % 360) + 360) % 360;
    }

    /**
     * The shadow's own opacity as a whole percent, or 100 where the deck named none.
     *
     * @throws MalformedShape
     */
    private static function alpha(int $slide, string $name, DirectiveAttributes $attributes): int
    {
        $written = Length::written($slide, $name, 'shadow-alpha', $attributes, '100');

        if (preg_match('/\A\d+\z/', $written) !== 1 || (int) $written > 100) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                'shadow-alpha',
                $written,
                'A shadow alpha is a whole number of percent from 0 to 100: shadow-alpha=60.',
            );
        }

        return (int) $written;
    }
}
