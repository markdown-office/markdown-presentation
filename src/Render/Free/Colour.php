<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\Style\Color;

/**
 * A colour a deck wrote, resolved against the theme it is rendered with.
 *
 * Resolved here rather than at draw time, and that is a decision rather than an accident of
 * where the code sits: `fill=panel` is a reference to the theme, so answering it needs the
 * theme — and an attribute nobody reads has to be refused before anything is built, which is
 * before there is a shape to hand a colour to. Resolving at read time is what lets
 * `fill=chartreuse` be a failure with no file behind it rather than the black
 * {@see \MarkdownPresentation\Render\ShapeWriter} falls back to for a slot it has never heard of.
 *
 * The four roles come before the twelve slots because they are the four names the rest of the
 * library paints with — `panel` is the panel a code block gets and `ink` is the ink body text
 * would have been drawn in — so a free shape and a laid-out one are the same colour on the same
 * theme. A deck written in them survives a theme swap; a deck written in hex does not, which is
 * the whole of what the theme engine is for.
 */
final class Colour
{
    /** Six hex digits, with or without the `#` an author writes them with. */
    private const HEX = '/\A#?([0-9A-Fa-f]{6})\z/';

    /** How a colour is written when there is none: an unfilled shape, not an unpainted one. */
    public const NONE = 'none';

    /**
     * The four names a shape plays rather than a slot it fills, in the order a message names
     * them.
     *
     * @var list<string>
     */
    private const ROLES = ['page', 'ink', 'subtle', 'panel'];

    /**
     * A colour as the deck wrote it, or null where it wrote that there is none.
     *
     * `none` is a value rather than a missing attribute because a shape with no fill is not the
     * same as one whose fill was never named: the first is written `a:noFill` and the second
     * inherits whatever its theme or placeholder would have given it. A free shape has no
     * placeholder, and a shape that inherits an outline it did not ask for is a shape with a
     * border nobody drew.
     *
     * @throws MalformedShape
     */
    public static function read(
        int $slide,
        string $name,
        string $attribute,
        DirectiveAttributes $attributes,
        Theme $theme,
        ?string $default = null,
    ): ?Color {
        $written = strtolower(Length::written($slide, $name, $attribute, $attributes, $default ?? self::NONE));

        if (self::isNone($written)) {
            return null;
        }

        $colour = self::named($written, $theme) ?? self::hex($written);

        if ($colour === null) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                sprintf(
                    'A colour is none, %s, a theme slot (%s), or a hex value such as #4C1D95.',
                    implode(', ', self::ROLES),
                    implode(', ', Theme::COLOURS),
                ),
            );
        }

        return $colour;
    }

    /**
     * Whether what the deck wrote is one of the two spellings of "there is no colour here".
     *
     * Named rather than left to each caller to know, because {@see self::read()} answers null for
     * exactly these two and nothing else — an unknown slot is refused rather than read as an
     * absent colour — so a caller that wants to refuse them has to be able to recognise them,
     * and a second list of spellings is a list that drifts from the first.
     */
    public static function isNone(string $written): bool
    {
        $written = strtolower($written);

        return $written === self::NONE || $written === 'transparent';
    }

    /**
     * A colour the deck named, whether by role or by slot, or null where it named neither.
     *
     * A slot the theme does not set comes back null as well, and is refused by the caller.
     * {@see Theme::defaults()} fills all twelve and a theme read from a file keeps the defaults
     * for the ones it does not mention, so a null here means a slot nobody has heard of rather
     * than one this particular theme forgot — and either way the failure belongs to the deck.
     */
    private static function named(string $written, Theme $theme): ?Color
    {
        $slot = match ($written) {
            'page' => $theme->page(),
            'ink' => $theme->ink(),
            'subtle' => $theme->subtleInk(),
            'panel' => $theme->panel(),
            default => $written,
        };

        $colour = $theme->colour($slot);

        return $colour === null ? null : new Color(ltrim($colour, '#'));
    }

    /**
 * A hex value, uppercased.
 *
 * Uppercased because the theme's own colours arrive that way from a `theme.yml` and a
 * lowercase hex beside an uppercase one in the same file is two spellings of one thing — which
 * `val` accepts either way and a reader diffing two decks does not.
 */
    private static function hex(string $written): ?Color
    {
        return preg_match(self::HEX, $written, $match) === 1 ? new Color(strtoupper($match[1])) : null;
    }
}
