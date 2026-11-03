<?php

declare(strict_types=1);

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Document\Slide;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * The deck's own settings: the ones every slide of the deck is drawn with.
 *
 * The deck's frontmatter is its first slide's, so a deck that named an accent in its
 * frontmatter painted the first slide in that accent and left every other slide in the
 * theme's own. There was no layer for the deck: `theme-variables:` is read per slide, over
 * a theme resolved once from the deck's `theme:`.
 *
 * The layer is `deck-settings:` now, and it holds the same settings a slide's own are
 * written in. The precedence is slide → template → deck → theme, and each layer above the
 * theme is somebody's: a slide's own keys are the slide's, a template's settings are the
 * template's and the deck's are the deck's.
 */

/** A rendered deck, opened for reading its parts. */
function deckSettingsDeck(string $markdown): Pptx
{
    $path = Scratch::file('deck-settings.pptx', '');

    (new PresentationRenderer(directory: Scratch::directory()))
        ->render((new DeckParser())->parse($markdown), $path);

    return Pptx::open($path);
}

/**
 * A deck built by hand rather than parsed, and the file it was written to.
 *
 * @param list<Slide> $slides
 */
function deckSettingsHandBuilt(array $slides): string
{
    $path = Scratch::file('deck-settings.pptx', '');

    (new PresentationRenderer(directory: Scratch::directory()))
        ->render(new Deck('', $slides), $path);

    return $path;
}

it('draws every slide of the deck in the values the deck names', function () {
    // The gap this closes: the deck's own frontmatter reached the first slide and no
    // other, so a deck that named an accent in it had one slide in that accent and the
    // rest in the theme's.
    $pptx = deckSettingsDeck(
        "---\ndeck-settings:\n  theme-variables:\n    colors:\n      dk1: \"#123456\"\n---\n\n# One\n\n---\n\n# Two\n",
    );

    expect(inkOf($pptx, 1))->toContain('123456')
        ->and(inkOf($pptx, 2))->toContain('123456');
});

it('lays a slide\'s own values over the deck\'s', function () {
    // The precedence is slide → template → deck → theme, so a slide that names a value of
    // its own is drawn in that value and not in the deck's.
    $pptx = deckSettingsDeck(
        "---\ndeck-settings:\n  theme-variables:\n    colors:\n      dk1: \"#123456\"\n---\n\n# One\n\n"
        . "---\ntheme-variables:\n  colors:\n    dk1: \"#654321\"\n---\n\n# Two\n",
    );

    expect(inkOf($pptx, 1))->toContain('123456')
        ->and(inkOf($pptx, 2))->toContain('654321')
        ->and(inkOf($pptx, 2))->not->toContain('123456');
});

it('lays a template\'s values over the deck\'s', function () {
    // A template's settings are the template's, and the template is above the deck: the
    // slides that draw it are drawn in the template's values and not in the deck's.
    $pptx = deckSettingsDeck(
        "---\ndeck-settings:\n  theme-variables:\n    colors:\n      dk1: \"#123456\"\n---\n\n# One\n\n"
        . "---\ntemplate: brand\ntemplate-settings:\n  theme-variables:\n    colors:\n      dk1: \"#654321\"\n"
        . "---\n::line{}\n\n"
        . "---\nlayout: content\ntemplate: brand\n---\n\nWords.\n",
    );

    expect(inkOf($pptx, 1))->toContain('123456')
        ->and(inkOf($pptx, 2))->toContain('654321');
});

it('paints every slide of the deck with the background the deck names', function () {
    $pptx = deckSettingsDeck(
        "---\ndeck-settings:\n  background: \"#123456\"\n---\n\n# One\n\n---\n\n# Two\n",
    );

    expect(pageOf($pptx, 1))->toBe('123456')
        ->and(pageOf($pptx, 2))->toBe('123456');
});

it('lays a slide\'s own background over the deck\'s', function () {
    $pptx = deckSettingsDeck(
        "---\ndeck-settings:\n  background: \"#123456\"\n---\n\n# One\n\n"
        . "---\nbackground: \"#654321\"\n---\n\n# Two\n",
    );

    expect(pageOf($pptx, 1))->toBe('123456')
        ->and(pageOf($pptx, 2))->toBe('654321');
});

it('refuses the settings of the deck on a later slide, and says where they belong', function () {
    // The deck's settings are the deck's, and the deck's frontmatter is the first slide's.
    // A later slide naming them is a mistake, and it is refused rather than left to be read
    // as nothing at all.
    expect(fn () => deckSettingsDeck("# One\n\n---\ndeck-settings:\n  background: accent1\n---\n\n# Two\n"))
        ->toThrow(
            UnknownDirective::class,
            'The frontmatter of slide 2 names "deck-settings", which is a deck directive and belongs in '
            . 'the frontmatter of slide 1.',
        );
});

it('refuses a setting of the deck that is not a setting', function () {
    expect(fn () => deckSettingsDeck("---\ndeck-settings:\n  backgroun: accent1\n---\n\n# One\n"))
        ->toThrow(
            MalformedFrontmatter::class,
            'The frontmatter of slide 1 has a "deck-settings" that names "backgroun", which is not a setting.',
        )
        ->toThrow(
            MalformedFrontmatter::class,
            'The settings are: background, background-gradient, background-angle, background-radial, theme-variables.',
        );
});

it('refuses the settings of the deck that are not a mapping', function () {
    expect(fn () => deckSettingsDeck("---\ndeck-settings: accent1\n---\n\n# One\n"))
        ->toThrow(
            MalformedFrontmatter::class,
            'The frontmatter of slide 1 has a "deck-settings" that is not a mapping of the settings the deck carries.',
        );
});

it('refuses the settings of the deck that are not there', function () {
    // A key that is there with nothing under it is a mistake rather than the absence of
    // one: the deck's settings are the ones every slide is drawn with, and a block that
    // says nothing under the key says nothing.
    expect(fn () => deckSettingsDeck("---\ndeck-settings:\n---\n\n# One\n"))
        ->toThrow(MalformedFrontmatter::class, 'has a "deck-settings" that is not a mapping');
});

it('refuses the settings of the deck when the mapping holds nothing', function () {
    expect(fn () => deckSettingsDeck("---\ndeck-settings: {}\n---\n\n# One\n"))
        ->toThrow(MalformedFrontmatter::class, 'has a "deck-settings" that is not a mapping');
});

it('draws a deck built by hand with the settings of the deck', function () {
    // A deck a caller built without the parser carries the same frontmatter, and the
    // deck's settings are read out of it as they are out of a parsed deck's.
    $path = deckSettingsHandBuilt([
        new Slide(0, '# One', '# One', ['deck-settings' => ['background' => '#123456']], 'One', 1, null, 0, 1),
    ]);

    expect(pageOf(Pptx::open($path)))->toBe('123456');
});

it('refuses the settings of the deck on a later slide of a deck built by hand', function () {
    // The line behind the parser's own refusal, for a deck a caller built without it.
    $slides = [
        new Slide(0, '# One', '# One', [], 'One', 1, null, 0, 1),
        new Slide(1, '# Two', '# Two', ['deck-settings' => ['background' => '#123456']], 'Two', 1, null, 1, 2),
    ];

    expect(fn () => deckSettingsHandBuilt($slides))
        ->toThrow(
            UnknownDirective::class,
            'The frontmatter of slide 2 names "deck-settings", which is a deck directive and belongs in '
            . 'the frontmatter of slide 1.',
        );
});
