<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownStyleSlot;
use MarkdownPresentation\Files\FileFinder;
use MarkdownPresentation\Filesystem\PathScope;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a theme out of a finder: the `theme.yml` and the files beside it.
 *
 * Only `theme.yml` is required. `styles.yml`, `layouts.yml` and `text-styles.yml` are
 * read when they are there, and `template.pptx` is only a path: nothing here opens a
 * `.pptx`, because a theme's template belongs to whoever renders the deck rather than to
 * the loader that found it.
 *
 * The finder is asked by name — `house/theme.yml`, `house/styles.yml` — because the
 * library composes names and a finder answers them. A theme that never had a directory
 * is read the same way as one that did.
 *
 * Each of the theme's own files is resolved and held to the scope the loader was given
 * before it is read, and the scope is asked of the file rather than of the directory it
 * was found in: a `styles.yml` that is a symlink pointing out of the scope is read just
 * as readily as one inside it, and the `author:` the manifest carries is copied into
 * `docProps/core.xml` of the deck — a way to get the contents of a file out through a
 * download.
 */
final class ThemeLoader
{
    public const THEME_FILE = 'theme.yml';
    public const STYLES_FILE = 'styles.yml';
    public const LAYOUTS_FILE = 'layouts.yml';
    public const TEXT_STYLES_FILE = 'text-styles.yml';
    public const TEMPLATE_FILE = 'template.pptx';

    /** The only key a layouts file may put around its mappings. */
    private const LAYOUTS_KEY = 'layouts';

    /** The smallest a size of type may be, in points. */
    private const MINIMUM_POINTS = 0.5;

    /**
     * @param PathScope|null $scope The directories a theme's own files may resolve into.
     *        Null takes the files the finder answers on trust, which is what a theme
     *        this library ships is.
     */
    public function __construct(private readonly ?PathScope $scope = null)
    {
    }

    /**
     * A theme read by the name it is known by.
     *
     * @param string $name The theme's name, which the finder resolves the theme's files by.
     * @param string|null $written What the deck wrote, which is what a failure names.
     * @param FileFinder|null $files Where the theme's files come from.
     *
     * @throws ThemeNotFound  when the theme has no `theme.yml`, so it is not a theme
     * @throws MalformedTheme  when a file in it does not describe a theme
     * @throws UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws UnknownLayoutRole when `layouts.yml` names a role that does not exist
     * @throws Exception      when a file in it cannot be read or parsed, or resolves out
     *                        of the scope
     */
    public function load(string $name, ?string $written = null, ?FileFinder $files = null): LoadedTheme
    {
        $written ??= $name;

        $manifest = $this->file($files, $name, self::THEME_FILE, $written);

        if ($manifest === null) {
            throw ThemeNotFound::noManifest($written);
        }

        return $this->read($manifest, $name, $written, $files);
    }

    /**
     * A theme whose manifest is a file of its own, with the rest of it read from beside
     * the manifest.
     *
     * The name the deck wrote is what makes the file the manifest rather than one of the
     * theme's other files: a `styles.yml` named as the theme is a file the theme's own
     * directory holds, not the theme.
     *
     * The Word half's loader asks the same question the same way, and the two have to
     * stay in step: a deck's theme resolves the same way whichever half renders it.
     *
     * @param string $name The theme's name, which the finder resolves the manifest by.
     * @param string|null $written What the deck wrote, which is what a failure names.
     * @param FileFinder|null $files Where the theme's files come from.
     *
     * @throws ThemeNotFound  when the file is not there, or is not a `theme.yml` and so
     *                        is not a manifest
     * @throws MalformedTheme  when the file does not describe a theme
     * @throws UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws UnknownLayoutRole when `layouts.yml` names a role that does not exist
     * @throws Exception      when a file in it cannot be read or parsed, or resolves out
     *                        of the scope
     */
    public function loadManifest(string $name, ?string $written = null, ?FileFinder $files = null): LoadedTheme
    {
        $written ??= $name;

        if (basename($written) !== self::THEME_FILE) {
            throw ThemeNotFound::noManifest($written);
        }

        $manifest = $this->file($files, $name, self::THEME_FILE, $written);

        if ($manifest === null) {
            throw ThemeNotFound::noManifest($written);
        }

        return $this->read($manifest, $name, $written, $files);
    }

    /**
     * The theme a manifest describes, with the rest of it read from beside the manifest.
     *
     * @param array{contents: string, path: string} $manifest
     *
     * @throws MalformedTheme
     * @throws ThemeNotFound
     * @throws UnknownLayoutRole
     * @throws UnknownStyleSlot
     * @throws Exception
     */
    private function read(array $manifest, string $name, string $written, ?FileFinder $files): LoadedTheme
    {
        return new LoadedTheme(
            dirname($manifest['path']),
            Theme::fromArray(
                $this->mapping($manifest, 'a theme'),
                $manifest['path'],
                $this->textStyles($name, $written, $files),
            ),
            $this->styles($name, $written, $files),
            $this->layouts($name, $written, $files),
            $this->template($name, $written, $files),
        );
    }

