<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Tests\Support\Contrast;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\Style;
use MarkdownPresentation\Theme\StyleSlots;
use MarkdownPresentation\Theme\Theme;
use MarkdownPresentation\Theme\ThemeMapping;

/**
 * The palette the theme tests below spell out, which is `corporate-dark`'s.
 *
 * Named by the slot they fill rather than by their hue, because the tests are about what a
 * slot is given and what it comes back as: the spelling "accent1" is the thing being tested,
 * and a constant called `ACCENT1` is legible where `#89B4FA` is not. The same colours are
 * the ones `themes/corporate-dark/theme.yml` carries, which is why a test may assert that a
 * theme reads back exactly what the file on disk says.
 */
const DK1 = '#1E1E2E';
const LT1 = '#CDD6F4';
const DK2 = '#181825';
const LT2 = '#BAC2DE';
const ACCENT1 = '#89B4FA';

/** The one colour in this file that is not a slot name, because the test is about rounding. */
const ROUND = '#101010';

/** Where the themes this package ships are read from. */
const THEMES = '/themes/';

it('reads a theme, and keeps the twelve colours in DrawingML order', function () {
    $theme = Theme::fromArray([
        'name' => 'Corporate Dark',
        'description' => 'Dark corporate deck.',
        'author' => 'markdown-office',
        'colors' => ['accent1' => ACCENT1, 'dk1' => DK1],
        'fonts' => ['heading' => 'Inter'],
    ]);

    expect($theme->name())->toBe('Corporate Dark')
        ->and($theme->description())->toBe('Dark corporate deck.')
        ->and($theme->author())->toBe('markdown-office')
        ->and(array_keys($theme->colours()))->toBe(Theme::COLOURS)
        ->and($theme->colour('dk1'))->toBe(DK1)
        ->and($theme->colour('accent1'))->toBe(ACCENT1)
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

it('names the spellings a colour may be written under, for a caller that holds a table of them', function () {
    // The spellings are a fact about the vocabulary rather than about the file, and a
    // caller holding the documentation's own reference table has no business reading a
    // private constant to find out what they are.
    expect(ThemeMapping::aliases())->toBe([
        'dark1' => Theme::DK1,
        'light1' => Theme::LT1,
        'dark2' => Theme::DK2,
        'light2' => Theme::LT2,
        'hyperlink' => Theme::HLINK,
        'followedHyperlink' => Theme::FOL_HLINK,
    ]);
});

it('takes the colour names the concept draft used, and speaks DrawingML afterwards', function () {
    $theme = Theme::fromArray([
        'name' => 'Aliases',
        'colors' => [
            'dark1' => DK1,
            'light1' => LT1,
            'dark2' => DK2,
            'light2' => LT2,
            'hyperlink' => ACCENT1,
            'followedHyperlink' => '#CBA6F7',
        ],
    ]);

    expect($theme->colour('dk1'))->toBe(DK1)
        ->and($theme->colour('lt1'))->toBe(LT1)
        ->and($theme->colour('dk2'))->toBe(DK2)
        ->and($theme->colour('lt2'))->toBe(LT2)
        ->and($theme->colour('hlink'))->toBe(ACCENT1)
        ->and($theme->colour('folHlink'))->toBe('#CBA6F7')
        ->and(array_keys($theme->colours()))->toBe(Theme::COLOURS);

    // Under the spelling it was written with too, because a caller may hold either.
    expect($theme->colour('dark1'))->toBe(DK1)
        ->and($theme->colour('hyperlink'))->toBe(ACCENT1);
});

it('gives a colour back the way it was written, in one case', function () {
    expect(Theme::fromArray(['name' => 'Lower', 'colors' => ['dk1' => '#1e1e2e']])->colour('dk1'))->toBe(DK1);
});

it('has no colour and no font for a slot a theme does not set', function () {
    $theme = Theme::defaults('Nothing');

    expect($theme->description())->toBeNull()
        ->and($theme->author())->toBeNull()
        ->and($theme->colour('accent9'))->toBeNull()
        ->and($theme->font('caption'))->toBeNull();
});

it('gives the theme back as the array it was read from', function () {
    $theme = Theme::fromArray(['name' => 'Round', 'colors' => ['dk1' => ROUND]]);
    $array = $theme->toArray();

    expect($array['name'])->toBe('Round')
        ->and($array['colors']['dk1'])->toBe(ROUND)
        ->and($array['dark'])->toBeFalse()
        ->and(Theme::fromArray($array)->colour('dk1'))->toBe(ROUND);

    // A round trip that dropped `dark` would turn a dark theme into a light one with
    // nothing saying so, which is the failure this key exists to make impossible.
    $night = Theme::fromArray(['name' => 'Night', 'dark' => true, 'colors' => $array['colors']]);

    expect($night->toArray()['dark'])->toBeTrue()
        ->and(Theme::fromArray($night->toArray())->isDark())->toBeTrue();
});

it('refuses a theme with no name', function () {
    Theme::fromArray(['colors' => []]);
})->throws(MalformedTheme::class, 'A theme needs a "name"');

it('refuses a key that is not one of a theme, and names the ones that are', function () {
    Theme::fromArray(['name' => 'Typo', 'colour' => []]);
})->throws(MalformedTheme::class, '"colour" is not a key of a theme. Valid keys: name, description, author, dark, colors, fonts');

it('reads a theme that says its page is dark, without changing a colour of it', function () {
    // `dark` says which of the two plays which part. It renames nothing: a theme that
    // declares itself dark while painting its page with the light token is a theme whose
    // palette and whose flag disagree, and only the flag can be acted on.
    $theme = Theme::fromArray([
        'name' => 'Night',
        'dark' => true,
        'colors' => ['dk1' => DK1, 'lt1' => LT1, 'dk2' => DK2, 'lt2' => LT2],
    ]);

    expect($theme->isDark())->toBeTrue()
        ->and($theme->colour('dk1'))->toBe(DK1)
        ->and($theme->colour('lt1'))->toBe(LT1);
});

it('paints the page and the type on it with opposite slots, whichever way round they are', function () {
    // These four are the master's colour map. A shape reaching a slot named outright
    // rather than through the theme would paint its own map.
    $night = Theme::fromArray(['name' => 'Night', 'dark' => true]);
    $day = Theme::fromArray(['name' => 'Day']);

    expect($night->page())->toBe(Theme::DK1)
        ->and($night->ink())->toBe(Theme::LT1)
        ->and($night->panel())->toBe(Theme::DK2)
        ->and($night->subtleInk())->toBe(Theme::LT2)
        ->and($day->page())->toBe(Theme::LT1)
        ->and($day->ink())->toBe(Theme::DK1)
        ->and($day->panel())->toBe(Theme::LT2)
        ->and($day->subtleInk())->toBe(Theme::DK2)
        ->and(Theme::defaults()->isDark())->toBeFalse();
});

it('refuses a dark flag that is not a boolean, rather than guessing at it', function () {
    // `dark: yes` is a YAML 1.1 truthy *string* rather than the flag, so a theme written
    // that way would silently render light unless the type is checked.
    Theme::fromArray(['name' => 'Typo', 'dark' => 'yes']);
})->throws(MalformedTheme::class, '"dark" is not true or false');

it('refuses a dark flag written as something else at all', function () {
    Theme::fromArray(['name' => 'Typo', 'dark' => ['yes']]);
})->throws(MalformedTheme::class, '"dark" is not true or false');

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
    Theme::fromArray(['name' => 'Typo', 'colors' => ACCENT1]);
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

it('reads a file through the mapping and holds no bounds of its own', function () {
    // The seam `Style` draws from `StyleMapping`, now drawn again: the spellings a
    // `theme.yml` may use and the bounds one puts on a file belong to the reader, and
    // `Theme` answers only about a theme it holds. A `Theme` built in code is held to the
    // types alone, exactly as a `Style` built in code is — which is what lets the two be
    // the same shape of thing.
    expect(ThemeMapping::slot('dark1'))->toBe(Theme::DK1)
        ->and(ThemeMapping::slot('accent1'))->toBe('accent1')
        ->and(ThemeMapping::KEYS)->toContain('name', 'colors', 'fonts', 'style')
        ->and(ThemeMapping::read(['name' => 'Straight'])->name())->toBe('Straight');

    // A `Theme` constructed directly takes whatever it is given, and is not the file's
    // business to police: this is the half of the seam that has no reader on the other side.
    $built = new Theme('Built', null, null, false, [], [], Style::defaults());

    expect($built->name())->toBe('Built')
        ->and($built->colours())->toBe([])
        ->and($built->colour('accent1'))->toBeNull();
});

it('lists the layout roles a deck may write', function () {
    expect(LayoutRole::names())->toBe([
        'title', 'content', 'two-column', 'section', 'blank', 'image-text', 'image-title', 'image-slide',
    ])
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
        $loaded = $loader->load(dirname(__DIR__, 2) . THEMES . $name);

        expect($loaded->theme()->name())->not->toBeEmpty()
            ->and($loaded->roles())->toContain('title', 'content', 'two-column');
    }
});

it('loads the dark flag off disk, so the shipped dark theme really is one', function () {
    $loader = new MarkdownPresentation\Theme\ThemeLoader();

    // The file is read, not assumed: `corporate-dark` has said it is dark since the
    // flag was added, and a theme with a near-black `dk1` and no flag renders light.
    expect($loader->load(dirname(__DIR__, 2) . THEMES . 'corporate-dark')->theme()->isDark())->toBeTrue();

    foreach (['default', 'minimal'] as $name) {
        expect($loader->load(dirname(__DIR__, 2) . THEMES . $name)->theme()->isDark())->toBeFalse();
    }
});

it('paints every dark theme that ships in type a reader can read', function () {
    $loader = new MarkdownPresentation\Theme\ThemeLoader();
    $ratios = [];

    foreach (['default', 'corporate-dark', 'minimal'] as $name) {
        $theme = $loader->load(dirname(__DIR__, 2) . THEMES . $name)->theme();

        if ($theme->isDark() === false) {
            continue;
        }

        $page = (string) $theme->colour($theme->page());

        foreach (['ink', 'subtleInk'] as $role) {
            $ratios[$name . '.' . $role] = Contrast::ratio((string) $theme->colour($theme->{$role}()), $page);
        }
    }

    // 4.5 is AA for body text and every pairing here is far above it. A palette edited
    // to something dimmer fails here rather than in a room with the lights off.
    expect($ratios)->not->toBe([])
        ->and(min($ratios))->toBeGreaterThanOrEqual(4.5);
});
