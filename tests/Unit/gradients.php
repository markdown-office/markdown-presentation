<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedGradient;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * A gradient: on a shape the deck placed, and as the background of a slide.
 *
 * The stops, the angle and the path are three separate things, and each of them is checked
 * against the file rather than against the library: a gradient that is right in the objects
 * and wrong in the file is a gradient nobody sees.
 */
function gradientDeck(string $markdown): Pptx
{
    $path = Scratch::file('gradient.pptx', '');

    (new PresentationRenderer(directory: Scratch::directory()))
        ->render((new DeckParser())->parse($markdown), $path);

    return Pptx::open($path);
}

/** The stops of the first gradient in a part, as the position and the colour of each. */
function gradientStops(string $xml): array
{
    preg_match_all('#<a:gs pos="(\d+)">\s*<a:srgbClr val="([0-9A-F]{6})"#', $xml, $matches, PREG_SET_ORDER);

    return array_map(static fn (array $match): array => [$match[1], $match[2]], $matches);
}

it('writes a shape\'s gradient with every stop of it', function () {
    $pptx = gradientDeck("::rectangle{gradient=\"accent1,dk1@50,lt2\"}\n");

    expect(gradientStops($pptx->part('ppt/slides/slide1.xml')))
        ->toBe([['0', '4472C4'], ['50000', '000000'], ['100000', 'E7E6E6']]);
});

it('spreads a stop that names no position among the ones that do', function () {
    $pptx = gradientDeck("::rectangle{gradient=\"accent1,lt1,lt2\"}\n");

    expect(gradientStops($pptx->part('ppt/slides/slide1.xml')))
        ->toBe([['0', '4472C4'], ['50000', 'FFFFFF'], ['100000', 'E7E6E6']]);
});

