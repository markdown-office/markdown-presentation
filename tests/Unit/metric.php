<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\UnknownFont;
use MarkdownPresentation\Render\FontMetrics;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\TextMetric;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\ThemeResolver;

/**
 * A one-column table whose only cell is `$z` characters of `z`.
 *
 * A function because the length is the claim: 500 characters wrap to four lines and a row is
 * 38px plus 19px a line, 60 characters fit one column's width and 292 wrap to two. Spelling
 * the shape out three times is how one of those numbers stops being what the comment above
 * it says.
 */
function oneColumnTable(int $z): string
{
    return "| A |\n| --- |\n| " . str_repeat('z', $z) . " |\n";
}

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

/**
 * Render a deck in a theme of its own and open what came out.
 *
 * The theme is named by a name that ships with the library or by the path of a
 * directory, and the render is told that directory so it may read the theme there.
 */
function measuredIn(string $theme, string $markdown): Pptx
{
    $deck = (new DeckParser())->parse(sprintf("---\ntheme: %s\n---\n%s", $theme, $markdown));
    $path = Scratch::file('deck.pptx', '');
    $directory = dirname($theme);

    (new PresentationRenderer($directory, new ThemeResolver($directory)))->render($deck, $path);

    return Pptx::open($path);
}

/**
 * A theme naming a monospaced face and nothing else of its own.
 *
 * Two of these differ in nothing but the face they name, so what a comparison between
 * them shows is the face and nothing else.
 */
function themeNamingMono(string $face): string
{
    return ThemeDirectory::create([
        'theme.yml' => sprintf("name: Mono\nfonts:\n  mono: \"%s\"\n", $face),
        'layouts.yml' => "layouts:\n  title: Title Slide\n  content: Title and Content\n",
    ]);
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
    // Exact rather than approximate: every character in a monospaced face advances by the
    // same amount, and a code block is the one kind of block always set in one. Consolas
    // advances by 550 units to the em, so every printable character is 550 wide.
    $metric = new TextMetric();
    $widths = [];

    foreach (str_split(' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~') as $character) {
        $widths[] = $metric->width([TextMetric::piece($character, 1000, face: 'Consolas')]);
    }

    expect(array_values(array_unique($widths)))->toBe([550])
        ->and($metric->width([TextMetric::piece('iiii', 12, face: 'Consolas')]))
        ->toBe($metric->width([TextMetric::piece('WWWW', 12, face: 'Consolas')]));
});

it('measures a monospaced run at the advance of the face it names', function () {
    // The face is what says how wide a character is, and the three monospaced faces the
    // shipped themes name advance by different amounts: Consolas by 550 units to the em,
    // Menlo by 602 and JetBrains Mono by 600.
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('iiii', 12, face: 'Consolas')]))
        ->toBe((int) round(4 * 550 * 12 / 1000))
        ->and($metric->width([TextMetric::piece('iiii', 12, face: 'Menlo')]))
        ->toBe((int) round(4 * 602 * 12 / 1000))
        ->and($metric->width([TextMetric::piece('iiii', 12, face: 'JetBrains Mono')]))
        ->toBe((int) round(4 * 600 * 12 / 1000));
});

it('measures every shipped theme\'s monospaced face at its own advance', function () {
    // Four of the five shipped themes name Consolas or Menlo, and neither advances by the
    // 600 units a single number for a monospaced face would give them — Consolas by nine
    // per cent less. A theme naming one of them is measured in that face.
    $resolver = new ThemeResolver();
    $metric = new TextMetric();
    $faces = [];

    foreach ($resolver->available() as $name) {
        $face = $resolver->resolve($name)->theme()->fonts()->get('mono');

        expect($face)->not->toBeNull();

        $faces[] = (string) $face;

        expect($metric->width([TextMetric::piece('x', 1000, face: (string) $face)]))
            ->toBe(FontMetrics::MONOSPACED[(string) $face]);
    }

    expect($faces)->toContain('Consolas', 'Menlo', 'JetBrains Mono')
        ->and(FontMetrics::MONOSPACED['Consolas'])->not->toBe(600)
        ->and(FontMetrics::MONOSPACED['Menlo'])->not->toBe(600)
        ->and(FontMetrics::MONOSPACED['JetBrains Mono'])->toBe(600);
});

it('measures a code block in the monospaced face its theme names', function () {
    // Consolas advances by 550 units to the em and JetBrains Mono by 600, so a line of
        // 95 characters is one line in the first and two in the second.
    // differ in nothing else, so what differs is the face the code is drawn in.
    $code = "```\n" . str_repeat('x', 95) . "\n```\n";

    expect(measuredIn(themeNamingMono('Consolas'), $code)->positionOf('code')['height'])
        ->toBe(19 + 2 * 5.0)
        ->and(measuredIn(themeNamingMono('JetBrains Mono'), $code)->positionOf('code')['height'])
        ->toBe(2 * 19 + 2 * 5.0);
});

