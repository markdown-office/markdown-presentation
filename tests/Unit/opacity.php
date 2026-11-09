<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * A shape that is not quite solid.
 *
 * An alpha is one percentage for the whole shape: it is written into the colour of the fill and
 * of the stroke, so a shape is seen slightly through the page rather than a fill and an outline
 * that disagree about how solid they are. What is checked here is that the alpha reaches the
 * colours of every kind of shape that takes one — the ink shapes, the placed text block and the
 * stops of a gradient — and that the shape it was given is drawn over the picture below it, so
 * that the words on a picture are words on a panel rather than words on a photograph.
 */

/** A rendered deck of one slide, opened for reading its parts. */
function opacityDeckFile(string $markdown): Pptx
{
    $path = Scratch::file('opacity.pptx', '');

    (new PresentationRenderer(directory: Scratch::directory()))
        ->render((new DeckParser())->parse($markdown), $path);

    return Pptx::open($path);
}

it('writes the alpha of a placed text block into the colour of its fill', function () {
    // A panel the words sit on: the text block is a box like any other and takes a fill and an
    // alpha like any other, which is what makes a picture under it readable.
    $deck = opacityDeckFile(
        "---\nlayout: blank\n---\n\n:::content{left=10 top=10 width=400 height=200 fill=accent1 alpha=60}\nWords.\n:::\n",
    );

    expect($deck->part('ppt/slides/slide1.xml'))->toContain('<a:alpha val="60000"/>');
});

it('writes the alpha of an ink shape into the colour of its fill and of its stroke', function () {
    // One alpha for the shape rather than one per colour: a shape that is seen slightly through
    // the page is one shape, not a fill and an outline that disagree about how solid they are.
    $deck = opacityDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{left=10 top=10 width=200 height=100 fill=accent1 stroke=accent2 alpha=75}\n",
    );

    expect($deck->part('ppt/slides/slide1.xml'))
        ->toContain('<a:alpha val="75000"/>')
        ->and(substr_count($deck->part('ppt/slides/slide1.xml'), '<a:alpha val="75000"/>'))->toBe(2);
});

it('writes an alpha of zero as an alpha of zero', function () {
    // The whole of the shape is gone rather than the whole of it being there: a shape that is
    // invisible and a shape that is opaque are two different things, and one is not the other.
    $deck = opacityDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{left=10 top=10 width=200 height=100 fill=accent1 alpha=0}\n",
    );

    expect($deck->part('ppt/slides/slide1.xml'))->toContain('<a:alpha val="0"/>');
});

it('writes the alpha of a gradient into every stop of it', function () {
    $deck = opacityDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{left=10 top=10 width=200 height=100 gradient=accent1,accent2 alpha=70}\n",
    );

    expect(substr_count($deck->part('ppt/slides/slide1.xml'), '<a:alpha val="70000"/>'))->toBe(2);
});

it('refuses an alpha on a picture', function () {
    // A picture is painted by its own bytes: it takes no fill and no alpha, and the attribute
    // is refused rather than ignored, so that a deck that asks for one is told why.
    expect(fn () => opacityDeckFile(
        "---\nlayout: blank\n---\n\n::image{src=\"logo.png\" alpha=50}\n",
    ))->toThrow(MalformedShape::class, 'alpha');
});

it('draws the picture, then the panel over it, then the words over that', function () {
    // The order the shapes are written in is the order they are drawn in, and it is what makes
    // the words legible over a photograph: the panel between them is what the words are read
    // against.
    $picture = Scratch::file('opacity.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    ));

    $deck = opacityDeckFile(sprintf(
        "---\nlayout: blank\n---\n\n::image{src=\"%s\" left=0 top=0 width=960 height=540}\n"
        . "::rectangle{left=0 top=0 width=960 height=540 fill=accent1 alpha=60}\n"
        . ":::content{left=40 top=200 width=600 height=140}\nReadable\n:::\n",
        basename($picture),
    ));

    expect($deck->shapeNames())->toBe(['image', 'rectangle', 'content'])
        ->and($deck->slideText())->toContain('Readable');
});
