<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\TextStyle;
use MarkdownPresentation\Theme\ThemeLoader;

/**
 * The theme's named text styles: what a `text-styles.yml` says, and what the loader makes
 * of it.
 *
 * The styles are a theme's own vocabulary, and the loader is where a mistake in one is
 * caught: a theme that names an attribute nobody reads is a theme nobody wanted.
 */

/**
 * A theme directory with the named text styles beside the manifest.
 *
 * The manifest is the library's own, because the styles are what these tests are about and
 * a theme that shipped with one would put a second claim into every assertion.
 */
function themeWithTextStyles(string $styles): string
{
    return ThemeDirectory::acme(['text-styles.yml' => $styles], 'text-style');
}

it('reads the named styles a theme carries', function () {
    $loaded = (new ThemeLoader())->load(themeWithTextStyles("brand:\n  color: accent1\n  size: 20\n"));

    expect($loaded->theme()->textStyles()->names())->toBe(['brand'])
        ->and($loaded->theme()->textStyles()->get('brand')?->colour)->toBe('accent1')
        ->and($loaded->theme()->textStyles()->get('brand')?->size)->toBe(20)
        ->and($loaded->theme()->textStyles()->get('muted'))->toBeNull();
});

it('refuses a text style that sets an attribute that is not one, when the theme is read', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  colour: accent1\n")))
        ->toThrow(MalformedTheme::class, 'which is not an attribute of a text style');
});

it('refuses a text style that styles nothing, when the theme is read', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand: {}\n")))
        ->toThrow(MalformedTheme::class, 'sets nothing');
});

it('refuses a text style named with the word the raw form is written with', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("style:\n  color: accent1\n")))
        ->toThrow(MalformedTheme::class, 'is named with a reserved word');
});

it('refuses a text style that sets a highlight, which this half does not draw', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  highlight: FFFF00\n")))
        ->toThrow(MalformedTheme::class, 'which the slides half does not draw');
});

it('refuses a text style whose colour is not a colour', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  color: chartreuse\n")))
        ->toThrow(MalformedTheme::class, 'is not a colour: none, a role, a theme slot, or a hex value');
});

it('refuses a text style whose size is not a size', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  size: big\n")))
        ->toThrow(MalformedTheme::class, 'is not a number of points');
});

it('refuses a text style whose weight is not a weight', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  bold: yes\n")))
        ->toThrow(MalformedTheme::class, 'is not true or false');
});

it('refuses a text style whose face is not a name', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  font: 12\n")))
        ->toThrow(MalformedTheme::class, 'is not a font name');
});

it('refuses a text style that is not a mapping of attributes', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  - color\n")))
        ->toThrow(MalformedTheme::class, 'is not a mapping of attributes to values');
});

it('reads the face and the weight a style names', function () {
    $loaded = (new ThemeLoader())->load(themeWithTextStyles("brand:\n  font: Inter\n  bold: true\n"));

    expect($loaded->theme()->textStyles()->get('brand')?->font)->toBe('Inter')
        ->and($loaded->theme()->textStyles()->get('brand')?->bold)->toBeTrue();
});

it('lays one text style over another, property by property', function () {
    $style = new TextStyle(colour: 'accent1', size: 20, bold: true);

    // The other wins for the properties it names, and the ones it does not name are left
    // as this one had them.
    expect($style->with(['size' => 30])->size)->toBe(30)
        ->and($style->with(['size' => 30])->colour)->toBe('accent1')
        ->and($style->with(['size' => 30])->bold)->toBeTrue()
        ->and($style->with(['bold' => false])->bold)->toBeFalse();
});