it('measures bold a little wider than the same character upright', function () {
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('mm', 16, bold: true)]))
        ->toBeGreaterThan($metric->width([TextMetric::piece('mm', 16)]));
});

it('measures a run with the face its theme names rather than with the reference face', function () {
    // Calibri's `n` is 525 units to the em and Arial's is 556, so the same word is 5.6%
    // narrower in the face the default theme draws it in. Which face a word is drawn in is
    // what decides where a line breaks, so it is what the measurement has to use.
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('nnn', 1000, face: 'Calibri')]))
        ->toBe(3 * 525)
        ->and($metric->width([TextMetric::piece('nnn', 1000, face: 'Arial')]))
        ->toBe(3 * 556)
        ->and($metric->width([TextMetric::piece('nnn', 1000)]))
        ->toBe(3 * 556);
});

it('wraps a line where the face it is drawn in wraps it', function () {
    // Twelve `n` at 16pt are 101px of Calibri and 107px of Arial, so a box 104px wide
    // holds one line of the first and two of the second. Where a line breaks is the whole
    // of what the measurement is for.
    $metric = new TextMetric();
    $word = str_repeat('n', 12);

    expect($metric->lines([TextMetric::piece($word, 16, face: 'Calibri')], 104))->toBe(1)
        ->and($metric->lines([TextMetric::piece($word, 16, face: 'Arial')], 104))->toBe(2);
});

it('measures a bold run with the widths of the bold face', function () {
    // Arial's upright `m` is 833 units to the em and its bold one is 889; Inter's are 876
    // and 913. The bold face is wider by its own amount, which is not the same amount in
    // every face and is not the five per cent every bold run used to be made wider by.
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('mm', 1000, bold: true, face: 'Arial')]))
        ->toBe(2 * 889)
        ->and($metric->width([TextMetric::piece('mm', 1000, face: 'Arial')]))
        ->toBe(2 * 833)
        ->and($metric->width([TextMetric::piece('mm', 1000, bold: true, face: 'Inter')]))
        ->toBe(2 * 913);
});

it('derives the table for Arial from the widths every face used to be measured with', function () {
    // Liberation Sans is metrically identical to Arial and Arial to Helvetica, so the
    // table derived for Arial is the table this renderer used to measure every face
    // with. If the two are not the same, the parser that read the font is wrong.
    expect(FontMetrics::TABLES['Arial']['regular'])->toBe([
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
        '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
        '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
        'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
        'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722,
        'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
    ]);
});

it('takes a character outside ASCII at a full em when it is three bytes or more', function () {
    // A CJK ideograph or an emoji, which is a full em in every face the table covers — and
    // counted once, not once per byte, which is what a byte-at-a-time reader gets wrong.
    $metric = new TextMetric();

    expect($metric->width([TextMetric::piece('漢', 1000)]))->toBe(1000)
        ->and($metric->width([TextMetric::piece('漢漢', 1000)]))->toBe(2000)
        ->and($metric->width([TextMetric::piece('漢', 1000, face: 'Menlo')]))->toBe(602);
});

it('takes a character outside ASCII at the mean of the table when it is two bytes', function () {
    // An accented Latin letter, which is about as wide as the letter it accents, so it
    // takes the average of the printable ASCII rather than a per-character width nobody
    // has measured. `é` at a thousand points is that mean, exactly.
    $metric = new TextMetric();
    $table = FontMetrics::TABLES[FontMetrics::REFERENCE]['regular'];

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

    expect($metric->lines([TextMetric::piece('    x', 16, face: 'Consolas')], 48))->toBe(1)
        ->and($metric->lines([TextMetric::piece('     x', 16, face: 'Consolas')], 48))->toBe(2);
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
    // `x` is 433 units to the em, so 500 of them at 16pt are 4619px of type — 16pt is
    // 21.3px, and a width is measured in pixels because a box is — in the 860px the body
    // box leaves for the text: five lines and a bit, so six, and six times the 26px a
    // line of body text is. Written on one line, so there is no break in it for the
    // theme's gap to follow.
expect(measured(str_repeat('x', 500) . "\n")->positionOf('text')['height'])->toBe(156.0);
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
    // `##` is 20pt and bold, and Calibri's bold `h` is 537 units to the em, so 500 of
    // them are 7160px of type in the 860px the body box leaves: eight lines and a bit,
    // so nine, and nine times the 32px a line at that size is.
        // A heading first, because the first heading of a slide is its title and is drawn in
        // the title box rather than in the body: this is the second one that is measured.
        expect(measured("# Lead\n\n## " . str_repeat('h', 500) . "\n")->positionOf('text')['height'])
->toBe(288.0);
});

