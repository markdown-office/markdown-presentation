<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Render\SlideGeometry;
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

    /**
     * How far a shadow may reach, in pixels.
     *
     * The slide's width plus its height, which is longer than its diagonal — a deliberate
     * over-estimate rather than a square root, because the bound is a promise about what a
     * deck author could need and not a measurement of what they can see.
     *
     * It is bounded because {@see \PhpOffice\Common\Drawing::pixelsToEmu()} answers a **float**,
     * and PHP writes a large float as `9.525E+14` — where it switches is the `precision` ini
     * rather than anything this library decides, and `ST_PositiveCoordinate` is an `xsd:long`
     * either way. Unbounded, `shadow-distance=99999999999999999999` arrived as
     * `dist="8.785261865104174e+22"`: PowerPoint reads one, and a validator, Keynote or a
     * server-side renderer is not obliged to. At this bound the largest value written is
     * 14287500, well inside a double's exact integers and below any `precision`.
     */
    private const REACH = SlideGeometry::WIDTH + SlideGeometry::HEIGHT;

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
        $colour = self::colour($slide, $name, $attributes, $theme);

        return new self(
            $colour,
            self::length($slide, $name, 'shadow-distance', $attributes),
            self::length($slide, $name, 'shadow-blur', $attributes),
            self::direction($slide, $name, $attributes),
            $alpha,
        );
    }

    /**
     * The colour the shadow is drawn in, or the theme's own ink where the deck named none.
     *
     * **`none` and `transparent` are refused rather than read.** {@see Colour::read()} answers
     * null for exactly those two and for nothing else — an unknown slot is refused rather than
     * read as an absent colour — so a fallback here would be reachable only by a deck that
     * asked for no colour, and it would answer with the most opaque colour there is. Everywhere
     * else in the library `none` means the thing is not drawn, and a library that has spent
     * every refusal on that argument cannot be the one place where `shadow-color=none` paints
     * a solid black shadow at full opacity. The refusal names the way to say what was meant:
     * drop every shadow attribute, and there is no shadow.
     *
     * @throws MalformedShape
     */
    private static function colour(
        int $slide,
        string $name,
        DirectiveAttributes $attributes,
        Theme $theme,
    ): Color {
        $written = Length::written($slide, $name, 'shadow-color', $attributes, 'ink');

        if (Colour::isNone($written)) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                'shadow-color',
                $written,
                'A shadow is drawn, so a shadow colour is one of the four role names, a theme slot, or a hex value: '
                . 'shadow-color=ink, shadow-color=accent1, shadow-color=#4C1D95. For no shadow at all, '
                . 'leave off every shadow attribute.',
            );
        }

        return Colour::read($slide, $name, 'shadow-color', $attributes, $theme, 'ink') ?? new Color('000000');
    }

    /**
     * The shadow as PHPPresentation writes it.
     *
     * Not chained onto the shape: {@see \PhpOffice\PhpPresentation\AbstractShape::setShadow()}
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
                sprintf('A shadow %s is a whole number of pixels, and cannot be negative: %s=0, %s=12.', self::word($attribute), $attribute, $attribute),
            );
        }

        $pixels = (int) $written;

        if ($pixels < 0) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                sprintf('A shadow %s cannot be negative. Leave it off for none: %s=0.', self::word($attribute), $attribute),
            );
        }

        // Bounded for the reason {@see self::REACH} gives: pixels are multiplied into an EMU
        // coordinate that has to survive being written as a whole number, and nothing a deck
        // can see is further away than the slide is wide and tall together.
        if ($pixels > self::REACH) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                sprintf(
                    'A shadow %s of %d pixels reaches further than the slide is wide and tall together (%dpx), and a distance is written in EMU — one that large is written as a fraction of a whole number rather than the whole number. For no shadow: %s=0.',
                    self::word($attribute),
                    $pixels,
                    self::REACH,
                    $attribute,
                ),
            );
        }

        return $pixels;
    }

    /** The word a shadow message uses for a length: a blur is one, a distance is the other. */
    private static function word(string $attribute): string
    {
        return str_starts_with($attribute, 'shadow-blur') ? 'blur' : 'distance';
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
