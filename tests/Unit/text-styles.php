<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedTextStyle;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\UnknownFont;
use MarkdownPresentation\Exception\UnknownTextStyle;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Doctest;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\ThemeLoader;

/**
 * The theme the text-style tests below draw with.
 *
 * A directory of its own, because the styles are what these tests are about and a theme
 * that shipped with one would put a second claim into every assertion. The accent is
 * named here rather than left to the library's defaults, so that a colour in the file is a
 * colour the test wrote down.
 */
const TEXT_STYLE_THEME = "name: Acme\ncolors:\n  accent1: \"#00B4D8\"\n";

/**
 * The layouts the theme needs, because a theme that maps no `content` role cannot draw a
 * slide at all.
 */
const TEXT_STYLE_LAYOUTS = "layouts:\n  content: Title and Content\n  blank: Blank\n";

/**
 * The box the wordmark is drawn in, which has a colour of its own.
 *
 * The size and the position are the review's deck's own, because the claim is about the
 * colours and not about the geometry: a box that carries a colour of its own is the case
 * the feature answers.
 */
const WORDMARK_BOX = 'left=4.5% top=3.913% width=29.996% height=4.969% fill=none padding=0 color=FFFFFF font-size=11';

/**
 * A theme directory with the named styles beside it, or without the file at all.
 */
function textStyleTheme(?string $styles): string
{
    $files = [
        'theme.yml' => TEXT_STYLE_THEME,
        'layouts.yml' => TEXT_STYLE_LAYOUTS,
    ];

    if ($styles !== null) {
        $files['text-styles.yml'] = $styles;
    }

    return ThemeDirectory::create($files, 'text-style');
}

/**
 * A deck drawn in a directory of its own, so that the theme it names is the one beside it.
 */
function styledDeck(string $markdown, string $directory): Pptx
{
    $deck = (new DeckParser())->parse($markdown);
    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer($directory))->render($deck, $path);

    return Pptx::open($path);
}

/**
 * A deck whose one slide is a wordmark in a box, and the theme it is drawn with.
 *
 * @return array{0: string, 1: string} The deck and the directory it is drawn in.
 */
function wordmark(?string $styles, string $word): array
{
    return [
        sprintf(
            "---\ntheme: ./theme.yml\n---\n\n:::content{%s}\nResilience %s\n:::\n",
            WORDMARK_BOX,
            $word,
        ),
        textStyleTheme($styles),
    ];
}

/**
 * The runs of a slide, as the text of each and the colour and size it is drawn at.
 *
 * Read off the file rather than off the writer, because the claim is about what a reader
 * sees: the colour of a word and the size of a word are two facts about the run, and the
 * run is what the file holds.
 *
 * @return array<string, array{colour: string, size: ?int}>
 */
function runColours(Pptx $pptx, int $slide = 1): array
{
    $runs = [];

    // Split at each run rather than matching one: the shape's own fill carries a colour
    // too, and it is not a run.
    foreach (preg_split('#(?=<a:r>)#', $pptx->part("ppt/slides/slide{$slide}.xml")) ?: [] as $run) {
        if (preg_match('#<a:srgbClr val="([0-9A-Fa-f]{6})"#', $run, $colour) !== 1) {
            continue;
        }

        if (preg_match('#<a:t>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</a:t>#s', $run, $text) !== 1) {
            continue;
        }

        preg_match('#<a:rPr[^>]*\ssz="(\d+)"#', $run, $size);

        $runs[$text[1]] = [
            'colour' => $colour[1],
            'size' => isset($size[1]) ? (int) $size[1] / 100 : null,
        ];
    }

    return $runs;
}

/**
 * The run a word is drawn in, as the file holds it.
 */
function runXml(Pptx $pptx, string $text, int $slide = 1): string
{
    foreach (preg_split('#(?=<a:r>)#', $pptx->part("ppt/slides/slide{$slide}.xml")) ?: [] as $run) {
        if (str_contains($run, $text)) {
            return $run;
        }
    }

    return '';
}

