<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Theme\Style;
use MarkdownPresentation\Theme\StyleMapping;
use MarkdownPresentation\Theme\Theme;
use MarkdownPresentation\Theme\ThemeMapping;
use MarkdownPresentation\Theme\ThemeResolver;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

/*
 * The numbers a theme sets, and the difference they make to a rendered slide.
 *
 * A theme that can name colours and fonts alone gives three decks that differ in a swatch and
 * nowhere else, so these check the type scale, the spacing and the heading treatment as well —
 * and, where a claim is about how a slide *looks*, they read the written file rather than the
 * theme's own declaration of what it asked for.
 */

/**
 * A theme with one `style:` key set, and everything else at the library's own numbers.
 *
 * A `colors:` key may be passed alongside, and is passed through to {@see Theme::fromArray}
 * rather than into the `style:` mapping — the two are separate parts of a `theme.yml` and
 * confusing them is the mistake this exists to catch.
 *
 * @param array<string, mixed> $style
 * @param array<string, string> $colors
 */
function styledTheme(array $style, array $colors = []): Theme
{
    return Theme::fromArray(['name' => 'Styled', 'style' => $style, 'colors' => $colors]);
}

/**
 * A deck named `$markdown` written in `$theme`, read back off the file.
 *
 * The theme is written into a scratch directory and the deck names it by the path relative to
 * the repository root, because that is what a theme path resolves against: {@see
 * ThemeResolver} refuses a path outside the roots it was given, and a test that put an
 * absolute path here would be a test that only passes on the machine it was written on.
 */
function themedDeck(Theme $theme, string $markdown = "---\ntheme: %s\n---\n\n# T\n\n## Sub\n\nBody.\n\n***\n"): Pptx
{
    $directory = Scratch::directoryPath('theme');
    file_put_contents($directory . '/theme.yml', (string) json_encode(ThemeMapping::write($theme)));

    // A theme with no `layouts.yml` maps no role at all, and a deck is written in the
    // `content` role whether it says so or not — so the mappings a deck needs are the ones
    // every shipped theme carries, and naming them here keeps what is under test the style.
    file_put_contents($directory . '/layouts.yml', (string) json_encode([
        'layouts' => array_combine(
            LayoutRole::names(),
            array_map(static fn (LayoutRole $role): string => ucwords(str_replace('-', ' ', $role->value)), LayoutRole::cases()),
        ),
    ]));

    $path = Scratch::file('style.pptx', '');

    (new PresentationRenderer())->render((new DeckParser())->parse(sprintf($markdown, relativePath($directory))), $path);

    return Pptx::open($path);
}

/**
 * A scratch directory as a deck may name it: relative to the repository root, which is one
 * of the roots {@see ThemeResolver} resolves against.
 */
function relativePath(string $directory): string
{
    return ltrim(str_replace(dirname(__DIR__, 2) . '/', '', $directory), '/');
}

it('gives a theme that names no style the library\'s own numbers', function () {
    // The reason the defaults are the numbers they are: a deck written before a theme could
    // say otherwise has to render the same way afterwards, or choosing a theme would resize
    // every deck that shipped one.
    expect(StyleMapping::write(styledTheme([])->style()))->toBe(StyleMapping::write(Style::defaults()))
        ->and(Style::defaults()->margin())->toBe(SlideGeometry::MARGIN)
        ->and(Style::defaults()->body())->toBe(SlideGeometry::BODY_SIZE);
});

it('takes one style key and leaves the rest at the library\'s numbers', function () {
    // A theme naming a wider margin and nothing else is a theme that works: the type scale
    // stays where it was and only the spacing moves.
    $style = styledTheme(['margin' => 64])->style();

    expect($style->margin())->toBe(64)
        ->and($style->gutter())->toBe(Style::defaults()->gutter())
        ->and($style->body())->toBe(Style::defaults()->body());
});

