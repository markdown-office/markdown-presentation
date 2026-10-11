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
}