/**
 * A deck drawn in a directory of its own, and the renderer that drew it.
 *
 * The renderer rather than the file, because the claim is about the report of the faces it
 * could not measure, and that report is on the renderer.
 */
function styledRenderer(string $markdown, string $directory, bool $strict = false): PresentationRenderer
{
    $deck = (new DeckParser())->parse($markdown);
    $path = Scratch::file('deck.pptx', '');

    $renderer = new PresentationRenderer($directory, strict: $strict);
    $renderer->render($deck, $path);

    return $renderer;
}

/**
 * A deck whose one slide is a word in a box that names a face of its own.
 */
function wordmarkIn(string $face): string
{
    return sprintf(
        "---\ntheme: ./theme.yml\n---\n\n:::content{left=10%% top=10%% width=30%% height=10%% font-size=11 font-family=%s}\nResilience\n:::\n",
        $face,
    );
}

it('colours a word inside a box that has a colour of its own', function () {
    // The wordmark of the review's deck: two words, two colours, one box. A box carried
    // one colour before, so the wordmark was two boxes.
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        'Bakery' => ['colour' => '00B4D8', 'size' => 11],
    ]);
});

it('draws the raw form, which names no style of its own', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':style[Bakery]{color=00B4D8}');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        'Bakery' => ['colour' => '00B4D8', 'size' => 11],
    ]);
});

it('overrides the named style\'s value for the property it names, and for that one only', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n  size: 20\n", ':brand[Bakery]{size=30}');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        'Bakery' => ['colour' => '00B4D8', 'size' => 30],
    ]);
});

it('sets the weight a style names, and leaves the rest of the run to the box', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n  bold: true\n", ':brand[Bakery]');

    expect(styledDeck($deck, $directory)->part('ppt/slides/slide1.xml'))
        ->toMatch('#<a:r>\s*<a:rPr[^>]*\bb="1"[^>]*>(?:(?!</a:rPr>).)*<a:srgbClr val="00B4D8">#s');
});

it('refuses a style the theme does not have, and names the styles it has', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\nmuted:\n  color: lt2\n", ':nope[Bakery]');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(UnknownTextStyle::class, 'The styles are: brand, muted.');
});

it('draws a style the theme spells with a capital letter, whichever case the deck writes it in', function () {
    // A style's name is a word to a reader, and `:Brand[Bakery]` and `:brand[Bakery]` are
    // the same word written twice — the directives package folds the names of the
    // directives themselves the same way.
    [$deck, $directory] = wordmark("Brand:\n  color: accent1\n", ':brand[Bakery]');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        'Bakery' => ['colour' => '00B4D8', 'size' => 11],
    ]);

    [$deck, $directory] = wordmark("Brand:\n  color: accent1\n", ':Brand[Bakery]');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        'Bakery' => ['colour' => '00B4D8', 'size' => 11],
    ]);

    // The refusal names the style as the theme spells it, because that is the spelling the
    // author has to change.
    [$deck, $directory] = wordmark("Brand:\n  color: accent1\nmuted:\n  color: lt2\n", ':nope[Bakery]');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(UnknownTextStyle::class, 'The styles are: Brand, muted.');
});

it('refuses an attribute a text style does not read, and names the attributes it reads', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{colour=accent1}');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(
            MalformedTextStyle::class,
            'The attributes are: color, size, font, bold, italic, underline, strike, highlight.',
        );
});

it('refuses a label that holds Markdown, because a style\'s text is plain text', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[**Bakery**]');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'which is Markdown');
});

it('refuses a label holding a strong emphasis written with two underscores', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[__Bakery__]');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'which is Markdown');
});