it('refuses a style key that is not one, and names the ones there are', function () {
    expect(fn () => styledTheme(['serif' => true]))
        ->toThrow(MalformedTheme::class, '"serif" is not a key of a theme. Valid keys: margin, gutter, block, rule, body, title, cover, section, headings, leading, heading-weight, heading-ink.');
});

it('refuses a style that is not a mapping', function () {
    expect(fn () => Theme::fromArray(['name' => 'Listed', 'style' => [12, 14]], 'theme.yml'))
        ->toThrow(MalformedTheme::class, '"style" is not a mapping of style names to values');
});

it('refuses a measurement that is not a whole number of pixels, and says what it wanted', function () {
    expect(fn () => styledTheme(['margin' => 'sixty']))
        ->toThrow(MalformedTheme::class, '"margin" is not a whole number of pixels')
        // Zero is a number, and a box of nothing is not a layout: a margin of zero puts the
        // type against the edge of the page, and a gap of zero puts two blocks on each other.
        ->and(fn () => styledTheme(['gutter' => 0]))
        ->toThrow(MalformedTheme::class, '"gutter" is not a number of pixels of at least 1');
});

it('refuses a type size that is not a whole number of points', function () {
    expect(fn () => styledTheme(['title' => 28.5]))
        ->toThrow(MalformedTheme::class, '"title" is not a whole number of points, at least 1')
        ->and(fn () => styledTheme(['body' => 0]))
        ->toThrow(MalformedTheme::class, '"body" is not a whole number of points, at least 1');
});

it('refuses a heading scale that is not a list of point sizes', function () {
    expect(fn () => styledTheme(['headings' => 20]))
        ->toThrow(MalformedTheme::class, '"headings" is not a list of type sizes in points, shallowest heading first')
        // Empty is not a scale: a heading with no size to be drawn at has none.
        ->and(fn () => styledTheme(['headings' => []]))
        ->toThrow(MalformedTheme::class, '"headings" is not a list of type sizes in points, shallowest heading first')
        // A list of the wrong things is the same mistake as a list of nothing, and says so
        // with the same message: the list was read and what is in it is not a size.
        ->and(fn () => styledTheme(['headings' => ['22', 20]]))
        ->toThrow(MalformedTheme::class, '"headings" is not a list of whole point sizes, each at least 1');
});

it('takes a heading scale of one, so a theme can make its headings flat', function () {
    // Writing the same number five times is the other way to say this, and a list of one says
    // it once.
    expect(styledTheme(['headings' => [20]])->style()->headings())->toBe([20]);
});

it('refuses leading that is not a number, or is tighter than the type itself', function () {
    expect(fn () => styledTheme(['leading' => 'loose']))
        ->toThrow(MalformedTheme::class, '"leading" is not a number')
        // Below 1.0 lines overlap, and a shape written at a negative line height is a shape
        // PowerPoint will not open.
        ->and(fn () => styledTheme(['leading' => 0.8]))
        ->toThrow(MalformedTheme::class, '"leading" is not a number of at least 1');
});

it('refuses a heading treatment that is not one of the two it has', function () {
    expect(fn () => styledTheme(['heading-weight' => 'heavy']))
        ->toThrow(MalformedTheme::class, '"heading-weight" is not one of: regular, bold')
        ->and(fn () => styledTheme(['heading-ink' => 'red']))
        ->toThrow(MalformedTheme::class, '"heading-ink" is not one of: ink, accent');
});

it('reads the heading treatment as the two questions a renderer asks of it', function () {
    $regular = styledTheme(['heading-weight' => 'regular'])->style();
    $accent = styledTheme(['heading-ink' => 'accent'])->style();

    expect($regular->headingIsBold())->toBeFalse()
        ->and($regular->headingUsesAccent())->toBeFalse()
        ->and($accent->headingIsBold())->toBeTrue()
        ->and($accent->headingUsesAccent())->toBeTrue();
});