it('measures a quote by the lines it wraps to', function () {
    // A quote is indented by a gutter and set at 15pt, which is 20px of em and 24px a
    // line. Calibri's `q` is 525 units to the em, so 500 of them are 5250px of type in
    // the 840px the text has left: six lines and a bit, so seven.
    expect(measured('> ' . str_repeat('q', 500) . "\n")->positionOf('text')['height'])
->toBe(168.0);
});

it('measures a list by the lines its items wrap to', function () {
    // One item of six lines, so the same six lines of type as the paragraph above and no
    // gap, the item having nothing under it inside the frame. The item has a gutter of
    // its own, so its text has 840px rather than 860px, and the paragraph's five lines
    // and a bit are six.
expect(measured('- ' . str_repeat('x', 500) . "\n")->positionOf('text')['height'])->toBe(156.0);
});

it('measures a code line by its characters, so a long one wraps', function () {
    // 550 units at 12pt is 8.8px a character — 12pt is 16px, and a width is measured in
    // pixels because a box is — so the 860px of text width holds 97 of them and a
    // 98-character line needs two rows. This was `lines × 18 + 24`: a sixty-line
    // block measured 1104px, on a slide 540 tall.
expect(measured("```\n" . str_repeat('x', 97) . "\n```\n")->positionOf('code')['height'])
        ->toBe(19 + 2 * 5.0)
->and(measured("```\n" . str_repeat('x', 98) . "\n```\n")->positionOf('code')['height'])
        ->toBe(2 * 19 + 2 * 5.0);
});

it('gives a table row the height of the lines its longest cell wraps to', function () {
    // The header is one line and takes a 38px row; the body cell is 3160px of type in a
    // column 880 wide — Calibri's `z` is 395 units to the em, and a table cell is set at
    // 12pt, which is 16px — so four lines of 19px.
    expect(measured(oneColumnTable(500))->positionOf('table')['height'])
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
    $markdown = oneColumnTable(60) . "| b |\n";
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
    // row taller than the text in it needs. 367 `z` are 1740px of type in an 880px column:
    // three lines once 12px of that column is padding, two without.
    $markdown = oneColumnTable(367);

    expect(measured($markdown)->positionOf('table')['height'])->toBe(38.0 + 3 * 19.0);
});

/**
 * Render a deck in a theme of its own, returning the renderer, because the report of the
 * faces it could not measure is on the renderer.
 */
function renderedIn(string $theme, bool $strict = false): PresentationRenderer
{
    $renderer = new PresentationRenderer(strict: $strict);
    $renderer->render(
        (new DeckParser())->parse(sprintf("---\ntheme: %s\n---\n# One\n", $theme)),
        Scratch::file('deck.pptx', ''),
    );

    return $renderer;
}

/**
 * A theme naming a face there is no table for.
 */
function themeWithAnUnknownFace(): string
{
    return ThemeDirectory::create([
        'theme.yml' => "name: Odd\nfonts:\n  heading: \"Comic Sans MS\"\n  body: \"Comic Sans MS\"\n",
        'layouts.yml' => "layouts:\n  title: Title Slide\n  content: Title and Content\n",
    ]);
}

it('reports a face a theme names that there is no table for', function () {
    // A theme naming a face nobody has measured still renders: the text is measured with
    // the reference face's widths, which are not that face's, and this is where that is
    // said. A deck laid out with the wrong face and nothing to say so is the failure the
    // tables of faces exist to end.
    expect(renderedIn(themeWithAnUnknownFace())->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => "the theme's heading slot"],
        ['face' => 'Comic Sans MS', 'where' => "the theme's body slot"],
    ]);
});

it('reports no face for a theme whose faces are all ones there are tables for', function () {
    // The default theme names Calibri and Consolas, and there is a table for the first and
    // the second is monospaced, so there is nothing to say about it.
    expect(renderedIn('default')->unknownFonts())->toBe([]);
});

it('refuses a deck whose theme names a face it cannot measure when the renderer is strict', function () {
    // Strict is for a caller that would rather have no file than a file whose text was
    // measured with another face's widths, and the message names the faces there are
    // tables for.
    expect(fn () => renderedIn(themeWithAnUnknownFace(), strict: true))
        ->toThrow(UnknownFont::class, 'The face "Comic Sans MS" has no table of advance widths')
        ->toThrow(UnknownFont::class, "It is named by the theme's heading slot");
});

