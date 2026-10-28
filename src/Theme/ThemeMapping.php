<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\MalformedTheme;

/**
 * A `theme.yml`, and the {@see Theme} it turns into and back into.
 *
 * The seam is the same one {@see Style} draws from {@see StyleMapping}: the spellings and the
 * bounds below belong to the file, and {@see Theme} never sees one, so a theme built in code is
 * the caller's own business where a theme written in one is a mistake somebody can be told
 * about. {@see Theme::colour()} is the one thing that asks this class, because a caller may
 * hold either spelling of a slot and a value object has no file to be strict about.
 */
final class ThemeMapping
{
    /** The keys a `theme.yml` may have. */
    public const KEYS = ['name', 'description', 'author', 'dark', 'colors', 'fonts', 'style'];

    /**
     * The spellings the concept draft used, mapped onto DrawingML's own. {@see self::colourNames()}
     * lists them wherever a colour name is offered to the writer, because a name that is refused
     * should say what would have worked.
     *
     * Read against {@see Theme}'s own slots rather than a second copy of them: the twelve names
     * are what `Theme::colours()` is keyed by and what the renderer writes a colour scheme from,
     * so there is one list and this is where the file is held to it.
     */
    private const ALIASES = [
        'dark1' => Theme::DK1,
        'light1' => Theme::LT1,
        'dark2' => Theme::DK2,
        'light2' => Theme::LT2,
        'hyperlink' => Theme::HLINK,
        'followedHyperlink' => Theme::FOL_HLINK,
    ];

    private const HEX = '/\A#[0-9A-Fa-f]{6}\z/';

    /**
     * A theme as a `theme.yml` describes it.
     *
     * Every failure names the file it came from and what would have worked instead, because the
     * writer of a `theme.yml` is usually a person or a model who can act on that and nobody else.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    public static function read(array $data, string $file = 'theme.yml', ?TextStyles $styles = null): Theme
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw MalformedTheme::unknownKey($file, (string) $key, self::KEYS);
            }
        }

        $name = $data['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw MalformedTheme::missingKey($file, 'name');
        }

        $defaults = Theme::defaults();

        return new Theme(
            trim($name),
            self::text($data, 'description', $file),
            self::text($data, 'author', $file),
            self::readDark($data, $file),
            self::readColours($data['colors'] ?? null, $file) ?? $defaults->colours(),
            new Fonts(self::readFonts($data['fonts'] ?? null, $file) ?? $defaults->fonts()->all()),
            StyleMapping::read(self::mapping($data, 'style', $file), $file),
            $styles ?? new TextStyles(),
        );
    }

    /**
     * The theme's `theme.yml` as an array, in DrawingML's spellings.
     *
     * The named text styles are not in here: they are read from a file of their own, and a
     * `theme.yml` that carried them would be a theme with two places to say the same thing.
     *
     * @return array{name: string, description: ?string, author: ?string, dark: bool, colors: array<string, string>, fonts: array<string, string>, style: array<string, mixed>}
     */
    public static function write(Theme $theme): array
    {
        return [
            'name' => $theme->name(),
            'description' => $theme->description(),
            'author' => $theme->author(),
            'dark' => $theme->isDark(),
            'colors' => $theme->colours(),
            'fonts' => $theme->fonts()->all(),
            'style' => StyleMapping::write($theme->style()),
        ];
    }

    /**
     * The DrawingML slot a colour may be written under, whichever of the two spellings it was.
     *
     * Asked by {@see Theme::colour()} rather than duplicated there, because the two spellings
     * are a fact about the file and a value object must not carry a second copy of a map that
     * {@see self::readColours()} also consults.
     *
     * The name is folded before it is looked up: `folHlink` is DrawingML's own spelling and
     * `folhlink` is the same name in lower case, and a colour a theme's file names and a
     * colour a deck names are one vocabulary.
     */
    public static function slot(string $written): string
    {
        return self::canonical($written) ?? $written;
    }

