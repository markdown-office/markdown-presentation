<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * A placed text block is drawn at the height the deck declared.
 *
 * It used to be shrunk to the height its own text needed. `height=48 vertical-align=middle`
 * was written at the 23px one line at 14pt takes, anchored centre — so the text centred in
 * 23px sitting at the top of a 48px space and rode high by (48 − 23) / 2, and the box in the
 * file was not the box the deck wrote. `vertical-align` had nothing to align against, and the
 * usual way to get words onto a shape — draw the shape, put a `:::content` box over it with
 * the same alignment — could not work, because the box would not stay where it was put.
 *
 * Only an overflow takes the room away now, and it is reported when it does. So these assert
 * the written height, read back out of the file: the declaration is the contract, and the
 * measurement is what may be overruled by it.
 */

/** The written height of the only shape on a one-slide deck, in pixels. */
function writtenHeight(string $attributes, string $text, int $height): int
{
    $path = Scratch::file('vertical.pptx', '');

    (new PresentationRenderer())->render(
        (new DeckParser())->parse(sprintf(
            "---\nlayout: blank\n---\n\n:::content{left=10 top=100 %s height=%d font-size=14 vertical-align=middle}\n%s\n:::\n",
            $attributes,
            $height,
            $text,
        )),
        $path,
    );

    return (int) round((Pptx::open($path)->positions()[0]['height'] ?? 0));
}

it('draws a placed text block at the height the deck declared, not at its text', function () {
    // One line of 14pt needs about 23px. The deck asked for 120 and is what it gets, which is
    // the room `vertical-align=middle` centres inside.
    expect(writtenHeight('width=300', 'A single line.', 120))->toBe(120)
        ->and(writtenHeight('width=300', 'A single line.', 240))->toBe(240);
});

it('keeps the declared height for a block whose text fills it', function () {
    expect(writtenHeight('width=300', 'A single line.', 23))->toBe(23);
});

it('clamps a placed text block to its box when the text does not fit, and says so', function () {
    $path = Scratch::file('clamped.pptx', '');
    $renderer = new PresentationRenderer();

    $renderer->render(
        (new DeckParser())->parse(
            "---\nlayout: blank\n---\n\n:::content{left=10 top=0 width=300 height=30 font-size=14}\n"
            . implode("\n\n", array_fill(0, 20, 'A line.'))
            . "\n:::\n",
        ),
        $path,
    );

    expect((int) round(Pptx::open($path)->positions()[0]['height'] ?? 0))->toBe(30)
        ->and($renderer->overflows())->toHaveCount(1)
        ->and($renderer->overflows()[0]['block'])->toBe('content')
        ->and($renderer->overflows()[0]['wanted'])->toBeGreaterThan(30);
});