it('writes the style back the way a theme.yml writes it', function () {
    // The round trip is what lets a theme be written once and read back, which is how the
    // examples' three copies of one deck stay comparable.
    $written = StyleMapping::write(styledTheme(['margin' => 48, 'heading-ink' => 'accent', 'heading-weight' => 'regular'])->style());

    expect($written['margin'])->toBe(48)
        ->and($written['heading-ink'])->toBe('accent')
        ->and($written['heading-weight'])->toBe('regular');
});

it('takes a heading treatment as the two questions rather than as the file\'s words', function () {
    // A word a caller invents — `headingWeight: 'heavy'` — is a value no file can express, and
    // writing one back turns it quietly into one that can: a style that reports a regular
    // heading and writes `regular` are not the same answer the caller gave. Two booleans
    // cannot be spelled wrongly.
    $style = new Style(headingIsBold: false, headingUsesAccent: true);
    $written = StyleMapping::write($style);

    expect($style->headingIsBold())->toBeFalse()
        ->and($style->headingUsesAccent())->toBeTrue()
        ->and($written['heading-weight'])->toBe('regular')
        ->and($written['heading-ink'])->toBe('accent');
});

it('writes every key a style: mapping may carry, in the order the keys name them', function () {
    // `KEYS` is what a failure offers as the ones that are, so a style written in another order
    // is a `theme.yml` that reads back as something else than the one it was written from.
    $written = StyleMapping::write(StyleMapping::read(['margin' => 64]));

    expect(array_keys($written))->toBe(StyleMapping::KEYS)
        ->and($written['margin'])->toBe(64);
});

it('writes back the mapping it read, whatever a file named and whatever it left out', function () {
    $all = StyleMapping::write(StyleMapping::read([]));

    foreach ([['margin', 64], ['rule', 6], ['leading', 1.4], ['headings', [30, 20, 16]]] as [$key, $value]) {
        expect(StyleMapping::write(StyleMapping::read([$key => $value])))->toBe(array_merge($all, [$key => $value]), $key);
    }

    // All four of the heading treatments, because these two are the only keys whose value
    // changes shape on the way through: a word in the file and two booleans on a style.
    foreach (StyleMapping::WEIGHTS as $weight) {
        foreach (StyleMapping::INKS as $ink) {
            $named = ['heading-weight' => $weight, 'heading-ink' => $ink];

            expect(StyleMapping::write(StyleMapping::read($named)))->toBe(array_merge($all, $named), $weight . '/' . $ink);
        }
    }
});

it('draws a title at the size its theme names', function () {
    // The claim a type scale makes: the number in the file is the number on the slide. Read
    // off the written shape, because reading it back off the theme proves only that the theme
    // said what it said.
    $pptx = themedDeck(styledTheme(['title' => 36, 'body' => 20]));

    expect($pptx->fontSize('title'))->toBe(36.0);
});

it('draws a heading at the depth its theme\'s scale names', function () {
    // A scale that is nearly flat makes a `##` and a `###` differ by a point and a half; a
    // stepped one separates them by four. That is the difference between two themes, not a
    // difference one theme makes with itself.
    $flat = themedDeck(styledTheme(['headings' => [22, 20, 19, 18, 17]]))->fontSize('text');
    $stepped = themedDeck(styledTheme(['headings' => [26, 22, 18, 14, 10]]))->fontSize('text');

    expect($flat)->toBe(20.0)
        ->and($stepped)->toBe(22.0);
});

it('draws a body paragraph at the size its theme names', function () {
    // A deck of a body paragraph and nothing else, because a heading in the same frame is
    // written before it and `fontSize()` reports the first run of the shape.
    expect(themedDeck(styledTheme(['body' => 19]), "---\ntheme: %s\n---\n\n# T\n\nBody.\n")->fontSize('text'))
        ->toBe(19.0);
});

it('moves the body of a slide when the theme widens its margin', function () {
    // The spacing rhythm: a wider margin is more air on every side, and the body follows the
    // margin rather than staying where it was.
    $narrow = themedDeck(styledTheme(['margin' => 30]))->positionOf('text');
    $wide = themedDeck(styledTheme(['margin' => 70]))->positionOf('text');

    expect($narrow['x'])->toBeLessThan($wide['x'])
        ->and($wide['y'])->toBeGreaterThan($narrow['y'])
        // And the box is correspondingly narrower, or a wider margin would buy nothing.
        ->and($wide['width'])->toBeLessThan($narrow['width']);
});

