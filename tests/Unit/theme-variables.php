<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * A slide's own values: the theme's values laid over the deck's theme.
 *
 * The values are a `theme.yml`, so the vocabulary is the theme's own and the reader is the
 * theme's own. What is checked here is the merge: the slide's over the template's, the
 * template's over the deck's, and one value over another leaving the rest alone.
 */
function variablesDeck(string $markdown): Pptx
{
    $path = Scratch::file('variables.pptx', '');

    (new PresentationRenderer(directory: Scratch::directory()))
        ->render((new DeckParser())->parse($markdown), $path);

    return Pptx::open($path);
}

it('writes a slide in the colour it names', function () {
    $pptx = variablesDeck("---\ntheme-variables:\n  colors:\n    dk1: \"#123456\"\n---\n\n# One\n");

    expect(inkOf($pptx))->toContain('123456');
});

it('leaves the other slides of the deck alone', function () {
    $pptx = variablesDeck(
        "---\ntheme-variables:\n  colors:\n    dk1: \"#123456\"\n---\n\n# One\n\n---\n\n# Two\n",
    );

    expect(inkOf($pptx, 1))->toContain('123456')
        ->and(inkOf($pptx, 2))->not->toContain('123456');
});

it('takes the ink from the slot the theme paints it on', function () {
    // The default theme is a light one, so the ink is the dark slot. A slide that says the
    // theme is dark takes the light one instead, which is what `dark:` has always meant.
    $pptx = variablesDeck("---\ntheme-variables:\n  dark: true\n---\n\n# One\n");

    expect(inkOf($pptx))->toContain('FFFFFF');
});

it('paints the page of a slide whose own theme paints another', function () {
    // The master keeps the theme's page for every slide, so a slide that says the theme is
    // dark is painted on the dark page of its own.
    $pptx = variablesDeck("---\ntheme-variables:\n  dark: true\n---\n\n# One\n");

    expect(pageOf($pptx))->toBe('000000')
        ->and(inkOf($pptx))->toContain('FFFFFF');
});

it('leaves the page of a slide alone where its own theme paints the same one', function () {
    $pptx = variablesDeck("---\ntheme-variables:\n  colors:\n    dk1: \"#123456\"\n---\n\n# One\n");

    expect(pageOf($pptx))->toBeNull();
});

it('takes the values of the template it names', function () {
    // The values of a template are named as the template's, under `template-settings:`, so
    // that the ordinary keys mean the slide's own everywhere.
    $pptx = variablesDeck(
        "---\ntemplate: brand\ntemplate-settings:\n  theme-variables:\n    colors:\n      dk1: \"#123456\"\n"
        . "---\n::line{}\n\n"
        . "---\nlayout: content\ntemplate: brand\n---\n\nWords.\n",
    );

    expect(inkOf($pptx, 1))->toContain('123456');
});

it('lays a slide\'s values over the template\'s', function () {
    $pptx = variablesDeck(
        "---\ntemplate: brand\ntemplate-settings:\n  theme-variables:\n    colors:\n      dk1: \"#123456\"\n"
        . "---\n::line{}\n\n"
        . "---\nlayout: content\ntemplate: brand\n---\n\nWords.\n\n"
        . "---\nlayout: content\ntemplate: brand\ntheme-variables:\n  colors:\n    dk1: \"#654321\"\n---\n\n"
        . "Words.\n",
    );

    expect(inkOf($pptx, 1))->toContain('123456')
        ->and(inkOf($pptx, 2))->toContain('654321');
});

it('leaves the values a slide does not name alone', function () {
    // The page is the light slot, and the slide named the dark one, so the page is still
    // the theme's own.
    $pptx = variablesDeck(
        "---\nbackground: page\ntheme-variables:\n  colors:\n    dk1: \"#123456\"\n---\n\n# One\n",
    );

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('FFFFFF');
});

it('writes a slide in the type scale it names', function () {
    $pptx = variablesDeck("---\ntheme-variables:\n  style:\n    body: 30\n---\n\nWords.\n");
    $xml = $pptx->part('ppt/slides/slide1.xml');

    expect($xml)->toContain('sz="3000"');
});

it('refuses a key that is not a value a theme holds', function () {
    expect(fn () => variablesDeck("---\ntheme-variables:\n  name: mine\n---\n\n# One\n"))
        ->toThrow(MalformedTheme::class, '"name" is not a value a theme holds');
});

it('refuses the sections of a theme\'s own file in a slide\'s values', function () {
    // The values are the values: the layout roles and the named styles are the theme's own
    // shape, and a slide has none of them to lay over it.
    expect(fn () => variablesDeck("---\ntheme-variables:\n  layouts:\n    content: Body Text\n---\n\n# One\n"))
        ->toThrow(MalformedTheme::class, '"layouts" is not a value a theme holds')
        ->and(fn () => variablesDeck("---\ntheme-variables:\n  textStyles:\n    brand:\n      color: accent1\n---\n\n# One\n"))
        ->toThrow(MalformedTheme::class, '"textStyles" is not a value a theme holds');
});

it('refuses a colour that is not a colour', function () {
    expect(fn () => variablesDeck("---\ntheme-variables:\n  colors:\n    accent1: red\n---\n\n# One\n"))
        ->toThrow(MalformedTheme::class, 'which is not a colour');
});

it('refuses values that are not a mapping', function () {
    expect(fn () => variablesDeck("---\ntheme-variables: red\n---\n\n# One\n"))
        ->toThrow(MalformedFrontmatter::class, 'that is not a mapping of the theme\'s values');
});
