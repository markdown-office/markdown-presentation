<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Render\Free\FreeText;
use MarkdownPresentation\Render\Free\Shadow;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Theme\Theme;

/**
 * The shadow a free shape is drawn with.
 *
 * Every assertion is about what reaches the file, and the one that matters most is that a shape
 * with no shadow attributes is written with no `<a:effectLst>` at all — an effect list that
 * says a shape has no shadow is still an effect list, and the difference is a byte in every
 * shape of every deck that never asked for one.
 */

/** A one-pixel PNG, written where a deck may read a picture from. */
function shadowPicture(): string
{
    return Scratch::file('logo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    ));
}

/** Renders one slide of Markdown and answers with the file it wrote. */
function shadowed(string $markdown): Pptx
{
    $path = Scratch::file('shadow.pptx', '');

    // `Scratch::file()` appends a unique suffix, so the deck has to name the basename rather
    // than the name it was asked for. See `tests/Unit/free-shapes.php`.
    $markdown = str_replace('__PICTURE__', basename(shadowPicture()), $markdown);

    // `directory:` is the scratch directory, because `logo.png` was written there and a deck's
    // relative picture path resolves against it.
    (new PresentationRenderer(directory: Scratch::directory()))
        ->render(
            (new DeckParser())->parse("---\nlayout: blank\n---\n\n" . $markdown),
            $path,
        );

    return Pptx::open($path);
}

/** The shape a directive produced, read through the parser on its own. */
function shadowOf(string $directive): ?Shadow
{
    $theme = (new MarkdownPresentation\Theme\ThemeResolver())->resolve('default')->theme();

    $blocks = (new BlockTranslator())->parse(
        $directive . "\n",
        1,
        '.',
        new MarkdownPresentation\Filesystem\PathScope(['.']),
        $theme,
    );

    foreach ($blocks->shapes() as $shape) {
        if ($shape instanceof FreeText) {
            return $shape->style === null ? null : ($shape->shadow ?? null);
        }
    }

    return $blocks->shapes()[0]->shadow ?? null;
}

it('writes no effect list on a shape that named no shadow', function () {
    expect(shadowed("::rectangle{fill=lt2}\n")->part('ppt/slides/slide1.xml'))
        ->not->toContain('effectLst')
        ->and(shadowed("::rectangle{fill=lt2}\n")->part('ppt/slides/slide1.xml'))
        ->not->toContain('outerShdw');
});

it('writes a shadow on an ink shape when one attribute is enough', function () {
    $xml = shadowed("::rectangle{fill=lt2 shadow-distance=8}\n")->part('ppt/slides/slide1.xml');

    expect($xml)->toContain('<a:effectLst>')->toContain('<a:outerShdw ')
        // 8px is 76200 EMU, and the default direction is 90 degrees which is 5400000.
        ->toContain('dist="76200"')
        ->toContain('dir="5400000"');
});

it('writes the distance, the blur and the direction it was given', function () {
    $xml = shadowed("::hexagon{fill=lt2 shadow-distance=10 shadow-blur=4 shadow-direction=45}\n")
        ->part('ppt/slides/slide1.xml');

    expect($xml)
        ->toContain('dist="95250"')   // 10px
        ->toContain('blurRad="38100"') // 4px
        ->toContain('dir="2700000"');  // 45 degrees
});

it('writes a shadow on a text block, a picture and every kind of shape', function (string $directive) {
    expect(shadowed($directive . "\n")->part('ppt/slides/slide1.xml'))->toContain('<a:outerShdw ');
})->with([
    'rectangle' => '::rectangle{fill=lt2 shadow-blur=4}',
    'roundrect' => '::roundrect{fill=lt2 border-radius=8 shadow-blur=4}',
    'ellipse' => '::ellipse{fill=lt2 shadow-blur=4}',
    'circle' => '::circle{width=80 shadow-blur=4}',
    'triangle' => '::triangle{fill=lt2 shadow-blur=4}',
    'diamond' => '::diamond{fill=lt2 shadow-blur=4}',
    'chevron' => '::chevron{fill=lt2 shadow-blur=4}',
    'arrow' => '::arrow{fill=lt2 shadow-blur=4}',
    'line' => '::line{fill=lt2 shadow-blur=4}',
    'image' => '::image{src="__PICTURE__" shadow-blur=4}',
    'content' => ":::content{width=300 shadow-blur=4}\nWords.\n:::",
]);

it('takes a shadow colour from a theme slot rather than from hex', function () {
    $xml = shadowed("::rectangle{fill=lt2 shadow-color=accent1 shadow-blur=4}\n")
        ->part('ppt/slides/slide1.xml');

    // The `default` theme's accent1 is #4472C4.
    expect($xml)->toContain('<a:outerShdw ')->toContain('val="4472C4"');
});

it('reads a fractional or unparseable distance as a failure naming the pixels', function (string $written) {
    expect(fn () => shadowed("::rectangle{fill=lt2 shadow-distance=$written}\n"))
        ->toThrow(MalformedShape::class, 'A shadow distance is a whole number of pixels, and cannot be negative: shadow-distance=0, shadow-distance=12.');
})->with(['1.5', 'four', '4px']);

it('refuses a negative distance rather than taking it as its magnitude', function () {
    expect(fn () => shadowed("::rectangle{fill=lt2 shadow-distance=-4}\n"))
        ->toThrow(MalformedShape::class, 'A shadow distance cannot be negative. Leave it off for none: shadow-distance=0.');
});

it('refuses a blur that is not whole pixels', function () {
    expect(fn () => shadowed("::rectangle{fill=lt2 shadow-blur=1.5}\n"))
        ->toThrow(MalformedShape::class, 'A shadow blur is a whole number of pixels, and cannot be negative: shadow-blur=0, shadow-blur=12.');
});

it('refuses a direction that is not whole degrees, naming the spellings that work', function () {
    expect(fn () => shadowed("::rectangle{fill=lt2 shadow-direction=45.5}\n"))
        ->toThrow(MalformedShape::class, 'A shadow direction is a whole number of degrees, measured clockwise from the right');
});

it('wraps a direction into a turn, the way rotate does', function () {
    $right = shadowed("::rectangle{fill=lt2 shadow-direction=0 shadow-blur=4}\n")->part('ppt/slides/slide1.xml');
    $full = shadowed("::rectangle{fill=lt2 shadow-direction=360 shadow-blur=4}\n")->part('ppt/slides/slide1.xml');
    $negative = shadowed("::rectangle{fill=lt2 shadow-direction=-90 shadow-blur=4}\n")->part('ppt/slides/slide1.xml');
    $threeSixty = shadowed("::rectangle{fill=lt2 shadow-direction=270 shadow-blur=4}\n")->part('ppt/slides/slide1.xml');

    // 0 and 360 are the same direction, and -90 is 270.
    expect($right)->toContain('dir="0"')
        ->and($full)->toContain('dir="0"')
        ->and($negative)->toContain('dir="16200000"')
        ->and($threeSixty)->toContain('dir="16200000"');
});

it('refuses a shadow alpha that is not a percent', function (string $written) {
    expect(fn () => shadowed("::rectangle{fill=lt2 shadow-alpha=$written}\n"))
        ->toThrow(MalformedShape::class, 'A shadow alpha is a whole number of percent from 0 to 100: shadow-alpha=60.');
})->with(['101', '-10', 'half']);

it('reads the shadow alpha onto the colour it writes', function (string $written, string $expected) {
    // The shadow's own colour element, rather than the first `<a:alpha>` in the file: a fill
    // carries one too, and PHPPresentation writes it unconditionally whatever the value.
    $xml = shadowed("::rectangle{fill=lt2 shadow-color=dk1 shadow-alpha=$written}\n")
        ->part('ppt/slides/slide1.xml');

    preg_match('#<a:outerShdw.*?</a:outerShdw>#s', $xml, $shadow);

    expect($shadow[0] ?? '')->toContain('<a:alpha val="' . $expected . '"');
})->with([['100', '100000'], ['40', '40000'], ['0', '0']]);

it('refuses a shadow attribute on a directive that has none', function () {
    // Every one of the twelve takes them, so this is a misspelling rather than a refusal by
    // kind — and a misspelling is refused by name, like any other attribute nobody reads.
    expect(fn () => shadowed("::rectangle{fill=lt2 shadow=8}\n"))
        ->toThrow(MalformedShape::class, 'which it does not read');
});

it('gives every directive the five shadow attributes', function (string $kind) {
    $attributes = MarkdownPresentation\Render\Free\ShapeKind::from($kind)->attributes();

    foreach (['shadow-color', 'shadow-distance', 'shadow-blur', 'shadow-direction', 'shadow-alpha'] as $attribute) {
        expect($attributes)->toContain($attribute);
    }
})->with(array_map(static fn (string $kind): string => $kind, array_column(MarkdownPresentation\Render\Free\ShapeKind::cases(), 'value')));

it('reads a shadow onto the shape rather than only onto the file', function () {
    $shadow = shadowOf('::rectangle{fill=lt2 shadow-distance=9 shadow-blur=3 shadow-direction=120}');

    expect($shadow)->toBeInstanceOf(Shadow::class)
        ->and($shadow->distance)->toBe(9)
        ->and($shadow->blur)->toBe(3)
        ->and($shadow->direction)->toBe(120);
});

it('reads no shadow where the deck named none', function () {
    expect(shadowOf('::rectangle{fill=lt2}'))->toBeNull();
});
