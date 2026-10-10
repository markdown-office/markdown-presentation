<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\StyleSlots;
use MarkdownPresentation\Theme\Theme;

it('reads a theme, and keeps the twelve colours in DrawingML order', function () {
    $theme = Theme::fromArray([
        'name' => 'Corporate Dark',
        'description' => 'Dark corporate deck.',
        'author' => 'markdown-office',
        'colors' => ['accent1' => '#89B4FA', 'dk1' => '#1E1E2E'],
        'fonts' => ['heading' => 'Inter'],
    ]);

    expect($theme->name())->toBe('Corporate Dark')
        ->and($theme->description())->toBe('Dark corporate deck.')
        ->and($theme->author())->toBe('markdown-office')
        ->and(array_keys($theme->colours()))->toBe(Theme::COLOURS)
        ->and($theme->colour('dk1'))->toBe('#1E1E2E')
        ->and($theme->colour('accent1'))->toBe('#89B4FA')
        ->and($theme->font('heading'))->toBe('Inter');
});

it('fills in what the file does not name, so a colour scheme is never half-written', function () {
    $theme = Theme::fromArray(['name' => 'Sparse', 'colors' => ['accent2' => '#A6E3A1']]);

    // The accent it named, and the eleven it did not: a `clrScheme` with a slot
    // missing is one PowerPoint will not show as a theme at all.
    expect($theme->colours())->toHaveCount(12)
        ->and($theme->colour('accent2'))->toBe('#A6E3A1')
        ->and($theme->colour('accent1'))->toBe(Theme::defaults()->colour('accent1'))
        ->and($theme->fonts())->toBe(Theme::defaults()->fonts());
});

it('takes the colour names the concept draft used, and speaks DrawingML afterwards', function () {
    $theme = Theme::fromArray([
        'name' => 'Aliases',
        'colors' => [
            'dark1' => '#1E1E2E',
            'light1' => '#CDD6F4',
            'dark2' => '#181825',
            'light2' => '#BAC2DE',
            'hyperlink' => '#89B4FA',
            'followedHyperlink' => '#CBA6F7',
        ],
    ]);

    expect($theme->colour('dk1'))->toBe('#1E1E2E')
        ->and($theme->colour('lt1'))->toBe('#CDD6F4')
        ->and($theme->colour('dk2'))->toBe('#181825')
        ->and($theme->colour('lt2'))->toBe('#BAC2DE')
        ->and($theme->colour('hlink'))->toBe('#89B4FA')
        ->and($theme->colour('folHlink'))->toBe('#CBA6F7')
        ->and(array_keys($theme->colours()))->toBe(Theme::COLOURS);

    // Under the spelling it was written with too, because a caller may hold either.
    expect($theme->colour('dark1'))->toBe('#1E1E2E')
        ->and($theme->colour('hyperlink'))->toBe('#89B4FA');
});

it('gives a colour back the way it was written, in one case', function () {
    expect(Theme::fromArray(['name' => 'Lower', 'colors' => ['dk1' => '#1e1e2e']])->colour('dk1'))->toBe('#1E1E2E');
});

it('has no colour and no font for a slot a theme does not set', function () {
    $theme = Theme::defaults('Nothing');

    expect($theme->description())->toBeNull()
        ->and($theme->author())->toBeNull()
        ->and($theme->colour('accent9'))->toBeNull()
        ->and($theme->font('caption'))->toBeNull();
});

it('gives the theme back as the array it was read from', function () {
    $theme = Theme::fromArray(['name' => 'Round', 'colors' => ['dk1' => '#101010']]);
    $array = $theme->toArray();

    expect($array['name'])->toBe('Round')
        ->and($array['colors']['dk1'])->toBe('#101010')
        ->and(Theme::fromArray($array)->colour('dk1'))->toBe('#101010');
});

it('refuses a theme with no name', function () {
    Theme::fromArray(['colors' => []]);
})->throws(MalformedTheme::class, 'A theme needs a "name"');

