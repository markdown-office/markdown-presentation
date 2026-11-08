<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Render\Free\FreePicture;
use MarkdownPresentation\Render\Free\Mask;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\Shape\AutoShape;

/**
 * A picture drawn in a shape rather than in a rectangle.
 *
 * The mask is the picture's own: the file writes the shape as the geometry of the picture, so
 * the picture is masked by the picture and not by a frame drawn around it. What is checked here
 * is that the geometry reaches the file, that a picture without a mask keeps the rectangle it
 * was drawn in, and that a mask that is not one of the names the format offers is refused by
 * name rather than passed on to the file as a shape nobody can draw.
 */

/** A rendered deck of one slide, opened for reading its parts. */
function maskDeckFile(string $markdown): Pptx
{
    $path = Scratch::file('mask.pptx', '');

    (new PresentationRenderer(directory: Scratch::directory()))
        ->render((new DeckParser())->parse($markdown), $path);

    return Pptx::open($path);
}

/** The shapes of one slide, read through the parser. */
function maskShapes(string $markdown): array
{
    return (new BlockTranslator())->parse(
        $markdown,
        1,
        Scratch::directory(),
        new PathScope([Scratch::directory()]),
        Theme::defaults(),
    )->shapes();
}

/** A deck with one picture in it, and the picture's file. */
function maskDeck(string $attributes): string
{
    $picture = Scratch::file('mask.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    ));

    return sprintf(
        "---\nlayout: blank\n---\n\n::image{src=\"%s\" %s width=100 height=100}\n",
        basename($picture),
        $attributes,
    );
}

it('writes the shape the picture is drawn in', function (string $mask, string $prst) {
    $deck = maskDeckFile(maskDeck(sprintf('mask=%s', $mask)));

    expect($deck->part('ppt/slides/slide1.xml'))
        ->toContain(sprintf('<a:prstGeom prst="%s">', $prst));
})->with([
    'circle' => ['circle', 'ellipse'],
    'ellipse' => ['ellipse', 'ellipse'],
    'roundrect' => ['roundrect', 'roundRect'],
    'triangle' => ['triangle', 'triangle'],
    'diamond' => ['diamond', 'diamond'],
    'hexagon' => ['hexagon', 'hexagon'],
    'chevron' => ['chevron', 'chevron'],
    'arrow' => ['arrow', 'rightArrow'],
    'star' => ['star', 'star5'],
    'heart' => ['heart', 'heart'],
    'teardrop' => ['teardrop', 'teardrop'],
    'cloud' => ['cloud', 'cloud'],
    'moon' => ['moon', 'moon'],
    'donut' => ['donut', 'donut'],
    'frame' => ['frame', 'frame'],
    'plaque' => ['plaque', 'plaque'],
]);

it('writes a picture without a mask in a rectangle', function () {
    $deck = maskDeckFile(maskDeck(''));

    expect($deck->part('ppt/slides/slide1.xml'))->toContain('<a:prstGeom prst="rect">');
});

it('refuses a mask that is not one of the names it offers', function () {
    expect(fn () => maskDeckFile(maskDeck('mask=blob')))
        ->toThrow(
            MalformedShape::class,
            'A mask is one of: arrow, chevron, circle, cloud, diamond, donut, ellipse, frame, '
            . 'heart, hexagon, moon, plaque, roundrect, star, teardrop, triangle.',
        );
});

it('refuses a mask on a shape that is not a picture', function () {
    // The attribute belongs to the picture and to nothing else: a shape that is drawn in a
    // shape is a picture, and a shape that is not one is a shape.
    expect(fn () => maskDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{mask=circle width=100 height=100}\n",
    ))->toThrow(MalformedShape::class, 'mask');
});

it('carries the mask on the shape the deck wrote', function () {
    $shapes = maskShapes(maskDeck('mask=heart'));

    expect($shapes)->toHaveCount(1)
        ->and($shapes[0])->toBeInstanceOf(FreePicture::class)
        ->and($shapes[0]->mask)->toBe(Mask::Heart)
        ->and($shapes[0]->mask?->prst())->toBe(AutoShape::TYPE_HEART);
});

it('carries no mask on a picture that was given none', function () {
    $shapes = maskShapes(maskDeck(''));

    expect($shapes)->toHaveCount(1)
        ->and($shapes[0]->mask)->toBeNull();
});

it('leaves the box of the picture the mask it was given', function () {
    // The mask is a shape and not a size: the picture keeps the box it was placed in, and the
    // shape is drawn in it.
    $masked = maskDeckFile(maskDeck('mask=circle'));
    $plain = maskDeckFile(maskDeck(''));

    expect($masked->positionOf('image'))->toEqual($plain->positionOf('image'));
});