it('draws a label holding a strong emphasis CommonMark does not call one', function () {
    // A delimiter run that opens an emphasis may not be followed by whitespace, and one that
    // closes it may not be preceded by whitespace: `__ Bakery__` and `** text**` are text,
    // and a label holding one is drawn as it was written.
    [$deck, $directory] = wordmark(
        "brand:\n  color: accent1\n",
        ':brand[__ Bakery__] a :brand[** text**] b :brand[**Bakery **]',
    );

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        '__ Bakery__' => ['colour' => '00B4D8', 'size' => 11],
        ' a ' => ['colour' => 'FFFFFF', 'size' => 11],
        '** text**' => ['colour' => '00B4D8', 'size' => 11],
        ' b ' => ['colour' => 'FFFFFF', 'size' => 11],
        '**Bakery **' => ['colour' => '00B4D8', 'size' => 11],
    ]);
});

it('draws a label holding a strikethrough, because the tildes are two characters', function () {
    // `~~struck~~` is GFM rather than CommonMark, and a label is plain text: the tildes are
    // characters in a word rather than an intent.
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[~~Bakery~~]');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        '~~Bakery~~' => ['colour' => '00B4D8', 'size' => 11],
    ]);
});

it('draws a label holding a bare marker, because a marker is a character', function () {
    // `2 * 3` and `snake_case` are ordinary text. A rule that refused every label holding
    // a marker would refuse correct decks all day.
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[2 * 3] snake_case');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        '2 * 3' => ['colour' => '00B4D8', 'size' => 11],
        ' snake_case' => ['colour' => 'FFFFFF', 'size' => 11],
    ]);
});

it('refuses a label with no text in it, because a style needs some', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[]');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'with no text');
});

it('refuses the raw form written without an attribute, because it says nothing', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':style[Bakery]');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'which says nothing');
});

it('refuses a highlight, which this half does not draw', function () {
    // PHPPresentation's font has no highlight setter, so a highlight would need a pass
    // over the written package. Accepting the attribute and dropping it would be a deck
    // that renders and shows something the author did not write.
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{highlight=FFFF00}');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'a highlight, which the slides half does not draw');
});

it('draws a deck whose theme names no text styles at all', function () {
    // The words are one run, because there is nothing to tell them apart: a style is what
    // makes a word a run of its own.
    [$deck, $directory] = wordmark(null, 'Bakery');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience Bakery' => ['colour' => 'FFFFFF', 'size' => 11],
    ]);
});

it('refuses a named style against a theme that has no text styles', function () {
    [$deck, $directory] = wordmark(null, ':brand[Bakery]');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(UnknownTextStyle::class, 'The theme has no text styles');
});

it('draws a word in a named style a shipped theme brings', function () {
    // A shipped theme is the one a deck can name without carrying a theme of its own, and
    // one of them brings the named styles, so the form can be tried without one.
    $deck = sprintf(
        "---\ntheme: corporate-dark\n---\n\n:::content{%s}\nResilience :brand[Bakery]\n:::\n",
        WORDMARK_BOX,
    );

    expect(runColours(styledDeck($deck, Scratch::directory())))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        'Bakery' => ['colour' => '89B4FA', 'size' => 11],
    ]);
});

it('reports a face a word names that there is no table for, and not one there is a table for', function () {
    // A face a word writes for itself is drawn in it and measured in it, and a face with
    // no table of advance widths is measured with the reference face's widths — which are
    // not its own. Nothing said so before, and the promise the metrics work makes is that
    // a face that was not measured says something.
    [$deck, $directory] = wordmark(
        "brand:\n  color: accent1\n",
        ':style[Bakery]{font=Inter} and :style[Shop]{font="Comic Sans MS"}',
    );

    expect(styledRenderer($deck, $directory)->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => 'a font attribute on slide 1'],
    ]);
});