it('refuses a key that is not one of a theme, and names the ones that are', function () {
    Theme::fromArray(['name' => 'Typo', 'colour' => []]);
})->throws(MalformedTheme::class, '"colour" is not a key of a theme. Valid keys: name, description, author, colors, fonts');

it('refuses a colour name that is not one of a theme, and names the ones that are', function () {
    Theme::fromArray(['name' => 'Typo', 'colors' => ['accent7' => '#FFFFFF']]);
})->throws(MalformedTheme::class, '"accent7" is not a key of a theme. Valid keys: dk1, lt1, dk2, lt2, accent1, accent2, accent3, accent4, accent5, accent6, hlink, folHlink, dark1, light1, dark2, light2, hyperlink, followedHyperlink');

it('refuses a colour that is not one, rather than writing an invalid theme part', function () {
    Theme::fromArray(['name' => 'Typo', 'colors' => ['accent1' => 'cornflower']]);
})->throws(MalformedTheme::class, 'is not a colour: a DrawingML colour is a hash and six hex digits');

it('refuses a colour slot given something that is not a string', function () {
    Theme::fromArray(['name' => 'Typo', 'colors' => ['accent1' => 0x89B4FA]]);
})->throws(MalformedTheme::class, '"accent1" is not a colour such as "#1E1E2E"');

it('refuses colors that are not a mapping', function () {
    Theme::fromArray(['name' => 'Typo', 'colors' => '#89B4FA']);
})->throws(MalformedTheme::class, '"colors" is not a mapping of colour names to colours');

it('refuses a font slot that is not one of the three, and names them', function () {
    Theme::fromArray(['name' => 'Typo', 'fonts' => ['caption' => 'Inter']]);
})->throws(MalformedTheme::class, '"caption" is not a key of a theme. Valid keys: heading, body, mono');

it('refuses a font slot with no font in it', function () {
    Theme::fromArray(['name' => 'Typo', 'fonts' => ['heading' => '  ']]);
})->throws(MalformedTheme::class, '"heading" is not a font name');

it('refuses a font given something that is not a string', function () {
    Theme::fromArray(['name' => 'Typo', 'fonts' => ['heading' => ['Inter']]]);
})->throws(MalformedTheme::class, '"heading" is not a font name');

it('refuses fonts that are not a mapping', function () {
    Theme::fromArray(['name' => 'Typo', 'fonts' => 'Inter']);
})->throws(MalformedTheme::class, '"fonts" is not a mapping of font slots to font names');

it('refuses a description that is not a string', function () {
    Theme::fromArray(['name' => 'Typo', 'description' => ['dark']]);
})->throws(MalformedTheme::class, '"description" is not a string');

it('says which file a theme came from', function () {
    Theme::fromArray(['name' => 'Typo', 'colorz' => []], 'themes/acme/theme.yml');
})->throws(MalformedTheme::class, 'In "themes/acme/theme.yml": "colorz" is not a key of a theme');

it('lists the layout roles a deck may write', function () {
    expect(LayoutRole::names())->toBe(['title', 'content', 'two-column', 'section', 'blank'])
        ->and(LayoutRole::from('two-column'))->toBe(LayoutRole::TwoColumn)
        ->and(LayoutRole::tryFrom('Two Content'))->toBeNull();
});

it('lists the style slots a theme may name', function () {
    expect(StyleSlots::names())->toContain('heading.1', 'paragraph', 'codeFont', 'tableCell')
        ->and(StyleSlots::names())->not->toContain('blockquote');
});

it('loads every theme that ships with the library', function () {
    $loader = new MarkdownPresentation\Theme\ThemeLoader();

    foreach (['default', 'corporate-dark', 'minimal'] as $name) {
        $loaded = $loader->load(dirname(__DIR__, 2) . '/themes/' . $name);

        expect($loaded->theme()->name())->not->toBeEmpty()
            ->and($loaded->roles())->toContain('title', 'content', 'two-column');
    }
});