it('leaves the theme\'s own gap between two shapes of a column', function () {
    // The gap is between the shapes rather than in the box they are laid out in, so this is
    // read off two shapes on one slide: a frame of text, then a code block, with the theme's
    // spacing between the end of the first and the top of the second.
    $deck = "---\ntheme: %s\n---\n\n# T\n\nOne.\n\nTwo.\n\n```\nx\n```\n";

    foreach (['tight' => 8, 'loose' => 24] as $name => $block) {
        $shapes = themedDeck(styledTheme(['block' => $block]), $deck)->positions();
        $between = $shapes[2]['y'] - ($shapes[1]['y'] + $shapes[1]['height']);

        // A paragraph's half of `block:` and a code block's, which is more of it: the panel
        // behind a code block is painted to the edge of its box and the text above it is not.
        expect(round($between))->toBe(round($block * 1.4), $name);
    }
});

it('leaves the theme\'s own gap between two paragraphs of one frame', function () {
    // The same number, spent in the other of its two places: `block:` is the air between two
    // paragraphs as well as the air between two shapes, and a theme that sets it is setting
    // both.
    $deck = "---\ntheme: %s\n---\n\n# T\n\nOne.\n\nTwo.\n";

    foreach (['tight' => 8, 'loose' => 24] as $name => $block) {
        $xml = themedDeck(styledTheme(['block' => $block]), $deck)->part(SLIDE_ONE);
        // `a:spcPts` is a measure in hundredths of a point, a pixel is three quarters of one,
        // and the gap is spent a half at each end of it: 8px of block gap is 300 there and
        // 24px is 900.
        preg_match_all('#<a:spcAft>\s*<a:spcPts val="(\d+)"/>#', $xml, $found);

        expect(in_array((string) (int) round($block * 37.5), $found[1], true))->toBeTrue($name);
    }
});

it('draws a rule at the weight its theme names', function () {
    // A hairline under a serif deck and a heavy one under a corporate one are the same shape
    // at two weights, and the bar in the file is what tells them apart.
    expect(themedDeck(styledTheme(['rule' => 6]))->positionOf('rule')['height'])->toBe(6.0)
        ->and(themedDeck(styledTheme(['rule' => 1]))->positionOf('rule')['height'])->toBe(1.0);
});

it('draws a heading in its face\'s own weight when the theme says so', function () {
    // A serif deck with a large title wants the reading weight of the face; a sans deck with
    // a heavy title does not. `b="1"` is the bold the writer emits.
    $regular = themedDeck(styledTheme(['heading-weight' => 'regular']))->part(SLIDE_ONE);
    $bold = themedDeck(styledTheme(['heading-weight' => 'bold']))->part(SLIDE_ONE);

    expect(headingIsBold($regular))->toBeFalse()
        ->and(headingIsBold($bold))->toBeTrue();
});

it('measures a heading in the weight it is drawn in', function () {
    // The other half of the one above: a heading in a theme that says `regular` is drawn in
    // the face's reading weight, so measuring it in the bold cut is measuring a face that is
    // not there — and a heading measured a line too tall is a gap under it.
    //
    // 60 `h` at 20pt are 851px in Calibri's regular cut and 870px in its bold one — 20pt is
    // 27px, and a width is measured in pixels because a box is — and the body box leaves
    // 860px for the text, so the first takes one line and the second two.
    $heading = "---\ntheme: %s\n---\n\n# T\n\nBody.\n\n## " . str_repeat('h', 60) . "\n";
    $regular = themedDeck(styledTheme(['heading-weight' => 'regular']), $heading);
    $bold = themedDeck(styledTheme(['heading-weight' => 'bold']), $heading);

    expect($regular->positionOf('text')['height'])->toBeLessThan($bold->positionOf('text')['height']);
});

