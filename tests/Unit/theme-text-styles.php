<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\TextStyle;
use MarkdownPresentation\Theme\Theme;
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

it('refuses a text style named with the reserved word in another case', function () {
    // The reserved word is folded with the rest of the names, because `Style` is the same
    // word as `style`.
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("Style:\n  color: accent1\n")))
        ->toThrow(MalformedTheme::class, 'is named with a reserved word');
});

it('refuses a theme that names one text style twice, in two spellings', function () {
    // The names are folded, so `Brand` and `brand` are one name: a theme carrying both has
    // two styles a deck cannot tell apart, and the one a deck reaches is the one written
    // last.
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("Brand:\n  color: accent1\nbrand:\n  color: accent2\n")))
        ->toThrow(MalformedTheme::class, 'is the same style as');
});

it('reads a text style the theme spells with a capital letter, under the folded name', function () {
    $loaded = (new ThemeLoader())->load(themeWithTextStyles("Brand:\n  color: accent1\n"));

    expect($loaded->theme()->textStyles()->get('brand')?->colour)->toBe('accent1')
        ->and($loaded->theme()->textStyles()->get('Brand')?->colour)->toBe('accent1')
        ->and($loaded->theme()->textStyles()->names())->toBe(['Brand']);
});

it('refuses a text style that sets a highlight, which this half does not draw', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  highlight: FFFF00\n")))
        ->toThrow(MalformedTheme::class, 'which the slides half does not draw');
});

it('refuses a text style whose colour is not a colour', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  color: chartreuse\n")))
        ->toThrow(MalformedTheme::class, '"brand.color" is "chartreuse", which is not a colour')
        ->toThrow(MalformedTheme::class, 'A colour is either no colour at all (none or transparent), a role (page, ink, subtle, panel)');
});

it('accepts a colour written as one of the names the concept draft used', function () {
    // `dark1` is the concept draft's name for `dk1`, and a theme that names a colour the
    // old way means the same colour: the spelling is not the thing being refused.
    $loaded = (new ThemeLoader())->load(themeWithTextStyles("brand:\n  color: dark1\n"));

    expect($loaded->theme()->textStyles()->get('brand')?->colour)->toBe('dark1');
});

it('accepts a colour written in capitals, as a deck may write one', function () {
    // A deck's own colour is lowercased before it is resolved, so `DK1` and `dk1` are one
    // name there; a theme's file is written against the same vocabulary as a deck's
    // attribute, so it is one name here too.
    $loaded = (new ThemeLoader())->load(themeWithTextStyles("brand:\n  color: DK1\n"));

    expect($loaded->theme()->textStyles()->get('brand')?->colour)->toBe('DK1');
});

it('names the names the concept draft used in the advice', function () {
    // The advice is what a reader is shown when they get a colour wrong, and it has to name
    // every colour that would have been right — including the six the concept draft used,
    // which are names like any other.
    expect(Theme::colourAdvice())
        ->toContain('dark1, light1, dark2, light2, hyperlink, followedHyperlink');
});

it('names every colour that would have been right in the advice, and reads as a sentence', function () {
    // The advice is built from the list the colours are accepted under, so the two cannot
    // drift: every name a colour may be written under has to be in it, and so do the two
    // spellings of no colour at all — which mean the absence of one and are named apart
    // from the colours rather than beside them, because a list that began with them read
    // as though "none" were a colour like the rest.
    $advice = Theme::colourAdvice();

    foreach ([...Theme::colourNames(), ...Theme::NO_COLOUR] as $name) {
        expect($advice)->toContain($name);
    }

    expect($advice)->toContain('#4C1D95')
        ->and($advice)->toStartWith('A colour is ')
        ->and($advice)->toEndWith('.');
});

it('names the two spellings of no colour apart from the colours in the advice', function () {
    // "none" and "transparent" are not colours, and the sentence says so where they are
    // named rather than listing them among the colours.
    expect(Theme::colourAdvice())->toContain('no colour at all (none or transparent)');
});

it('refuses a text style whose colour is not a name at all', function () {
    // A number is not a spelling of a colour, so it is refused as what it is rather than as
    // a name nobody has heard of: the advice would not help.
    $loader = new ThemeLoader();

    expect(fn () => $loader->load(themeWithTextStyles("brand:\n  color: 123\n")))
        ->toThrow(MalformedTheme::class, '"brand.color" is not a colour');
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
