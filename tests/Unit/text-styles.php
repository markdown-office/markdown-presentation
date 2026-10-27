<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedTextStyle;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\UnknownTextStyle;
use MarkdownPresentation\Render\PresentationRenderer;
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
        ->toThrow(MalformedTextStyle::class, 'A colour is none, page, ink, subtle, panel');
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
