<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A `theme.yml` — or another file in a theme directory — that parses but does not
 * describe a theme: an unknown key, a colour that is not one, a value of the wrong
 * shape.
 *
 * Every message names what would have worked instead, for the reason
 * {@see ThemeNotFound} gives: the writer of the file is somebody who can act on
 * that and nobody else.
*/
final class MalformedTheme extends InvalidInput
{
    private static function in(string $file, string $problem): string
    {
        return sprintf('In "%s": %s', $file, $problem);
    }

    /**
     * @param list<string> $valid
    */
    public static function unknownKey(string $file, string $key, array $valid): self
    {
        return new self(self::in($file, sprintf(
            '"%s" is not a key of a theme. Valid keys: %s.',
            $key,
            implode(', ', $valid),
        )));
    }

    public static function missingKey(string $file, string $key): self
    {
        return new self(self::in($file, sprintf('A theme needs a "%s".', $key)));
    }

    public static function wrongType(string $file, string $key, string $wanted): self
    {
        return new self(self::in($file, sprintf('"%s" is not %s.', $key, $wanted)));
    }

    public static function notAMapping(string $file, string $wanted, string $got): self
    {
        return new self(self::in($file, sprintf(
            'The file does not contain %s — it is %s.',
            $wanted,
            $got,
        )));
    }

    public static function notHexColour(string $file, string $key, string $value): self
    {
        return new self(self::in($file, sprintf(
            '"%s" is "%s", which is not a colour: a DrawingML colour is a hash and six hex digits, as in "#1E1E2E".',
            $key,
            $value,
        )));
    }

    /**
     * A colour a theme's file named that is not one.
     *
     * The advice is the theme's own, built from the names a colour may be written under, so
     * that a failure in a theme's file and a failure in a deck's attribute name the same
     * list — they are one question and two vocabularies would be two answers to it.
     */
    public static function wrongColour(string $file, string $key, string $written, string $advice): self
    {
        return new self(self::in($file, sprintf(
            '"%s" is "%s", which is not a colour. %s',
            $key,
            $written,
            $advice,
        )));
    }

    /**
     * A text style that sets an attribute that is not one.
     *
     * The attributes are named rather than the keys of a theme, because a text style is
     * not a theme: it carries the properties of a run and nothing else.
     *
     * @param list<string> $attributes
     */
    public static function unknownAttribute(string $file, string $style, string $attribute, array $attributes): self
    {
        return new self(self::in($file, sprintf(
            'The style "%s" sets "%s", which is not an attribute of a text style. The attributes are: %s.',
            $style,
            $attribute,
            implode(', ', $attributes),
        )));
    }

    /**
     * A text style that styles nothing.
     */
    public static function emptyStyle(string $file, string $style): self
    {
        return new self(self::in($file, sprintf(
            'The style "%s" sets nothing. A style that styles nothing is a mistake.',
            $style,
        )));
    }

    /**
     * A text style named with the word the raw form is written with.
     */
    public static function reservedStyle(string $file, string $style): self
    {
        return new self(self::in($file, sprintf(
            'The style "%s" is named with a reserved word: "style" is how the raw form of a text style is written, '
            . 'as in ":style[Bakery]{color=00B4D8}".',
            $style,
        )));
    }

    /**
     * A text style that sets an attribute this half does not draw.
     */
    public static function unsupportedAttribute(string $file, string $style, string $attribute): self
    {
        return new self(self::in($file, sprintf(
            'The style "%s" sets a "%s", which the slides half does not draw: a highlight is a colour behind the '
            . 'word, and this library has no way to write one.',
            $style,
            $attribute,
        )));
    }

    /**
     * Two text styles whose names are the same word.
     *
     * The names are folded, so `Brand` and `brand` are one name: a theme that carries both
     * has two styles a deck cannot tell apart.
     */
    public static function duplicateStyle(string $file, string $style, string $other): self
    {
        return new self(self::in($file, sprintf(
            'The style "%s" is the same style as "%s": a style\'s name is a word, and a theme names one style once.',
            $style,
            $other,
        )));
    }
}