it('reports a face a word names once, however many words name it', function () {
    // A deck that writes the face on twenty words is one place, and one entry: the words
    // are on one slide and the attribute is the same one.
    [$deck, $directory] = wordmark(
        "brand:\n  color: accent1\n",
        ':style[Bakery]{font="Comic Sans MS"} and :style[Shop]{font="Comic Sans MS"}',
    );

    expect(styledRenderer($deck, $directory)->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => 'a font attribute on slide 1'],
    ]);
});

it('refuses a deck whose word names a face it cannot measure when the renderer is strict', function () {
    // Strict is for a caller that would rather have no file than a file whose text was
    // measured with another face's widths, and the message says where the face was named.
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':style[Bakery]{font="Comic Sans MS"}');

    expect(fn () => styledRenderer($deck, $directory, strict: true))
        ->toThrow(UnknownFont::class, 'The face "Comic Sans MS" has no table of advance widths')
        ->toThrow(UnknownFont::class, 'It is named by a font attribute on slide 1');
});

it('draws a link in a text style in the style\'s colour and keeps it underlined', function () {
    // The style is the more particular statement, so the link takes the style's colour
    // rather than the theme's hyperlink colour; the underline stays, so the word is still
    // a link to a reader. The reference says so too, because a reader would otherwise take
    // the theme's hyperlink colour for every link there is.
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", '[:brand[Bakery]](https://example.com)');

    $run = runXml(styledDeck($deck, $directory), 'Bakery');

    expect($run)->toMatch('#<a:srgbClr val="00B4D8">#')
        ->and($run)->toMatch('#\bu="sng"#')
        ->and($run)->toMatch('#<a:hlinkClick#');

    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

    preg_match('/### Inline styles(.*?)^### /ms', $readme, $section);

    expect($section[1] ?? '')->toContain("the style's colour")
        ->and($section[1] ?? '')->toContain('underline');
});

it('says in the reference that the round trip does not keep a text style', function () {
    // The page is wrong the moment this lands unless it says so: a deck read back out has
    // neither the named style nor the colour, and a reader who is not told that reads the
    // missing colour as a fault in the reader.
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

    preg_match('/### What the round trip does not keep(.*?)^## /ms', $readme, $section);

    expect($section[1] ?? '')->toContain('a text style')
        ->and($section[1] ?? '')->toContain('without the colour it was drawn in');
});

it('draws every flag an attribute names, and the face it names', function () {
    // A bare attribute asks for the attribute rather than for its value, which is a thing
    // only a flag can be: `{bold}` is a word set in bold.
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{font=Inter bold italic underline strike}');

    $run = runXml(styledDeck($deck, $directory), 'Bakery');

    expect($run)->toMatch('#\bb="1"#')
        ->and($run)->toMatch('#\bi="1"#')
        ->and($run)->toMatch('#\bu="sng"#')
        ->and($run)->toMatch('#\bstrike="sngStrike"#')
        ->and($run)->toMatch('#<a:latin typeface="Inter"#');
});

it('refuses a bare attribute that is not a flag, because it has no value in it', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{size}');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'A bare attribute asks for the attribute rather than for its value');
});

it('takes the colour of the box where a style names none of its own', function () {
    [$deck, $directory] = wordmark("brand:\n  bold: true\n", ':brand[Bakery]');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience ' => ['colour' => 'FFFFFF', 'size' => 11],
        'Bakery' => ['colour' => 'FFFFFF', 'size' => 11],
    ]);
});

it('takes the colour of the box where a style names no colour of its own', function () {
    // A style that says "no colour of my own" is a style that says nothing, and a word in
    // it is the word beside it: one run rather than two.
    [$deck, $directory] = wordmark("brand:\n  color: none\n", ':brand[Bakery]');

    expect(runColours(styledDeck($deck, $directory)))->toBe([
        'Resilience Bakery' => ['colour' => 'FFFFFF', 'size' => 11],
    ]);
});

it('draws a word in the face a style names', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n  font: Inter\n", ':brand[Bakery]');

    expect(runXml(styledDeck($deck, $directory), 'Bakery'))->toMatch('#<a:latin typeface="Inter"#');
});

