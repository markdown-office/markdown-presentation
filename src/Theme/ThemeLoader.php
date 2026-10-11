<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownStyleSlot;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a theme out of a directory.
 *
 * Only `theme.yml` is required. `styles.yml` and `layouts.yml` are read when they
 * are there, and `template.pptx` is only a path: nothing here opens a `.pptx`,
 * because a theme's template belongs to whoever renders the deck rather than to the
 * loader that found it.
*/
final class ThemeLoader
{
    public const THEME_FILE = 'theme.yml';
    public const STYLES_FILE = 'styles.yml';
    public const LAYOUTS_FILE = 'layouts.yml';
    public const TEMPLATE_FILE = 'template.pptx';

    /** The only key a layouts file may put around its mappings. */
    private const LAYOUTS_KEY = 'layouts';
    /**
     * @throws ThemeNotFound  When the directory has no `theme.yml`, so it is not a theme.
     * @throws MalformedTheme When a file in it does not describe a theme.
     * @throws Exception     When a file in it cannot be read or parsed.
    */
    public function load(string $directory): LoadedTheme
    {
        $manifest = $directory . '/' . self::THEME_FILE;

        if (!is_file($manifest)) {
            throw ThemeNotFound::noManifest($directory);
        }

        return new LoadedTheme(
            $directory,
            Theme::fromArray($this->mapping($manifest, 'a theme'), $manifest),
            $this->styles($directory),
            $this->layouts($directory),
            is_file($template = $directory . '/' . self::TEMPLATE_FILE) ? $template : null,
        );
    }

    /**
     * @return array<array-key, mixed>
    */
    private function mapping(string $file, string $wanted): array
    {
        if (!is_readable($file)) {
            throw new Exception(sprintf('Unable to read "%s".', $file));
        }

        try {
            // `parseFile()` rather than reading the file and handing the bytes over:
            // it checks that the file is there and readable itself, so a directory
            // that lost a file between two calls fails as a read rather than as YAML
            // nobody can parse.
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new Exception(
                sprintf('"%s" is not valid YAML: %s', $file, $exception->getMessage()),
                previous: $exception,
            );
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw MalformedTheme::notAMapping($file, $wanted, self::describe($parsed));
        }

        /** @var array<array-key, mixed> $parsed */
        return $parsed;
    }

    /** What a YAML document turned out to be, in the words the failure uses. */
    private static function describe(mixed $parsed): string
    {
        return is_array($parsed) ? 'a list' : 'a ' . get_debug_type($parsed);
    }

    /**
     * The Word style slot overrides, every slot checked against the slots the Word
     * half really has.
     *
     * @return array<string, mixed>
    */
    private function styles(string $directory): array
    {
        $file = $directory . '/' . self::STYLES_FILE;

        if (!is_file($file)) {
            return [];
        }

        $styles = [];

        foreach ($this->mapping($file, 'a mapping of style slots to styles') as $slot => $style) {
            $slot = (string) $slot;

            if (!in_array($slot, StyleSlots::names(), true)) {
                throw UnknownStyleSlot::named($file, $slot, StyleSlots::names());
            }

            $styles[$slot] = $style;
        }

        return $styles;
    }

    /**
     * The role mappings, every role checked against the portable roles. The
     * mappings may stand on their own or under a `layouts:` key, because the concept
     * draft writes the second and a bare mapping is what a person types.
     *
     * @return array<string, string>
    */
    private function layouts(string $directory): array
    {
        $file = $directory . '/' . self::LAYOUTS_FILE;

        if (!is_file($file)) {
            return [];
        }

        $mapping = $this->mapping($file, 'a mapping of layout roles to layout names');

        if (isset($mapping[self::LAYOUTS_KEY])) {
            foreach (array_keys($mapping) as $key) {
                if ((string) $key !== self::LAYOUTS_KEY) {
                    throw UnknownLayoutRole::unknownKey($file, (string) $key, [self::LAYOUTS_KEY]);
                }
            }

            $mapping = $mapping[self::LAYOUTS_KEY];
        }

        if (!is_array($mapping)) {
            throw UnknownLayoutRole::notTheMappings($file);
        }

        $layouts = [];

        foreach ($mapping as $role => $layout) {
            $role = (string) $role;

            if (LayoutRole::tryFrom($role) === null) {
                throw UnknownLayoutRole::notARole($role, LayoutRole::names());
            }

            if (!is_string($layout) || trim($layout) === '') {
                throw UnknownLayoutRole::notALayoutName($file, $role);
            }

            $layouts[$role] = trim($layout);
        }

        return $layouts;
    }
}