it('writes the angle the deck named', function () {
    // CSS counts its angles from the top and the format counts its own from the right, so
    // the one is the other less ninety degrees: forty-five is three hundred and fifteen.
    $pptx = gradientDeck("::rectangle{gradient=\"accent1,lt2\" gradient-angle=45}\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<a:lin ang="18900000"');
});

it('writes a gradient that runs from the middle outwards as a path', function () {
    $pptx = gradientDeck("::rectangle{gradient=\"accent1,lt2\" gradient-radial}\n");
    $xml = $pptx->part('ppt/slides/slide1.xml');

    expect($xml)->toContain('<a:path path="circle">')
        ->and($xml)->toContain('<a:fillToRect l="50000" t="50000" r="50000" b="50000"/>')
        ->and($xml)->not->toContain('<a:lin');
});

it('writes a slide\'s background', function () {
    $pptx = gradientDeck("---\nbackground: accent1\n---\n\n# One\n");
    $xml = $pptx->part('ppt/slides/slide1.xml');

    expect($xml)->toContain('<p:bg>')
        ->and($xml)->toContain('<a:solidFill>')
        ->and($xml)->toContain('<a:srgbClr val="4472C4">');
});

it('writes a gradient background', function () {
    $pptx = gradientDeck("---\nbackground-gradient: \"accent1,lt2\"\n---\n\n# One\n");

    expect(gradientStops($pptx->part('ppt/slides/slide1.xml')))
        ->toBe([['0', '4472C4'], ['100000', 'E7E6E6']]);
});

it('refuses a gradient of one stop', function () {
    expect(fn () => gradientDeck("::rectangle{gradient=\"accent1\"}\n"))
        ->toThrow(MalformedGradient::class, 'A gradient is two or more stops');
});

it('refuses a stop that names no colour', function () {
    expect(fn () => gradientDeck("::rectangle{gradient=\"accent1,chartreuse\"}\n"))
        ->toThrow(MalformedGradient::class, 'A colour is either no colour at all');
});

it('refuses none as a stop', function () {
    expect(fn () => gradientDeck("::rectangle{gradient=\"accent1,none\"}\n"))
        ->toThrow(MalformedGradient::class, 'A stop is a colour, and none is not one');
});

it('refuses a position that is not a number', function () {
    expect(fn () => gradientDeck("::rectangle{gradient=\"accent1@half,lt2\"}\n"))
        ->toThrow(MalformedGradient::class, 'A stop\'s position is a whole number of percent');
});

it('refuses a position of more than a hundred percent', function () {
    expect(fn () => gradientDeck("::rectangle{gradient=\"accent1@120,lt2\"}\n"))
        ->toThrow(MalformedGradient::class, 'A stop\'s position is a whole number of percent');
});

it('refuses a fill and a gradient together', function () {
    expect(fn () => gradientDeck("::rectangle{fill=accent1 gradient=\"accent1,lt2\"}\n"))
        ->toThrow(MalformedGradient::class, 'A shape is filled with a colour or with a gradient');
});

it('refuses an angle with a gradient that runs from the middle outwards', function () {
    expect(fn () => gradientDeck("::rectangle{gradient=\"accent1,lt2\" gradient-radial gradient-angle=45}\n"))
        ->toThrow(MalformedGradient::class, 'A radial gradient runs from the middle outwards and has no angle');
});

it('refuses an angle that is not a whole number of degrees', function () {
    expect(fn () => gradientDeck("::rectangle{gradient=\"accent1,lt2\" gradient-angle=45.5}\n"))
        ->toThrow(MalformedGradient::class, 'An angle is a whole number of degrees');
});

it('writes the angle of a gradient background', function () {
    $pptx = gradientDeck("---\nbackground-gradient: \"accent1,lt2\"\nbackground-angle: 45\n---\n\n# One\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<a:lin ang="18900000"');
});

it('refuses an empty stop', function () {
    expect(fn () => gradientDeck("::rectangle{gradient=\"accent1,\"}\n"))
        ->toThrow(MalformedGradient::class, 'A gradient is two or more stops');
});

it('paints every stop of a gradient with the alpha of the shape', function () {
    $pptx = gradientDeck("::rectangle{gradient=\"accent1,lt2\" alpha=50}\n");
    $xml = $pptx->part('ppt/slides/slide1.xml');

    expect(substr_count($xml, '<a:alpha val="50000"/>'))->toBe(2);
});

it('refuses a background that is not a colour', function () {
    expect(fn () => gradientDeck("---\nbackground: 42\n---\n\n# One\n"))
        ->toThrow(MarkdownPresentation\Exception\MalformedFrontmatter::class, 'that is not a colour such as');
});

it('refuses a background of none', function () {
    expect(fn () => gradientDeck("---\nbackground: none\n---\n\n# One\n"))
        ->toThrow(MarkdownPresentation\Exception\MalformedFrontmatter::class, 'that is not a colour such as');
});

it('refuses an angle of the background that is not a number', function () {
    expect(fn () => gradientDeck("---\nbackground-gradient: \"accent1,lt2\"\nbackground-angle: forty\n---\n\n# One\n"))
        ->toThrow(MarkdownPresentation\Exception\MalformedFrontmatter::class, 'that is not a whole number of degrees');
});

it('refuses a gradient background that is not a list of stops', function () {
    expect(fn () => gradientDeck("---\nbackground-gradient: 42\n---\n\n# One\n"))
        ->toThrow(MarkdownPresentation\Exception\MalformedFrontmatter::class, 'that is not a list of stops');
});

it('refuses a background and a gradient background together', function () {
    expect(fn () => gradientDeck("---\nbackground: accent1\nbackground-gradient: \"accent1,lt2\"\n---\n\n# One\n"))
        ->toThrow(MalformedGradient::class, 'a background and a gradient background');
});

it('refuses a background that names no colour', function () {
    expect(fn () => gradientDeck("---\nbackground: chartreuse\n---\n\n# One\n"))
        ->toThrow(MarkdownPresentation\Exception\MalformedFrontmatter::class, 'that is not a colour such as "#3F6FB5"');
});

it('refuses an angle with no background to angle', function () {
    expect(fn () => gradientDeck("---\nbackground-angle: 45\n---\n\n# One\n"))
        ->toThrow(MalformedGradient::class, 'an angle or a radial gradient on its own');
});

it('refuses a radial flag that is not true or false', function () {
    expect(fn () => gradientDeck("---\nbackground-gradient: \"accent1,lt2\"\nbackground-radial: yes please\n---\n\n# One\n"))
        ->toThrow(MarkdownPresentation\Exception\MalformedFrontmatter::class, 'that is not true or false');
});

it('refuses a radial gradient with an angle', function () {
    expect(fn () => gradientDeck("---\nbackground-gradient: \"accent1,lt2\"\nbackground-radial: true\nbackground-angle: 45\n---\n\n# One\n"))
        ->toThrow(MalformedGradient::class, 'A radial gradient runs from the middle outwards and has no angle');
});