it('measures a placed box in the face it is drawn in', function () {
    // A box with a `font-family` of its own is drawn in it, and a box measured in another
    // face is measured for type it does not draw: the words wrap where the other face
    // wraps them, and the box comes out the wrong height for what is in it.
    $directory = textStyleTheme(null);
    $words = str_repeat('Resilience Bakery ', 12);

    $wanted = function (string $face) use ($directory, $words): int {
        $deck = sprintf(
            "---\ntheme: ./theme.yml\n---\n\n:::content{left=10%% top=10%% width=260px height=120px font-size=18 font-family=%s}\n%s\n:::\n",
            $face,
            $words,
        );

        $renderer = new PresentationRenderer($directory);
        $renderer->render((new DeckParser())->parse($deck), Scratch::file('deck.pptx', ''));

        return $renderer->overflows()[0]['wanted'] ?? 0;
    };

    // Calibri is the theme's body face and Arial Narrow is narrower, so the same words
    // wrap into fewer lines in it and the box is shorter.
    expect($wanted('"Arial Narrow"'))->toBeLessThan($wanted('Calibri'));
});

it('measures a word in the face a style names', function () {
    // The face a word is drawn in is the face it is measured in, for the same reason the
    // box's own face is: a word measured in another face wraps where the other face wraps
    // it, and the box comes out the wrong height for what is in it.
    $directory = textStyleTheme("brand:\n  font: \"Arial Narrow\"\n");
    $word = str_repeat('Bakery', 20);

    $wanted = function (string $word) use ($directory): int {
        $deck = sprintf(
            "---\ntheme: ./theme.yml\n---\n\n:::content{left=10%% top=10%% width=260px height=120px font-size=18}\n%s\n:::\n",
            $word,
        );

        $renderer = new PresentationRenderer($directory);
        $renderer->render((new DeckParser())->parse($deck), Scratch::file('deck.pptx', ''));

        return $renderer->overflows()[0]['wanted'] ?? 0;
    };

    // Arial Narrow is narrower than the theme's body face, so the same word takes fewer
    // lines in it and the box is shorter.
    expect($wanted(':brand[' . $word . ']'))->toBeLessThan($wanted($word));
});

it('reports a face a text style names that there is no table for', function () {
    // A text style's `font` is a face the theme names, and a face with no table of its own
    // is measured with the reference face's widths — which are not that face's. The report
    // is where that is said, and it names the style, because the face alone does not say
    // where to change it.
    [$deck, $directory] = wordmark("brand:\n  font: \"Comic Sans MS\"\n", ':brand[Bakery]');

    expect(styledRenderer($deck, $directory)->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => 'the text style "brand"'],
    ]);
});

it('reports a face a placed box names that there is no table for', function () {
    // A box with a `font-family` of its own is drawn in it, and the face is in the deck
    // rather than in the theme — so it is asked about once the deck has been read, and the
    // report names the box and the slide it is on.
    $directory = textStyleTheme(null);

    expect(styledRenderer(wordmarkIn('"Comic Sans MS"'), $directory)->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => 'the "content" box on slide 1'],
    ]);
});

it('reports a face two boxes of the same kind name once', function () {
    // Two boxes of the same kind on one slide are two boxes and one place — the place being
    // where to go and change it — so the report names the face once rather than twice.
    $directory = textStyleTheme(null);

    $deck = sprintf(
        "---\ntheme: ./theme.yml\n---\n\n:::content{left=10%% top=10%% width=30%% height=10%% font-size=11 font-family=\"Comic Sans MS\"}\nOne\n:::\n\n:::content{left=50%% top=10%% width=30%% height=10%% font-size=11 font-family=\"Comic Sans MS\"}\nTwo\n:::\n",
    );

    expect(styledRenderer($deck, $directory)->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => 'the "content" box on slide 1'],
    ]);
});

