<?php

declare(strict_types=1);

namespace MarkdownPresentation\Document;

use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Theme\ThemeVariables;

/**
 * The settings a slide names for itself: the background it is drawn on, and the theme's
 * values for it.
 *
 * @phpstan-type Shape array{background?: array<string, mixed>, variables?: array<string, mixed>}
 *
 * A template definition holds them too, and the two are read the same way, because a
 * template's settings are the settings of the slides that draw it. The deck holds them as
 * well, and every slide of the deck is drawn with them.
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
     * The list is the settings' own, and {@see \MarkdownPresentation\Render\PresentationRenderer::DIRECTIVES}
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
     * @param int|null             $slide    The slide the frontmatter is of, or null where it
     *                                       is a template definition's — a definition is not a
     *                                       slide of the deck and has no number.
     *
     * @return Shape
     *
     * @throws MalformedFrontmatter
     */
    public static function read(array $frontmatter, ?int $slide): array
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
     * The settings a template definition carries, as the frontmatter named them.
     *
     * A definition's settings are the template's, and they are named as the template's:
     * `template-settings:` holds the same keys a slide's own settings are written in, so
     * that the ordinary keys mean the slide's own everywhere. A slide that draws a template
     * may therefore name a background of its own, and is a slide for it.
     *
     * @return Shape
     *
     * @throws MalformedFrontmatter
     */
    public static function readTemplate(mixed $written): array
    {
        if (!is_array($written) || ($written !== [] && array_is_list($written))) {
            throw MalformedFrontmatter::wrongType(
                null,
                'template-settings',
                'a mapping of the settings a template carries',
            );
        }

        return self::readNamed(null, 'template-settings', $written);
    }

    /**
     * The settings the deck carries, as its frontmatter named them.
     *
     * The deck's settings are the deck's, and they are named as the deck's:
     * `deck-settings:` holds the same keys a slide's own settings are written in, so that
     * the ordinary keys mean the slide's own everywhere. Every slide of the deck is drawn
     * with them, and a slide's own settings are laid over them.
     *
     * @return Shape
     *
     * @throws MalformedFrontmatter
     */
    public static function readDeck(int $slide, mixed $written): array
    {
        // A list is not a mapping of settings, and an empty one is a key that is there with
        // nothing under it: both are mistakes rather than settings.
        if (!is_array($written) || $written === [] || array_is_list($written)) {
            throw MalformedFrontmatter::wrongType(
                $slide,
                'deck-settings',
                'a mapping of the settings the deck carries',
            );
        }

        return self::readNamed($slide, 'deck-settings', $written);
    }

    /**
     * The settings a block of somebody else's names, under the names they are known by.
     *
     * A name that is not one of the settings is refused rather than left out, so that a
     * typo in a deck's or a template's settings is a failure rather than a setting that
     * does nothing.
     *
     * @param array<array-key, mixed> $written
     *
     * @return Shape
     *
     * @throws MalformedFrontmatter
     */
    private static function readNamed(?int $slide, string $where, array $written): array
    {
        $settings = [];

        foreach ($written as $key => $value) {
            if (!is_string($key) || !in_array($key, self::KEYS, true)) {
                throw MalformedFrontmatter::unknownSetting($slide, $where, (string) $key, self::KEYS);
            }

            $settings[$key] = $value;
        }

        return self::read($settings, $slide);
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
     * @param Shape $under
     * @param Shape $over
     *
     * @return Shape
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
