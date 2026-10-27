<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownStyleSlot;
use MarkdownPresentation\Filesystem\PathScope;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a theme out of a directory, or out of the manifest that describes one.
 *
 * Only `theme.yml` is required. `styles.yml` and `layouts.yml` are read when they
 * are there, and `template.pptx` is only a path: nothing here opens a `.pptx`,
 * because a theme's template belongs to whoever renders the deck rather than to the
 * loader that found it.
 *
 * Every file of a theme is resolved and held to the caller's {@see PathScope}
 * before it is read, and the template is held before it is named. Holding the
 * directory is not enough: a `theme.yml` that is a symlink pointing out of the scope
 * is read as readily as one inside it, and the `author:` it carries is copied into
 * `docProps/core.xml` of the deck — a way to get the contents of a file out through
 * a download.
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
     * @param string         $directory The directory the theme is read from.
     * @param string|null    $name      What the deck wrote, which is what a failure names.
     * @param PathScope|null $files     The directories a theme's own files may resolve into.
     *        Null takes the files inside a resolved directory on trust, which is what a
     *        theme this library ships is.
     *
     * @throws ThemeNotFound  When the directory has no `theme.yml`, so it is not a theme.
     * @throws MalformedTheme When a file in it does not describe a theme.
     * @throws Exception     When a file in it cannot be read or parsed, or resolves out of
     *                        the scope.
     */
    public function load(string $directory, ?string $name = null, ?PathScope $files = null): LoadedTheme
    {
        $written = $name ?? $directory;
        $manifest = $this->contained($files, $directory . '/' . self::THEME_FILE, $written);

        if (!is_file($manifest)) {
            throw ThemeNotFound::noManifest($directory);
        }

        return $this->read($manifest, $directory, $files, $written);
    }

    /**
     * Reads a theme out of the manifest that describes one, the rest of the theme read
     * from the directory holding it.
     *
     * The name the deck wrote is what makes the file the manifest, not the name the file
     * carries once it has been resolved: a `theme.yml` that is a link to a file of another
     * name is the manifest the deck asked for, and the file it reaches is read. The scope
     * still applies to that file, so a manifest reaching out of the roots is refused
     * whatever the deck called it.
     *
     * The Word half's loader asks the same question the same way, and the two have to
     * stay in step: a deck's theme resolves the same way whichever half renders it.
     *
     * @param string         $manifest The manifest itself.
     * @param string|null    $name     What the deck wrote, which is what a failure names.
     * @param PathScope|null $files    The directories a theme's own files may resolve into.
     *
     * @throws ThemeNotFound  When the file is not a theme manifest.
     * @throws MalformedTheme When the file does not describe a theme.
     * @throws Exception     When a file of the theme cannot be read or parsed, or resolves
     *                        out of the scope.
     */
    public function loadManifest(string $manifest, ?string $name = null, ?PathScope $files = null): LoadedTheme
    {
        $written = $name ?? $manifest;

        if (basename($written) !== self::THEME_FILE) {
            throw ThemeNotFound::noManifest($written);
        }

        $resolved = $this->contained($files, $manifest, $written);

        if (!is_file($resolved)) {
            throw ThemeNotFound::noManifest($written);
        }

        // The directory the manifest is in, rather than the one the deck wrote: a
        // manifest reached through a symlink sits where its target is, and that is
        // where the `styles.yml` that belongs to it is.
        return $this->read($resolved, dirname($resolved), $files, $written);
    }

    /**
     * The theme a manifest describes, with the rest of it read from beside the manifest.
     *
     * @throws MalformedTheme
     * @throws Exception
     * @throws ThemeNotFound
     */
    private function read(string $manifest, string $directory, ?PathScope $files, string $name): LoadedTheme
    {
        return new LoadedTheme(
            $directory,
            Theme::fromArray($this->mapping($manifest, 'a theme'), $manifest),
            $this->styles($directory, $files, $name),
            $this->layouts($directory, $files, $name),
            $this->template($directory, $files, $name),
        );
    }

    /**
     * A file of the theme, refused when it resolves outside the scope.
     *
     * The path is returned as it stands when there is nothing to check, so a theme with
     * no `styles.yml` costs no more than the one `file_exists()` call.
     *
     * @throws ThemeNotFound When the file is there but is somewhere this render may not read.
     */
    private function contained(?PathScope $files, string $path, string $name): string
    {
        if ($files === null || !file_exists($path)) {
            return $path;
        }

        $real = PathScope::resolve($path);

        if ($real === null || !$files->holds($real)) {
            throw ThemeNotFound::outside($name, $real ?? $path, $files->roots());
        }

        return $real;
    }

    /**
     * The theme's template, held to the scope before it is named.
     *
     * The path is what the renderer opens, and it opens whatever it is given: a
     * template that is a symlink out of the scope is a file outside it, read under a
     * name that is inside.
     *
     * @throws ThemeNotFound
     */
    private function template(string $directory, ?PathScope $files, string $name): ?string
    {
        $file = $this->contained($files, $directory . '/' . self::TEMPLATE_FILE, $name);

        return is_file($file) ? $file : null;
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
     *
     * @throws ThemeNotFound
     * @throws Exception
     */
    private function styles(string $directory, ?PathScope $files, string $name): array
    {
        $file = $this->contained($files, $directory . '/' . self::STYLES_FILE, $name);

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
     *
     * @throws ThemeNotFound
     * @throws Exception
     */
    private function layouts(string $directory, ?PathScope $files, string $name): array
    {
        $file = $this->contained($files, $directory . '/' . self::LAYOUTS_FILE, $name);

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