it('reports no face for a text style and a box that name faces there are tables for', function () {
    // A face there is a table for is not a finding, and neither is one the theme names by
    // its own slot: a style that says `font: heading` is the theme's heading face, which the
    // theme has already said.
    [$deck, $directory] = wordmark("brand:\n  font: Inter\n", ':brand[Bakery]');

    expect(styledRenderer($deck, $directory)->unknownFonts())->toBe([])
        ->and(styledRenderer(wordmarkIn('Inter'), $directory)->unknownFonts())->toBe([])
        ->and(styledRenderer(wordmarkIn('heading'), $directory)->unknownFonts())->toBe([]);
});

it('refuses a deck whose text style names a face it cannot measure when the renderer is strict', function () {
    // Strict is for a caller that would rather have no file than a file whose text was
    // measured with another face's widths, and the message says where the face was named.
    [$deck, $directory] = wordmark("brand:\n  font: \"Comic Sans MS\"\n", ':brand[Bakery]');

    expect(fn () => styledRenderer($deck, $directory, strict: true))
        ->toThrow(UnknownFont::class, 'The face "Comic Sans MS" has no table of advance widths')
        ->toThrow(UnknownFont::class, 'It is named by the text style "brand"');
});

it('refuses a deck whose placed box names a face it cannot measure when the renderer is strict', function () {
    $directory = textStyleTheme(null);

    expect(fn () => styledRenderer(wordmarkIn('"Comic Sans MS"'), $directory, strict: true))
        ->toThrow(UnknownFont::class, 'The face "Comic Sans MS" has no table of advance widths')
        ->toThrow(UnknownFont::class, 'It is named by the "content" box on slide 1');
});

it('leaves a word unbold where a style says it is not bold', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{bold=false}');

    $run = runXml(styledDeck($deck, $directory), 'Bakery');

    expect($run)->not->toMatch('#\bb="1"#')
        ->and($run)->toMatch('#<a:srgbClr val="00B4D8"#');
});

it('leaves a word to the weight of its block where a style says it carries none of its own', function () {
    // `false` is not "off": it is "not set on the run", so the run takes the weight of the
    // block it is in — which is how the Word half reads it too, because a run cannot be
    // written with a weight of none. A heading is bold, so the word is bold.
    $directory = textStyleTheme("brand:\n  color: accent1\n  bold: true\n");

    $deck = sprintf(
        "---\ntheme: ./theme.yml\ntitle: A slide\n---\n\n:::content{%s}\n## A :brand[heading]{bold=false}\n:::\n",
        WORDMARK_BOX,
    );

    $run = runXml(styledDeck($deck, $directory), 'heading');

    expect($run)->toMatch('#\bb="1"#')
        ->and($run)->toMatch('#<a:srgbClr val="00B4D8"#');
});

it('refuses a colour that is not a colour', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{color=chartreuse}');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'A colour is either no colour at all (none or transparent), a role (page, ink, subtle, panel)');
});

it('refuses a size that is not a size', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{size=big}');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'A size of type is a number of points');
});

it('refuses a size below half a point, because it is a box with nothing in it', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{size=0.2}');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'A size of type is at least half a point');
});

it('refuses a font that is not a name', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{font=""}');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'A font is a family name');
});

it('refuses a flag that is neither set nor not set', function () {
    [$deck, $directory] = wordmark("brand:\n  color: accent1\n", ':brand[Bakery]{bold=maybe}');

    expect(fn () => styledDeck($deck, $directory))
        ->toThrow(MalformedTextStyle::class, 'A flag is true or false');
});