it('draws a heading in the accent rather than the body ink when the theme says so', function () {
    // An accent a body could never be painted in, so the two are told apart by the colour
    // rather than by which shape it happened to land on.
    $accent = styledTheme(['heading-ink' => 'accent'], ['accent1' => '#C00000']);
    $ink = headingShape(themedDeck(styledTheme(['heading-ink' => 'ink']))->part(SLIDE_ONE));

    expect(headingColour(themedDeck($accent)))->toBe('C00000')
        ->and(headingColourIn($ink ?? ''))->not->toBe('C00000');
});

it('leaves a title in the body ink whatever a heading says of itself', function () {
    // `heading-ink` is about a heading in a body. A title is the deck's own heading and is
    // painted with the ink the rest of the slide is, or a slide whose title is a different
    // colour from its own text reads as two decks.
    $theme = styledTheme(['heading-ink' => 'accent'], ['accent1' => '#C00000']);
    $pptx = themedDeck($theme);
    preg_match('#<p:sp>(?:(?!</p:sp>).)*name="title".*?</p:sp>#s', $pptx->part(SLIDE_ONE), $shape);

    expect(headingColourIn($shape[0] ?? ''))->toBe('000000');
});

it('keeps the three shipped themes apart in every axis a theme decides', function () {
    // The claim `03-themes.md` makes is that the three are distinguishable. A set of themes
    // that agreed on two of these would render as the same deck with a different swatch, so
    // the axes are compared rather than the file contents.
    $resolver = new ThemeResolver(dirname(__DIR__, 2));
    $themes = ['default', 'minimal', 'corporate-dark'];

    foreach (['margin' => 'margin', 'body' => 'body', 'title' => 'title', 'rule' => 'ruleWeight', 'gutter' => 'gutter'] as $key => $method) {
        $values = array_map(
            static fn (string $name): int => $resolver->resolve($name)->theme()->style()->{$method}(),
            $themes,
        );

        expect(array_unique($values))->toHaveCount(count($themes), $key . ' is the same in all three');
    }

    $leading = array_map(
        static fn (string $name): float => $resolver->resolve($name)->theme()->style()->leading(),
        $themes,
    );
    $weights = array_map(
        static fn (string $name): bool => $resolver->resolve($name)->theme()->style()->headingIsBold(),
        $themes,
    );

    expect(array_unique($leading))->toHaveCount(count($themes))
        ->and(array_unique($weights))->toHaveCount(2);
});

/** Whether the `## Sub` shape on slide one is written in a bold weight. */
function headingIsBold(string $slide): bool
{
    return headingShape($slide) !== null && str_contains(headingShape($slide) ?? '', 'b="1"');
}

/** The first colour written on the `## Sub` shape on slide one. */
function headingColour(Pptx $pptx): ?string
{
    $shape = headingShape($pptx->part(SLIDE_ONE));

    return $shape === null ? null : headingColourIn($shape);
}

/** The first colour written anywhere in `$shape`. */
function headingColourIn(string $shape): ?string
{
    preg_match('/srgbClr val="([0-9A-Fa-f]{6})"/', $shape, $colour);

    return $colour[1] ?? null;
}

/**
 * The `## Sub` on slide one, which is the first paragraph of the frame the body is drawn into.
 *
 * A heading is a paragraph of that frame rather than a shape of its own, so it is found by
 * position within the shape — and it is the first paragraph because the deck wrote it before
 * the body paragraph. The shape is found by name because the shape tree's own group carries
 * a `<p:nvPr/>` too and the first shape in the file belongs to no shape.
 */
function headingShape(string $slide): ?string
{
    foreach (explode('</p:sp>', $slide) as $chunk) {
        if (preg_match('/name="text"/', $chunk) !== 1) {
            continue;
        }

        preg_match('#<a:p>.*?</a:p>#s', $chunk, $paragraph);

        return $paragraph[0] ?? null;
    }

    return null;
}
