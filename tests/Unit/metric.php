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
    // box leaves: five lines, and five times the 26px a line of body text is. Written on one
    // line, so there is no break in it for the theme's gap to follow.
    expect(measured(str_repeat('x', 500) . "\n")->positionOf('text')['height'])->toBe(130.0);
});

it('measures a run boundary as a change of face rather than as a line', function () {
    // The cursor used to restart at every run, so a sentence written with markup cost a
    // line per `**bold**` in it: this one reads as one line and measured as six, and the
    // block after it was clamped to nothing.
    $metric = new TextMetric();
    $runs = [
        TextMetric::piece('A paragraph with ', 16),
        TextMetric::piece('bold', 16, bold: true),
        TextMetric::piece(', ', 16),
        TextMetric::piece('italic', 16),
        TextMetric::piece(' and a link', 16),
        TextMetric::piece(' in it.', 16),
    ];

    expect($metric->lines($runs, 4000))->toBe(1)
        ->and($metric->lines($runs, 4000))
        ->toBe($metric->lines([TextMetric::piece('A paragraph with bold, italic and a link in it.', 16)], 4000));
});

it('measures a paragraph carrying markup as the paragraph it reads as', function () {
    // The same claim read off a written file, because the number was never the complaint:
    // the paragraph was over-allocated by its own markup, which pushed the block below it
    // down and had that one clamped.
    $sentence = 'A paragraph with bold, italic, both, code, struck and a link in it.';
    $marked = str_replace(
        ['bold', 'italic', 'both', 'code', 'struck', 'link'],
        ['**bold**', '*italic*', '***both***', '`code`', '~~struck~~', '[link](https://example.com)'],
        $sentence,
    );

    expect(measured("# Lead\n\n$marked\n")->positionOf('text')['height'])
        ->toBe(measured("# Lead\n\n$sentence\n")->positionOf('text')['height']);
});

it('measures a heading by the lines it wraps to', function () {
        // `##` is 20pt and bold, so the same 500 characters are wider again and still come to
        // seven lines of the 32px a line at that size is.
        // A heading first, because the first heading of a slide is its title and is drawn in
        // the title box rather than in the body: this is the second one that is measured.
        expect(measured("# Lead\n\n## " . str_repeat('h', 500) . "\n")->positionOf('heading')['height'])
            ->toBe(224.0);
});

it('measures a quote by the lines it wraps to', function () {
    // A quote is indented by a gutter and set at 15pt, which is 24px a line.
    expect(measured('> ' . str_repeat('q', 500) . "\n")->positionOf('quote')['height'])
        ->toBe(120.0);
});

it('measures a list by the lines its items wrap to', function () {
    // One item of five lines, so the same five lines of type as the paragraph above and no
    // gap, the item having nothing under it inside the frame.
    expect(measured('- ' . str_repeat('x', 500) . "\n")->positionOf('text')['height'])->toBe(130.0);
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

/**
 * The widths a table's grid was written at, in pixels, straight out of `<a:gridCol>`.
 *
 * @return list<float>
 */
function columnsOf(string $markdown): array
{
    preg_match_all('#<a:gridCol w="(\d+)"#', measured($markdown)->part('ppt/slides/slide1.xml'), $found);

    return array_map(static fn (string $emu): float => ((int) $emu) / 9525.0, $found[1]);
}

it('gives a table\'s columns the width of what they hold', function () {
    // Equal columns came from `intdiv(880, $columns)`, which is a third of the body box for
    // every column whatever it says: a column whose header is one character and a column
    // whose cells are a sentence were drawn the same, and the narrow one wrapped to one
    // word a line while the wide one sat empty.
    $markdown = "| A |\n| --- |\n| " . str_repeat('z', 60) . " |\n| b |\n";
    $widths = columnsOf($markdown);

    expect($widths[0])->toBe(880.0);
});

it('divides a table\'s width between its columns exactly', function () {
    // `intdiv` drops up to a pixel per column: twelve columns of 880 came to 876 and three
    // came to 879, and a grid narrower than the shape it sits in is one PowerPoint lays out
    // again itself.
    foreach ([3, 7, 12] as $columns) {
        $row = implode('|', array_fill(0, $columns, ' x '));
        $align = implode('|', array_fill(0, $columns, ' --- '));
        $widths = columnsOf("|$row|\n|$align|\n");

        expect(array_sum($widths))->toBe(880.0);
    }
});

it('never draws a table column narrower than a label fits', function () {
    // Proportional widths alone would let one very long column starve the rest: a table of
    // two columns, one holding a sentence and one holding a letter, would give the letter
    // a column too narrow to show it.
    $markdown = "| " . str_repeat('z', 60) . " | b |\n| --- | --- |\n| " . str_repeat('z', 60) . " | b |\n";
    $widths = columnsOf($markdown);

    expect(min($widths))->toBeGreaterThanOrEqual(80.0);
});

it('keeps a table cell\'s text off its neighbour\'s', function () {
    // PHPPresentation writes a cell's `a:bodyPr` with no insets and gives `Cell` no inset
    // to set, so a cell's text starts exactly at its edge: a right-aligned cell and the
    // left-aligned one beside it met at the border and read as one word, which is what
    // `struck` and `plain` became in the built examples. The paragraph's own margins are
    // what stands in for the inset, on both sides, or a right-aligned cell would still
    // touch the one after it. 57150 EMU is 6px, which is `SlideGeometry::TABLE_PADDING`
    // written out rather than read back, so the assertion is on what was written.
    $xml = measured("| a | b |\n| :--- | ---: |\n| 1 | 2 |\n")->part('ppt/slides/slide1.xml');
    preg_match_all('#<a:pPr[^>]*>#', $xml, $found);

    expect($found[0])->toHaveCount(4);

    foreach ($found[0] as $properties) {
        expect($properties)->toContain('marL="57150"')->toContain('marR="57150"');
    }
});

it('measures a table cell against the width its padding leaves', function () {
    // The padding is not free: a cell measured against the whole column uses six pixels its
    // text does not have, and a cell whose last line is exactly that wide wraps — which is a
    // row taller than the text in it needs. 292 `z` are 1752px of type in an 880px column:
    // three lines once 12px of that column is padding, two without.
    $markdown = "| A |\n| --- |\n| " . str_repeat('z', 292) . " |\n";

    expect(measured($markdown)->positionOf('table')['height'])->toBe(38.0 + 3 * 19.0);
});