    /**
     * One of the theme's own files, resolved and held to the scope.
     *
     * A finder with the bytes and no path is asked for them: the caller vouches for its
     * own files, and there is no path to hold to the scope. A loader with no finder has
     * no source at all, and the file is not there.
     *
     * @return array{contents: string, path: string}|null
     *
     * @throws Exception when the file resolves outside the directories this render may read
     */
    private function file(?FileFinder $files, string $theme, string $file, string $written): ?array
    {
        $name = $theme . '/' . $file;
        $path = $files?->path($name, 'theme');

        if ($path === null) {
            $contents = $files?->read($name, 'theme');

            return $contents === null ? null : ['contents' => $contents, 'path' => $name];
        }

        $real = $this->contained($path, $written);

        if ($real === null) {
            return null;
        }

        // `is_readable()` first, so a file that cannot be read is reported as that rather
        // than as a warning the test runner turns into a failure of its own.
        $contents = is_readable($real) ? @file_get_contents($real) : false;

        if ($contents === false) {
            throw new Exception(sprintf('Unable to read "%s".', $real));
        }

        return ['contents' => $contents, 'path' => $real];
    }

    /**
     * A path held to the scope, and the file it names.
     *
     * The path is resolved before it is held, because a prefix is not containment: `..`
     * and a symlink both defeat one, and a symlink pointing outside the roots is the
     * ordinary way a path that looks harmless turns into a read of the wrong file.
     *
     * Without a scope there is nothing to hold the path to, and it is taken as the
     * finder answered it: a theme this library ships is read on trust, and so is one a
     * caller named a directory of its own.
     *
     * @return string|null The path of the file, or null when there is none.
     *
     * @throws ThemeNotFound when the file is somewhere this render may not read
     */
    private function contained(string $path, string $written): ?string
    {
        if ($this->scope === null) {
            return file_exists($path) ? $path : null;
        }

        $real = PathScope::resolve($path);

        if ($real === null) {
            return null;
        }

        if (!$this->scope->holds($real)) {
            throw ThemeNotFound::outside($written, $real, $this->scope->roots());
        }

        return $real;
    }

    /**
     * The theme's template, held to the scope before it is named.
     *
     * The path is what the renderer opens, and it opens whatever it is given: a template
     * that is a symlink out of the scope is a file outside it, read under a name that is
     * inside.
     *
     * @throws ThemeNotFound
     */
    private function template(string $name, string $written, ?FileFinder $files): ?string
    {
        $path = $files?->path($name . '/' . self::TEMPLATE_FILE, 'theme');

        if ($path === null) {
            return null;
        }

        return $this->contained($path, $written);
    }