it('draws the four kinds of block a box holds, and a style in each of them', function () {
    // A heading, a paragraph, a quote and a list are the four things a frame holds, and the
    // style is drawn in every one of them.
    $directory = textStyleTheme("brand:\n  color: accent1\n");

    $deck = sprintf(
        "---\ntheme: ./theme.yml\ntitle: A slide\n---\n\n:::content{%s}\n## A :brand[heading]\n\nA :brand[paragraph].\n\n> A :brand[quote].\n\n- A :brand[item]\n:::\n",
        WORDMARK_BOX,
    );

    $pptx = styledDeck($deck, $directory);
    $colours = runColours($pptx);

    expect($pptx->texts(1))->toBe([
        'A slide',
        'A ',
        'heading',
        'A ',
        'paragraph',
        '.',
        'A ',
        'quote',
        '.',
        'A ',
        'item',
    ])
        ->and($colours['heading']['colour'])->toBe('00B4D8')
        ->and($colours['paragraph']['colour'])->toBe('00B4D8')
        ->and($colours['quote']['colour'])->toBe('00B4D8')
        ->and($colours['item']['colour'])->toBe('00B4D8');
});

it('holds a label that is Markdown and draws one that is not', function () {
    // The corpus is the list of labels a text style may be given and what CommonMark
    // makes of each one. A label the directive grammar cannot carry — one with a bracket
    // in it — is left out: the grammar says a label holds no brackets at all.
    //
    // The two markers that differ from CommonMark say which way the halves went, and
    // this half holds what the corpus says it holds: a run of three underscores is
    // emphasis to CommonMark and text here, and a run of one asterisk beside a run of
    // two is text to CommonMark and a construct here.
    $directory = textStyleTheme("brand:\n  color: accent1\n");
    $labels = file(dirname(__DIR__) . '/fixtures/labels.txt', FILE_IGNORE_NEW_LINES) ?: [];
    $wrong = [];
    $held = 0;
    $drawn = 0;
    $diverged = [];

    foreach ($labels as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$marker, $label] = array_pad(explode(' ', $line, 2), 2, '');

        if (str_contains($label, '[') || str_contains($label, ']')) {
            continue;
        }

        $deck = (new DeckParser())->parse(sprintf("---\ntheme: ./theme.yml\n---\n\nA :brand[%s] here.\n", $label));
        $path = Scratch::file('deck.pptx', '');
        $verdict = 'drawn';

        try {
            (new PresentationRenderer($directory))->render($deck, $path);
        } catch (Throwable $failure) {
            $verdict = $failure instanceof MalformedTextStyle ? 'refused' : 'failed';
        }

        $want = in_array($marker, ['markdown', 'text-only'], true) ? 'refused' : 'drawn';

        if ($want !== $verdict) {
            $wrong[] = sprintf('%s: the corpus says %s, this half says %s', $label, $marker, $verdict);
        }

        if (in_array($marker, ['markdown-only', 'text-only'], true)) {
            $diverged[$marker] = true;
        }

        if ($want === 'refused') {
            $held++;

            continue;
        }

        $drawn++;
    }

    expect($wrong)->toBe([])
        ->and($held)->toBeGreaterThan(0)
        ->and($drawn)->toBeGreaterThan(0)
        ->and($diverged)->toHaveKeys(['markdown-only', 'text-only']);
});

it('names the labels the halves differ from CommonMark about, in the code', function () {
    // The docblock has to say where the halves differ from CommonMark, and the labels it
    // names have to be the labels the corpus records: a docblock that names a label the
    // corpus does not, or leaves one out, is a claim nothing checks.
    $doc = (string) file_get_contents(Doctest::root() . '/src/Render/CommonMark/StyleReader.php');
    $labels = file(dirname(__DIR__) . '/fixtures/labels.txt', FILE_IGNORE_NEW_LINES) ?: [];

    foreach ($labels as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$marker, $label] = array_pad(explode(' ', $line, 2), 2, '');

        if (!in_array($marker, ['markdown-only', 'text-only'], true)) {
            continue;
        }

        expect($doc)->toContain($label);
    }

    expect($doc)->toContain('Word half')
        ->and($doc)->toContain('agree with each other');
});
