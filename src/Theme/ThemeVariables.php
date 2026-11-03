<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\MalformedTheme;

/**
 * The theme's values, as a slide may lay over them.
 *
 * The override is a `theme.yml`: the same keys, the same names, the same spelling, read by
 * the reader that reads the theme's own file. A slide writes `colors: {accent1: "#FF0000"}`
 * and a theme's file writes the same, and there is one vocabulary rather than two.
 *
 * What a slide may name is the theme's *values* and not its identity: `name`, `description`
 * and `author` are the theme's, and a slide has none of them.
 *
 * The values are merged as the mappings they are and read once, rather than merged as the
 * objects they become. That is what makes an override of one value leave the others alone:
 * a style's mapping names every one of the theme's numbers, so a slide that names one of
 * them replaces one of them and the rest stand.
 */
final class ThemeVariables
{
    /**
     * The keys an override may carry.
     *
     * The theme's own keys less the three that are the theme's identity.
     *
     * @var list<string>
     */
    public const KEYS = ['dark', 'colors', 'fonts', 'style'];

    /**
     * The keys whose values are mappings of names to values, and are merged name by name.
     *
     * @var list<string>
     */
    private const MAPS = ['colors', 'fonts', 'style'];

    /**
     * One set of the theme's values laid over another.
     *
     * The mappings are merged name by name, so that a slide that names one colour leaves the
     * other eleven alone, and the values that are not mappings are replaced whole, so that a
     * slide that says the theme is dark says so outright.
     *
     * @param array<string, mixed> $under What the values are laid over.
     * @param array<string, mixed> $over  What is laid over them.
     *
     * @return array<string, mixed>
     */
    public static function merge(array $under, array $over): array
    {
        $merged = $under;

        foreach ($over as $key => $value) {
            $merged[$key] = in_array((string) $key, self::MAPS, true)
                && is_array($value)
                && is_array($merged[$key] ?? null)
                    ? array_replace($merged[$key], $value)
                    : $value;
        }

        return $merged;
    }

    /**
     * The theme with the values laid over it.
     *
     * @param array<string, mixed> $data The override, as the slide's frontmatter wrote it.
     * @param string $file           Where the override came from, for a failure.
     *
     * @throws MalformedTheme
     */
    public static function apply(Theme $theme, array $data, string $file): Theme
    {
        foreach (array_keys($data) as $key) {
            if (!in_array((string) $key, self::KEYS, true)) {
                throw MalformedTheme::unknownVariable($file, (string) $key, self::KEYS);
            }
        }

        $merged = self::merge(ThemeMapping::write($theme), $data);

        return ThemeMapping::read($merged, $file, $theme->textStyles());
    }
}
