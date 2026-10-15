<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * The width a placed text block is measured in is the width it is drawn in.
 *
 * `padding` writes an inset onto the shape through {@see
 * \MarkdownPresentation\Render\ShapeWriter::freeText()}, and it used not to reach
 * {@see \MarkdownPresentation\Render\ShapeWriter::textWidth()}, which subtracted the default
 * inset whatever the deck had asked for. The file and the measurement therefore disagreed, in
 * both directions and neither of them visible from the deck:
 *
 * - a `padding` below the default was measured wider than it is drawn, so blocks were
 *   over-estimated, clamped and reported as not fitting when they fit; and
 * - a `padding` above it was measured narrower, so the box was not grown and the text ran
 *   over whatever was under it — **without appearing in `overflows()` at all**, which is the
 *   one outcome this library states it will not produce.
 *
 * Every height here is read back out of the written file rather than asked of the renderer,
 * because the number under test is the one a reader ends up looking at and the renderer's own
 * opinion of it is the thing that was wrong. The insets themselves were always right —
 * `tests/Unit/free-shapes.php` pins that half — and this is about the other half.
 */

/**
 * The drawn height of one placed text block in pixels, and whether it was reported as not
 * fitting.
 *
 * @return array{0: int, 1: bool}
 */
function placedText(string $attributes, string $text, int $height): array
{
    $path = Scratch::file('padding.pptx', '');

    $renderer = new PresentationRenderer();
    $renderer->render(
        (new DeckParser())->parse(sprintf(
            "---\nlayout: blank\n---\n\n:::content{left=10 top=0 %s height=%d font-size=14}\n%s\n:::\n",
            $attributes,
            $height,
            $text,
        )),
        $path,
    );

    preg_match('#<a:ext cx="\d+" cy="(\d+)"#', Pptx::open($path)->part('ppt/slides/slide1.xml'), $extension);

    // EMU per pixel: 9525. Rounded the way the renderer rounds a box, so the assertion is
    // about the layout rather than about the conversion.
    return [(int) round(((int) ($extension[1] ?? 0)) / 9525), $renderer->overflows() !== []];
}

/**
 * Text whose wrapping changes between a 280px column and a 300px one, which is the width a
 * 300px box is measured in with and without a `padding=0`.
 *
 * Counted rather than written out: the shape of this fixture is that it straddles a boundary,
 * and a hand-counted list of words is exactly the kind of fixture that stops straddling it
 * when somebody edits a word without re-counting the rest.
 */
function straddling(): string
{
    return implode(' ', [
        ...array_fill(0, 3, 'Wanderung'),
        'Nuremberg',
        ...array_fill(0, 20, 'Trudering'),
    ]);
}

/**
 * A paragraph short enough for one box to hold and long enough that its wrapping changes when
 * the column it is measured in narrows from 280px to 180px.
 */
function shortBody(): string
{
    return 'Wanderung Trudering Nuremberg Schweinfurt Kitzingen Wurzburg';
}

it('measures a padding=0 block in the whole box rather than twenty pixels less', function () {
    // Both boxes leave 300px for the text: one because it has no padding and is 320 wide, one
    // because it asks for none and is 300 wide. They have to come out the same height, and
    // shorter than the 280px column the default inset leaves a 300px box.
    [$padded] = placedText('width=300 padding=0', straddling(), 520);
    [$inset] = placedText('width=320', straddling(), 520);

    expect($padded)->toBe($inset)
        ->and($padded)->toBeLessThan(placedText('width=300', straddling(), 520)[0]);
});

it('measures a wide padding in the width the padding leaves', function () {
    // 300 less 120 is 180, which is narrow enough for the same words to need a second line
    // that the 280px default column did not.
    expect(placedText('width=300 padding=60', shortBody(), 520)[0])
        ->toBeGreaterThan(placedText('width=300 padding=0', shortBody(), 520)[0]);
});

it('reports a block that a wide padding pushes past its box', function () {
    // The box is tall enough for the height the default inset measures and not tall enough
    // for the height this padding produces. Unfixed, nothing is reported and the text is
    // drawn over whatever is below it.
    [, $reported] = placedText('width=300 padding=60', shortBody(), 50);

    expect($reported)->toBeTrue();
});

it('reports a box narrower than its own padding rather than measuring a column', function () {
    // 60 wide with 60 of padding leaves nothing, which is not a one-pixel column to lay text
    // out in. The measurement clamped the difference to a pixel and the word-breaking loop
    // then counted one line per pixel of text.
    [$height, $reported] = placedText('width=60 padding=60', 'Wanderung', 200);

    expect($reported)->toBeTrue()
        ->and($height)->toBeGreaterThan(0);
});
