<?php

declare(strict_types=1);

namespace MarkdownPresentation\Document;

use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Theme\ThemeVariables;

/**
 * The settings a slide names for itself: the background it is drawn on, and the theme's
 * values for it.
 *
 * A template definition holds them too, and the two are read the same way, because a
 * template's settings are the settings of the slides that draw it.
 *
 * The settings are carried as they were written and read where they are drawn, because a
 * colour is resolved against the theme the slide is drawn in, and a background of one theme
 * is not the background of another.
 */
final class Settings
{
    /**
     * The keys a slide's own settings are made of.
     *
     * The list is one, and {@see \MarkdownPresentation\Render\PresentationRenderer::DIRECTIVES}
     * is built from it, so that a key which is a setting is a key the frontmatter accepts.
     *
     * @var list<string>
     */
    public const KEYS = [
        'background',
        'background-gradient',
        'background-angle',
        'background-radial',
        'theme-variables',
    ];

    /**
     * The keys the background is made of, which are one setting between them.
     *
     * @var list<string>
     */
    public const BACKGROUND = ['background', 'background-gradient', 'background-angle', 'background-radial'];

    /**
     * The settings a frontmatter holds.
     *
     * The background's four keys are one setting and are carried as one, and the theme's
     * values are carried as they were written: a value that is a colour is a colour the
     * theme resolves, and the theme that resolves it is the one the slide is drawn in.
     *
     * @param array<string, mixed> $frontmatter
     *
     * @return array{background?: array<string, mixed>, variables?: array<string, mixed>}
     *
     * @throws MalformedFrontmatter
     */
    public static function read(array $frontmatter, int $slide): array
    {
        $settings = [];

        $background = [];
        foreach (self::BACKGROUND as $key) {
            if (array_key_exists($key, $frontmatter)) {
                $background[$key] = $frontmatter[$key];
            }
        }

        if ($background !== []) {
            $settings['background'] = $background;
        }

        if (array_key_exists('theme-variables', $frontmatter)) {
            $variables = $frontmatter['theme-variables'];

            if (!is_array($variables) || ($variables !== [] && array_is_list($variables))) {
                throw MalformedFrontmatter::wrongType(
                    $slide,
                    'theme-variables',
                    'a mapping of the theme\'s values, as a theme.yml holds them',
                );
            }

            $settings['variables'] = $variables;
        }

        return $settings;
    }

    /**
     * The settings of both, with the one laid over the other winning.
     *
     * The background is **one** setting: a slide that names a gradient over a template's
     * colour has a gradient, and not a gradient and a colour.
     *
     * The theme's values are merged name by name, so that a slide that names one colour
     * leaves the other eleven alone.
     *
     * @param array{background?: array<string, mixed>, variables?: array<string, mixed>} $under
     * @param array{background?: array<string, mixed>, variables?: array<string, mixed>} $over
     *
     * @return array{background?: array<string, mixed>, variables?: array<string, mixed>}
     */
    public static function merge(array $under, array $over): array
    {
        $merged = [];

        $background = $over['background'] ?? $under['background'] ?? null;
        if ($background !== null) {
            $merged['background'] = $background;
        }

        if (isset($over['variables'])) {
            $merged['variables'] = ThemeVariables::merge($under['variables'] ?? [], $over['variables']);
        } elseif (isset($under['variables'])) {
            $merged['variables'] = $under['variables'];
        }

        return $merged;
    }
}
