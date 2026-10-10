<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\TextMetric;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * The advance-width model, asked of itself and then asked of a written file.
 *
 * The model is an approximation, and every decision in it — the reference face, the
 * monospaced width, what to do with a character the table does not have — is a place where
 * the next person to tune it needs to know what was chosen and why.
 */

/** Render a deck and open what came out, in one step. */
function measured(string $markdown): Pptx
{
    $deck = (new DeckParser())->parse($markdown);
    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer())->render($deck, $path);

    return Pptx::open($path);
}

it('measures a run of narrow letters more narrowly than a run of wide ones', function () {
    // `i` is 222 units and `W` is 944, so four of each differ by a factor of four. A count
    // of characters cannot tell them apart, and `i` is the commonest letter in English.
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('iiii', 16)]))
        ->toBeLessThan($metric->width([TextMetric::piece('WWWW', 16)]))
        ->and($metric->width([TextMetric::piece('iiii', 16)]))
        ->toBe((int) round(4 * 222 * 16 / 1000));
});

it('measures every character of a monospaced face the same', function () {
    // Exact rather than approximate: Consolas, Menlo and JetBrains Mono all advance 600
    // units, and a code block is the one kind of block always set in one.
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('iiii', 12, mono: true)]))
        ->toBe($metric->width([TextMetric::piece('WWWW', 12, mono: true)]))
        ->toBe((int) round(4 * 600 * 12 / 1000));
});

it('measures bold a little wider than the same character upright', function () {
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('mm', 16, bold: true)]))
        ->toBeGreaterThan($metric->width([TextMetric::piece('mm', 16)]));
});

it('takes a character outside ASCII at a full em when it is three bytes or more', function () {
    // A CJK ideograph or an emoji, which is a full em in every face the table covers — and
    // counted once, not once per byte, which is what a byte-at-a-time reader gets wrong.
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('漢', 1000)]))->toBe(1000)
        ->and($metric->width([TextMetric::piece('漢漢', 1000)]))->toBe(2000)
        ->and($metric->width([TextMetric::piece('漢', 1000, mono: true)]))->toBe(600);
});

it('takes a character outside ASCII at the mean of the table when it is two bytes', function () {
    // An accented Latin letter, which is about as wide as the letter it accents, so it
    // takes the average of the printable ASCII rather than a per-character width nobody
    // has measured. `é` at a thousand points is that mean, exactly.
    $metric = new TextMetric();
    /** @var array<string, int> $table */
    $table = (new ReflectionClass(TextMetric::class))->getConstant('SANS');

    expect($metric->width([TextMetric::piece('é', 1000)]))
        ->toBe(intdiv(array_sum($table), count($table)));
});

it('wraps a run of text at the width it is given', function () {
    $metric = new TextMetric();
    $sentence = [TextMetric::piece('one two three four five six seven eight', 16)];

    expect($metric->lines($sentence, 4000))->toBe(1)
        ->and($metric->lines($sentence, 200))->toBeGreaterThan(1);
});

it('breaks a word too long for a line of its own rather than letting it overflow', function () {
    // A URL or an identifier with no space in it is ordinary in prose, and the reader does
    // break it mid-word. Counting it as one line is how a paragraph comes out short.
    $metric = new TextMetric();

    expect($metric->lines([TextMetric::piece(str_repeat('x', 300), 16)], 200))->toBe(12);
});

it('counts a newline inside a run as a break the deck asked for', function () {
    // Not a space: a soft line break in the Markdown is a newline the author wrote, and it
    // starts a line whatever the box is wide.
    expect((new TextMetric())->lines([TextMetric::piece("one\ntwo", 16)], 4000))->toBe(2);
});

it('counts the indentation of a line as part of it', function () {
    // The whitespace between words is kept rather than discarded, which is what an
    // indented code block depends on: five spaces in is five spaces of the first line.
    $metric = new TextMetric();

    expect($metric->lines([TextMetric::piece('    x', 16, mono: true)], 48))->toBe(1)
        ->and($metric->lines([TextMetric::piece('     x', 16, mono: true)], 48))->toBe(2);
});

it('measures a box narrower than a pixel rather than looping on it', function () {
    expect((new TextMetric())->lines([TextMetric::piece('word', 16)], 0))->toBeGreaterThan(0);
});

it('measures an empty string as one line', function () {
    // A paragraph with nothing in it still occupies a line; this is what the `max(1, …)`
    // used to be for, and it now falls out of wrapping rather than being a special case.
    expect((new TextMetric())->lines([TextMetric::piece('', 16)], 4000))->toBe(1);
});

it('measures a paragraph by the lines it wraps to', function () {
    // `x` is 500 units, so 500 of them at 16pt is 4000px of type in the 860px the body
    // box leaves: five lines, and five times the 26px a line of body text is.
    expect(measured(str_repeat('x', 500) . "\n")->positionOf('paragraph')['height'])->toBe(130.0);
});

it('measures a heading by the lines it wraps to', function () {
    // `##` is 20pt and bold, so the same 500 characters are wider again and still come to
    // seven lines of the 32px a line at that size is.
    // A paragraph first, because a heading opening a slide is its title and is not drawn
    // in the body at all.
    expect(measured("Lead.\n\n## " . str_repeat('h', 500) . "\n")->positionOf('heading')['height'])
        ->toBe(224.0);
});

it('measures a quote by the lines it wraps to', function () {
    // A quote is indented by a gutter and set at 15pt, which is 24px a line.
    expect(measured('> ' . str_repeat('q', 500) . "\n")->positionOf('quote')['height'])
        ->toBe(120.0);
});

it('measures a list by the lines its items wrap to', function () {
    expect(measured('- ' . str_repeat('x', 500) . "\n")->positionOf('list')['height'])->toBe(130.0);
});

it('measures a code line by its characters, so a long one wraps', function () {
    // 600 units at 12pt is 7.2px a character, so the 860px of text width holds 119 of them
    // and a 130-character line needs two rows. This was `lines × 18 + 24`: a sixty-line
    // block measured 1104px, on a slide 540 tall.
    expect(measured("```\n" . str_repeat('x', 130) . "\n```\n")->positionOf('code')['height'])
        ->toBe(2 * 19 + 2 * 5.0);
});

it('gives a table row the height of the lines its longest cell wraps to', function () {
    // The header is one line and takes a 38px row; the body cell is 3000px of type in a
    // column 880 wide, so four lines of 19px.
    expect(measured("| A |\n| --- |\n| " . str_repeat('z', 500) . " |\n")->positionOf('table')['height'])
        ->toBe(38 + 4 * 19.0);
});