    /**
     * The slot a colour name stands for, whatever it is written as, or null where it names
     * no colour at all.
     *
     * The aliases are the concept draft's spellings and the slots are DrawingML's own, and a
     * name in either of them stands for the same slot.
     */
    private static function canonical(string $written): ?string
    {
        $folded = strtolower($written);

        foreach (self::ALIASES as $alias => $slot) {
            if (strtolower($alias) === $folded) {
                return $slot;
            }
        }

        foreach (Theme::COLOURS as $slot) {
            if (strtolower($slot) === $folded) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * The spellings a colour may be written under besides DrawingML's own, and the slot each
     * of them stands for.
     *
     * Public because the spellings are a fact about the vocabulary rather than about the
     * file, and a caller holding a table of them — the documentation's own reference table,
     * for one — has no business reading a private constant to find out what they are.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return self::ALIASES;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws MalformedTheme
     */
    private static function mapping(array $data, string $key, string $file): array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw MalformedTheme::wrongType($file, $key, 'a mapping of style names to values');
        }

        return $value;
    }

    /**
     * `dark:` read as the boolean it has to be: `dark: yes` is a YAML 1.1 truthy
     * string rather than the flag, so only `true` and `false` are accepted and anything
     * else is a mistake somebody can fix.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    private static function readDark(array $data, string $file): bool
    {
        $dark = $data['dark'] ?? false;

        if (!is_bool($dark)) {
            throw MalformedTheme::wrongType($file, 'dark', 'true or false');
        }

        return $dark;
    }

    /**
     * @return array<string, string>|null Null when the theme names no colours, so the defaults stand.
     */
    private static function readColours(mixed $data, string $file): ?array
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw MalformedTheme::wrongType($file, 'colors', 'a mapping of colour names to colours');
        }

        $defaults = Theme::defaults()->colours();
        $named = [];

        foreach ($data as $key => $value) {
            $written = (string) $key;
            $slot = self::canonical($written);

            if ($slot === null) {
                throw MalformedTheme::unknownKey($file, $written, self::colourNames());
            }

            if (!is_string($value)) {
                throw MalformedTheme::wrongType($file, $written, 'a colour such as "#1E1E2E"');
            }

            if (preg_match(self::HEX, $value) !== 1) {
                throw MalformedTheme::notHexColour($file, $written, $value);
            }

            $named[$slot] = strtoupper($value);
        }

        $colours = [];

        foreach (Theme::COLOURS as $slot) {
            $colours[$slot] = $named[$slot] ?? $defaults[$slot];
        }

        return $colours;
    }

    /**
     * @return array<string, string>|null Null when the theme names no fonts, so the defaults stand.
     */
    private static function readFonts(mixed $data, string $file): ?array
    {
        if ($data === null) {
            return null;
        }

        if (!is_array($data)) {
            throw MalformedTheme::wrongType($file, 'fonts', 'a mapping of font slots to font names');
        }

        $defaults = Theme::defaults()->fonts()->all();
        $named = [];

        foreach ($data as $key => $value) {
            $slot = (string) $key;

            if (!in_array($slot, Theme::FONTS, true)) {
                throw MalformedTheme::unknownKey($file, $slot, Theme::FONTS);
            }

            if (!is_string($value) || trim($value) === '') {
                throw MalformedTheme::wrongType($file, $slot, 'a font name');
            }

            $named[$slot] = trim($value);
        }

        $fonts = [];

        foreach (Theme::FONTS as $slot) {
            $fonts[$slot] = $named[$slot] ?? $defaults[$slot];
        }

        return $fonts;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function text(array $data, string $key, string $file): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw MalformedTheme::wrongType($file, $key, 'a string');
        }

        return $value;
    }

    /**
     * Every name a colour may be written under, the DrawingML spellings first.
     *
     * @return list<string>
     */
    private static function colourNames(): array
    {
        return [...Theme::COLOURS, ...array_keys(self::ALIASES)];
    }
}
