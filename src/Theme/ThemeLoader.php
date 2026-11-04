<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\RetiredThemeFile;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnservedTemplate;
use MarkdownPresentation\Files\FileFinder;
use MarkdownPresentation\Filesystem\PathScope;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a theme out of a finder: the `theme.yml` and the files beside it.
 *
 * A theme is one file. `theme.yml` carries the look, the roles the theme maps and the
 * named styles a deck may write in, and `template.pptx` beside it is the theme's master:
 * it is opened as a file, so a source that holds only its bytes has them staged into one,
 * and nothing here reads what is inside it — a theme's master belongs to whoever renders
 * the deck rather than to the loader that found it.
 *
 * The files a theme used to be made of — `styles.yml`, `layouts.yml` and
 * `text-styles.yml` — are refused when they are beside it, each with a message saying
 * what the reader has to do: move the content into the section it became and delete the
 * file, or delete the file that belongs to the other half.
 *
 * The finder is asked by name — `house/theme.yml`, `house/template.pptx` — because the
 * library composes names and a finder answers them. A theme that never had a directory
 * is read the same way as one that did.
 *
 * Each of the theme's own files is resolved and held to the scope the loader was given
 * before it is read, and the scope is asked of the file rather than of the directory it
 * was found in: a file that is a symlink pointing out of the scope is read just
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

    /**
     * The sections a theme's file may carry beside the theme's own values.
     *
     * The theme's own keys are {@see ThemeMapping::KEYS}, and a key that is neither one
     * nor a section is refused: the typo-catching is the point of a closed vocabulary.
     *
     * Public because the refusal already names it — {@see MalformedTheme::unknownKey()}
     * offers the whole vocabulary — so a reader of the format is given the list and then
     * asked to find it somewhere else.
     */
    public const SECTIONS = ['layouts', 'textStyles'];

    /**
     * The files whose content has become a section of the theme's own file.
     *
     * The `styles.yml` is not among them: it is the Word half's, and this half has no
     * section for it.
     */
    private const MOVED = [
        self::LAYOUTS_FILE => 'layouts',
        self::TEXT_STYLES_FILE => 'textStyles',
    ];

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
     * @throws RetiredThemeFile when a file of the old format is beside the theme
     * @throws UnknownLayoutRole when the `layouts:` section names a role that does not exist
     * @throws UnservedTemplate when the theme carries a master the source cannot serve
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
     * theme's other files: a `theme.yml` named as the theme is the file the theme's own
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
     * @throws RetiredThemeFile when a file of the old format is beside the theme
     * @throws UnknownLayoutRole when the `layouts:` section names a role that does not exist
     * @throws UnservedTemplate when the theme carries a master the source cannot serve
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
     * The files the old format had beside the manifest are refused first: a theme that
     * carries one is a theme whose shape is wrong whatever its manifest says.
     *
     * @param array{contents: string, path: string} $manifest
     *
     * @throws MalformedTheme
     * @throws ThemeNotFound
     * @throws UnknownLayoutRole
     * @throws RetiredThemeFile
     * @throws Exception
     */
    private function read(array $manifest, string $name, string $written, ?FileFinder $files): LoadedTheme
    {
        $this->refuseRetired($name, $written, $files);

        $data = $this->mapping($manifest, 'a theme');
        $file = $manifest['path'];

        return new LoadedTheme(
            $name,
            dirname($file),
            Theme::fromArray(
                $this->values($data, $file),
                $file,
                $this->textStyles($data, $file),
            ),
            $this->layouts($data, $file),
            $this->template($name, $written, $files),
        );
    }

    /**
     * The files of the old format, refused when they are beside the theme.
     *
     * Each is asked of the finder and held to the scope like every other file of the
     * theme, so that a file resolving out of the roots is refused for that before it is
     * refused for what it is.
     *
     * @throws RetiredThemeFile
     * @throws ThemeNotFound
     * @throws Exception
     */
    private function refuseRetired(string $name, string $written, ?FileFinder $files): void
    {
        foreach (self::MOVED as $file => $section) {
            if ($this->file($files, $name, $file, $written) !== null) {
                throw RetiredThemeFile::moved($written, $file, $section);
            }
        }

        if ($this->file($files, $name, self::STYLES_FILE, $written) !== null) {
            throw RetiredThemeFile::otherHalf($written, self::STYLES_FILE);
        }
    }

    /**
     * The theme's own values, and the refusal of a key that is neither one nor a section.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     *
     * @throws MalformedTheme
     */
    private function values(array $data, string $file): array
    {
        $values = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (in_array($key, self::SECTIONS, true)) {
                continue;
            }

            if (!in_array($key, ThemeMapping::KEYS, true)) {
                throw MalformedTheme::unknownKey($file, $key, [...ThemeMapping::KEYS, ...self::SECTIONS]);
            }

            $values[$key] = $value;
        }

        return $values;
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
     * A path resolved and held to the scope.
     *
     * The path is resolved before it is held, because a prefix is not containment: `..`
     * and a symlink both defeat one, and a symlink pointing outside the roots is the
     * ordinary way a path that looks harmless turns into a read of the wrong file.
     *
     * Without a scope there is nothing to hold the path to, and the file is read on
     * trust: a theme this library ships is one, and so is one a caller named a directory
     * of its own. The path is resolved all the same, because the Word half's loader
     * resolves it and the two have to answer the same way.
     *
     * @return string|null The resolved path, or null when there is no such file.
     *
     * @throws ThemeNotFound when the file is somewhere this render may not read
     */
    private function contained(string $path, string $written): ?string
    {
        $real = PathScope::resolve($path);

        if ($real === null) {
            return null;
        }

        if ($this->scope !== null && !$this->scope->holds($real)) {
            throw ThemeNotFound::outside($written, $real, $this->scope->roots());
        }

        return $real;
    }

    /**
     * The theme's master, and the refusal of one the source cannot serve.
     *
     * The master is asked for by name like the rest of the theme's files. A source that
     * holds it as a file has it opened where it is — held to the scope first, because the
     * renderer opens whatever it is given, and a master that is a symlink out of the scope
     * is a file outside it read under a name that is inside. A source that holds only the
     * bytes has them staged into a file of the library's own.
     *
     * A master the theme carries and the source cannot serve is refused rather than drawn
     * without — a deck without the master's geometry is in the wrong design, with nothing
     * in it saying so. The listing of the theme's files tells a theme that carries no
     * master from a source that cannot serve one, and a path the source answers for a
     * master it cannot serve is the same case said the other way round.
     *
     * @throws UnservedTemplate when the theme carries a master the source cannot serve
     * @throws ThemeNotFound when the master resolves outside the directories this render may read
     */
    private function template(string $name, string $written, ?FileFinder $files): ?Master
    {
        $file = $name . '/' . self::TEMPLATE_FILE;

        $path = $files?->path($file, 'theme');

        if ($path !== null) {
            $real = $this->contained($path, $written);

            if ($real === null) {
                throw UnservedTemplate::named($written, self::TEMPLATE_FILE);
            }

            return Master::at($real, $file);
        }

        $bytes = $files?->read($file, 'theme');

        if ($bytes !== null) {
            return Master::of($bytes, $file);
        }

        if ($files !== null && in_array(self::TEMPLATE_FILE, $files->files($name), true)) {
            throw UnservedTemplate::named($written, self::TEMPLATE_FILE);
        }

        return null;
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
     * The role mappings the theme's file carries, or the library's own.
     *
     * A theme with a `layouts:` section has the whole mapping: a role the section does
     * not name is a role the theme does not map, and the failure names the roles it does
     * map. A theme with no section is drawn with the library's own mapping, which is the
     * one every theme shipping with this library carried in a file of its own.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     *
     * @throws UnknownLayoutRole
     */
    private function layouts(array $data, string $file): array
    {
        if (!array_key_exists('layouts', $data)) {
            return LayoutRole::defaults();
        }

        $mapping = $data['layouts'];

        if (!is_array($mapping) || ($mapping !== [] && array_is_list($mapping))) {
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

    /**
     * The named text styles the theme's file carries.
     *
     * A theme without a `textStyles:` section has none, and every named form in a deck
     * drawn with it is then a name the theme does not have.
     *
     * A style named twice is refused by {@see TextStyles} itself rather than here, because
     * the styles may also be built in code and the rule belongs to them.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    private function textStyles(array $data, string $file): TextStyles
    {
        if (!array_key_exists('textStyles', $data)) {
            return new TextStyles();
        }

        $styles = $data['textStyles'];

        if (!is_array($styles) || ($styles !== [] && array_is_list($styles))) {
            throw MalformedTheme::wrongType($file, 'textStyles', 'a mapping of style names to text styles');
        }

        $read = [];

        foreach ($styles as $style => $properties) {
            $style = (string) $style;

            if (TextStyle::isReserved($style)) {
                throw MalformedTheme::reservedStyle($file, $style);
            }

            if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                throw MalformedTheme::wrongType($file, $style, 'a mapping of attributes to values');
            }

            if ($properties === []) {
                throw MalformedTheme::emptyStyle($file, $style);
            }

            $read[$style] = $this->textStyle($file, $style, $properties);
        }

        return new TextStyles($read, $file);
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