    /**
     * @param array{contents: string, path: string} $file
     *
     * @return array<array-key, mixed>
     */
    private function mapping(array $file, string $wanted): array
    {
        try {
            $parsed = Yaml::parse($file['contents']);
        } catch (ParseException $exception) {
            throw new Exception(
                sprintf('"%s" is not valid YAML: %s', $file['path'], $exception->getMessage()),
                previous: $exception,
            );
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw MalformedTheme::notAMapping($file['path'], $wanted, self::describe($parsed));
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
    private function styles(string $name, string $written, ?FileFinder $files): array
    {
        $file = $this->file($files, $name, self::STYLES_FILE, $written);

        if ($file === null) {
            return [];
        }

        $styles = [];

        foreach ($this->mapping($file, 'a mapping of style slots to styles') as $slot => $style) {
            $slot = (string) $slot;

            if (!in_array($slot, StyleSlots::names(), true)) {
                throw UnknownStyleSlot::named($file['path'], $slot, StyleSlots::names());
            }

            $styles[$slot] = $style;
        }

        return $styles;
    }

    /**
     * The role mappings, every role checked against the portable roles. The mappings may
     * stand on their own or under a `layouts:` key, because the concept draft writes the
     * second and a bare mapping is what a person types.
     *
     * @return array<string, string>
     *
     * @throws ThemeNotFound
     * @throws Exception
     */
    private function layouts(string $name, string $written, ?FileFinder $files): array
    {
        $file = $this->file($files, $name, self::LAYOUTS_FILE, $written);

        if ($file === null) {
            return [];
        }

        $mapping = $this->mapping($file, 'a mapping of layout roles to layout names');

        if (isset($mapping[self::LAYOUTS_KEY])) {
            foreach (array_keys($mapping) as $key) {
                if ((string) $key !== self::LAYOUTS_KEY) {
                    throw UnknownLayoutRole::unknownKey($file['path'], (string) $key, [self::LAYOUTS_KEY]);
                }
            }

            $mapping = $mapping[self::LAYOUTS_KEY];
        }

        if (!is_array($mapping)) {
            throw UnknownLayoutRole::notTheMappings($file['path']);
        }

        $layouts = [];

        foreach ($mapping as $role => $layout) {
            $role = (string) $role;

            if (LayoutRole::tryFrom($role) === null) {
                throw UnknownLayoutRole::notARole($role, LayoutRole::names());
            }

            if (!is_string($layout) || trim($layout) === '') {
                throw UnknownLayoutRole::notALayoutName($file['path'], $role);
            }

            $layouts[$role] = trim($layout);
        }

        return $layouts;
    }

    /**
     * The named text styles a theme carries, read from its `text-styles.yml`.
     *
     * The file is optional: a theme without one has no named styles, and every named form
     * in a deck drawn with it is then a name the theme does not have. It is held to the
     * scope like every other file of the theme, because it is one of them.
     *
     * A style named twice is refused by {@see TextStyles} itself rather than here, because
     * the styles may also be built in code and the rule belongs to them.
     *
     * @throws ThemeNotFound
     * @throws Exception
     * @throws MalformedTheme
     */
    private function textStyles(string $name, string $written, ?FileFinder $files): TextStyles
    {
        $file = $this->file($files, $name, self::TEXT_STYLES_FILE, $written);

        if ($file === null) {
            return new TextStyles();
        }

        $styles = [];

        foreach ($this->mapping($file, 'a mapping of style names to text styles') as $style => $properties) {
            $style = (string) $style;

            if (TextStyle::isReserved($style)) {
                throw MalformedTheme::reservedStyle($file['path'], $style);
            }

            if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                throw MalformedTheme::wrongType($file['path'], $style, 'a mapping of attributes to values');
            }

            if ($properties === []) {
                throw MalformedTheme::emptyStyle($file['path'], $style);
            }

            $styles[$style] = $this->textStyle($file['path'], $style, $properties);
        }

        return new TextStyles($styles, $file['path']);
    }

    /**
     * One named style, as the properties it carries.
     *
     * @param array<array-key, mixed> $properties
     */
    private function textStyle(string $file, string $name, array $properties): TextStyle
    {
        $read = [];

        foreach ($properties as $property => $value) {
            $property = (string) $property;

            if (!in_array($property, TextStyle::ATTRIBUTES, true)) {
                throw MalformedTheme::unknownAttribute($file, $name, $property, TextStyle::ATTRIBUTES);
            }

            if ($property === TextStyle::UNSUPPORTED) {
                throw MalformedTheme::unsupportedAttribute($file, $name, $property);
            }

            $read[$property] = $value;
        }

        return new TextStyle(
            self::colour($file, $name, $read['color'] ?? null),
            self::size($file, $name, $read['size'] ?? null),
            self::family($file, $name, $read['font'] ?? null),
            self::flag($file, $name, 'bold', $read['bold'] ?? null),
            self::flag($file, $name, 'italic', $read['italic'] ?? null),
            self::flag($file, $name, 'underline', $read['underline'] ?? null),
            self::flag($file, $name, 'strike', $read['strike'] ?? null),
        );
    }

    /**
     * The ink a style is drawn in, as it was written.
     *
     * A role, a theme slot or a hex value — the same vocabulary a shape's own colour is
     * written in, because a run's ink and a shape's fill are the same question. The
     * failure names the same list the shape's own colour names, because the two are one
     * question and two answers to it would be two vocabularies.
     */
    private static function colour(string $file, string $name, mixed $written): ?string
    {
        if ($written === null) {
            return null;
        }

        // A number is not a spelling of a colour, so it is refused as what it is rather
        // than as a name nobody has heard of: the advice would not help.
        if (!is_string($written)) {
            throw MalformedTheme::wrongType($file, $name . '.color', 'a colour');
        }

        if (!Theme::hasColour($written)) {
            throw MalformedTheme::wrongColour($file, $name . '.color', $written, Theme::colourAdvice());
        }

        return $written;
    }

    /**
     * The size of the type a style is drawn at, in points.
     */
    private static function size(string $file, string $name, mixed $written): ?int
    {
        if ($written === null) {
            return null;
        }

        if ((!is_int($written) && !is_float($written)) || $written < self::MINIMUM_POINTS) {
            throw MalformedTheme::wrongType($file, $name . '.size', 'a number of points, at least half a point');
        }

        return (int) round($written);
    }

    /**
     * The family a style is drawn in, as it was written.
     *
     * A slot the theme has not set falls through to being read as a literal, which is the
     * outcome rather than a refusal: `font: Inter` is a real answer whether or not the
     * theme names it.
     */
    private static function family(string $file, string $name, mixed $written): ?string
    {
        if ($written === null) {
            return null;
        }

        if (!is_string($written) || trim($written) === '') {
            throw MalformedTheme::wrongType($file, $name . '.font', 'a font name');
        }

        return trim($written);
    }

    /**
     * A flag a style sets or does not set.
     *
     * Only `true` and `false`, because `yes` is a YAML 1.1 truthy string rather than the
     * flag and a file that means something else by it is a file nobody can read.
     */
    private static function flag(string $file, string $name, string $property, mixed $written): ?bool
    {
        if ($written === null) {
            return null;
        }

        if (!is_bool($written)) {
            throw MalformedTheme::wrongType($file, $name . '.' . $property, 'true or false');
        }

        return $written;
    }
}
