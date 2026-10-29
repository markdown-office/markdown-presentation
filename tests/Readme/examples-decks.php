<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SvgRasteriser;
use MarkdownPresentation\Tests\Support\Pptx;

/*
 * Every claim examples/README.md makes about the decks it lists, checked against a real
 * conversion.
 *
 * The page is a gallery, and a gallery drifts: a deck is renamed, a feature is fixed and a
 * line of prose keeps describing what the file used to do. Reading the markup is not the
 * evidence either — it has already been caught concluding a style survived when the reader
 * showed otherwise. So each claim below is answered by building the deck and asking the
 * `.pptx`.
 *
 * The PNG previews are not asserted on: they need LibreOffice and `pymupdf`, which CI does
 * not have, and a test that skips is a test that proves nothing. What they are for is a
 * person looking at them.
 *
 * The one skip here is `builtDeck()` declining to convert a deck carrying an SVG on a build
 * with no working rasteriser. It is not a claim standing in for another: the library refuses
 * that picture in such a build, so there is no deck on disk to ask a question of — and the
 * reason the skip prints names the capability that is missing, rather than leaving a green run
 * that reads as though the deck had been converted.
*/

/**
 * Builds a deck source and hands back the `.pptx` to inspect.
 *
 * @return array{0: Pptx, 1: string}
 */
function builtDeck(string $name): array
{
    $root = dirname(__DIR__, 2);
    $source = (string) file_get_contents($root . '/examples/markdown/' . $name);

    // A deck carrying an SVG cannot be converted by a build that cannot draw one, and that is
    // the library's answer rather than a reason to change the deck into something easier. So
    // the conversion is skipped and the reason names the capability that is missing: the claim
    // these tests make is about the deck, and a build with no rasteriser cannot put the deck on
    // disk to be asked about.
    if (str_contains($source, '.svg)') && !(new SvgRasteriser())->available()) {
        test()->markTestSkipped(sprintf(
            'This build cannot rasterise an SVG, and %s carries one to be drawn.',
            $name,
        ));
    }

    // The hash is a filename, not a digest: it tells one deck's render apart from another's
    // in the system temporary directory, and nothing reads it back to decide anything. So it
    // is xxh3 rather than md5, which was here doing the same job while claiming to be
    // something it was not.
    $path = sys_get_temp_dir() . '/mdp-example-' . hash('xxh3', $name) . '.pptx';

    // The renderer is handed the directory the deck is in, because that is what a relative
    // image path in it resolves against.
    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse($source),
        $path,
    );

    return [Pptx::open($path), $source];
}

/**
 * The runs of a shape on a slide, with the colour, the size and the weight of each.
 *
 * Read off the file rather than off the writer, because the claim is about what a reader
 * sees: the colour of a word and the size of a word are two facts about the run, and the
 * run is what the file holds.
 *
 * Named and numbered rather than named alone, because a deck may write two shapes of one
 * kind — the wordmark and the prose under it are both `content` — and asking about "the"
 * shape of that name would answer about the first of them.
 *
 * @return list<array{text: string, colour: ?string, size: ?int, bold: bool}>
 */
function runsOfShape(Pptx $pptx, string $name, int $slide = 1, int $index = 0): array
{
    $shapes = array_values(array_filter(
        preg_split('#(?=<p:sp>|<p:graphicFrame>|<p:pic>)#', $pptx->part("ppt/slides/slide{$slide}.xml")) ?: [],
        static fn (string $chunk): bool => preg_match(
            '#<p:cNvPr id="\d+" name="' . preg_quote($name, '#') . '"#',
            $chunk,
        ) === 1,
    ));

    $runs = [];

    foreach (preg_split('#(?=<a:r>)#', $shapes[$index] ?? '') ?: [] as $chunk) {
        if (preg_match('#<a:t>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</a:t>#s', $chunk, $text) !== 1) {
            continue;
        }

        preg_match('#<a:srgbClr val="([0-9A-Fa-f]{6})"#', $chunk, $colour);
        preg_match('#<a:rPr[^>]*\ssz="(\d+)"#', $chunk, $size);
        preg_match('#<a:rPr[^>]*\bb="1"#', $chunk, $bold);

        $runs[] = [
            'text' => $text[1],
            'colour' => $colour[1] ?? null,
            'size' => isset($size[1]) ? (int) $size[1] / 100 : null,
            'bold' => ($bold[0] ?? '') !== '',
        ];
    }

    return $runs;
}

/** The deck that carries the three picture roles, and the layout part its full-bleed one is called. */
const PICTURE_DECK = '07-images.md';
const FULL_BLEED_LAYOUT = 'Full Bleed Picture';

/**
 * The deck four of the tests below convert, which is the one carrying every block kind.
 *
 * Named because it is the deck those four assertions are about — unclosed emphasis, list
 * nesting, an ordered list's start, a title with markup — rather than a fixture that happens
 * to be convenient. Renaming the file would otherwise break four tests with a message about
 * a missing deck instead of one about the gallery.
 */
const KITCHEN_SINK = '01-kitchen-sink.md';

$root = dirname(__DIR__, 2);
$readme = (string) file_get_contents($root . '/examples/README.md');

it('lists every deck that is on disk', function () use ($readme, $root) {
    // The table names the sources; the directory holds them. A deck added without a row
    // is a deck nobody is told about, and a row without a deck is a lie.
    $listed = [];
    preg_match_all('/`(\d{2}-[a-z-]+)\.md`/', $readme, $matches);

    $listed = array_map(
        static fn (string $stem): string => $stem . '.md',
        $matches[1],
    );

    // `10-rejected.md` is named in the table and again in the prose below it, so the
    // mentions are deduplicated before being compared with the directory.
    $listed = array_values(array_unique($listed));
    $onDisk = array_map(
        static fn (string $path): string => basename($path),
        glob($root . '/examples/markdown/*.md') ?: [],
    );
    sort($listed);
    sort($onDisk);

    expect($listed)->toBe($onDisk)
        ->and($listed)->not->toBe([]);
});

it('builds every deck the page says it builds', function () use ($readme, $root) {
    $build = (string) file_get_contents($root . '/examples/build.php');
    preg_match_all('/`(\d{2}-[a-z-]+)\.md`\s*\|\s*\*\*nothing\*\*/', $readme, $declined);

    // A page that says a deck is not built has to be right about it: the reason has to be
    // in `build.php`, keyed by the stem, or the deck stops being built for a reason nobody
    // wrote down.
    expect($declined[1])->not->toBe([]);

    foreach ($declined[1] as $name) {
        expect($build)->toContain("'" . $name . "' => '");
    }
});

it('converts each deck that is not the rejected one', function (string $name) {
    [$pptx] = builtDeck($name);

    expect($pptx->slideCount())->toBeGreaterThan(0)
        ->and($pptx->slideText(1))->not->toBe('');
})->with([
    KITCHEN_SINK,
    '02-layout-roles.md',
    '04-frontmatter.md',
    '05-steps.md',
    '06-tables.md',
    PICTURE_DECK,
    '08-code.md',
    '09-inline-markup.md',
    '11-type-scale.md',
    '12-free-shapes.md',
    '13-placed-deck.md',
    '14-shadows.md',
    '15-inline-styles.md',
    '16-templates.md',
    '17-corporate-template.md',
]);

it('refuses the deck whose conversion is the failure it exists to demonstrate', function () use ($root) {
    $source = (string) file_get_contents($root . '/examples/markdown/10-rejected.md');

    // Parsing succeeds — directives are checked at render, not at parse — and rendering is
    // where the deck is refused.
    expect(function () use ($root, $source): void {
        (new PresentationRenderer($root . '/examples/markdown'))->render(
            (new DeckParser())->parse($source),
            sys_get_temp_dir() . '/mdp-rejected.pptx',
        );
    })->toThrow(UnknownDirective::class);
});

it('leaves emphasis that does not close exactly as it was written', function () {
    [$pptx] = builtDeck(KITCHEN_SINK);

    // Refusing a deck over a stray asterisk is a deck nobody can show, so the unclosed
    // forms are text.
    expect($pptx->slideText(1))->toContain('2 * 3')
        ->and($pptx->slideText(1))->toContain('**unclosed')
        ->and($pptx->slideText(1))->toContain('snake_case_name');
});

it('nests four spaces of indent one level and not two', function () {
    [$pptx] = builtDeck(KITCHEN_SINK);

    // A list is paragraphs of a text frame and its indentation lives on those paragraphs, so
    // the left edges of the shapes say nothing. What matters is how many indent steps the
    // bulleted paragraphs have: reading four spaces as two levels would add a step.
    $levels = [];
    $shape = '';

    foreach (explode('</p:sp>', $pptx->part('ppt/slides/slide2.xml')) as $chunk) {
        if (preg_match('/name="([^"]+)"/', $chunk, $name) === 1) {
            $shape = $name[1];
        }

        if ($shape !== 'text') {
            continue;
        }

        preg_match_all('/marL="(\d+)"/', $chunk, $matches);

        foreach ($matches[1] as $indent) {
            $levels[(int) $indent] = true;
        }
    }

    $steps = array_keys($levels);
    sort($steps);

    // The bullets and their children, one step apart. A third level, or a second step
    // twice as wide, is four spaces read as two levels.
    expect($steps)->toBe([190500, 381000]);
});

it('starts an ordered list at the number the deck wrote', function () {
    [$pptx] = builtDeck(KITCHEN_SINK);
    $slide = 2;

    expect($pptx->part('ppt/slides/slide' . $slide . '.xml'))->toContain('startAt="7"');
});

it('gives the title that carries markup once, with the markup', function () {
    [$pptx] = builtDeck('09-inline-markup.md');

    // `title: "Ops **runbook**: what *changed*"` is drawn with the emphasis, and the
    // heading that repeats it is not drawn a second time.
    expect($pptx->slideText(1))->toContain('Ops')
        ->and($pptx->slideText(1))->not->toContain('**runbook**')
        ->and($pptx->slideText(1))->not->toContain('*changed*');
});

it('draws a frontmatter title on a blank layout, which has no title box', function () {
    [$pptx] = builtDeck('02-layout-roles.md');

    // The blank slide is the last of the five roles.
    $last = $pptx->slideCount();

    expect($pptx->slideText($last))->toContain('A blank slide');
});

it('binds each picture layout to the layout part its theme names for it', function () {
    [$pptx] = builtDeck(PICTURE_DECK);

    // A role is portable and the layout part is not: the deck wrote `image-text` and the
    // file carries a part called `Picture Left`, which is what `layouts.yml` is for.
    expect($pptx->layoutNames())->toContain('Picture Left', 'Picture with Caption', FULL_BLEED_LAYOUT);
});

it('gives the picture layouts placeholders rather than free-floating boxes', function () {
    [$pptx] = builtDeck(PICTURE_DECK);

    // The claim that makes them roles rather than drawing: each layout part carries the
    // placeholders for the boxes the slides are drawn in, and *Reset* restores them.
    $picture = array_search('Picture Left', $pptx->layoutNames(), true);
    $full = array_search(FULL_BLEED_LAYOUT, $pptx->layoutNames(), true);

    expect($picture)->not->toBeFalse()
        ->and(array_keys($pptx->layoutPlaceholders((int) $picture)))->toBe(['title', 'body 1', 'body 2'])
        ->and(array_keys($pptx->layoutPlaceholders((int) $full)))->toBe(['body 1']);
});

it('leaves an image slide with no title placeholder to inherit', function () {
    [$pptx] = builtDeck(PICTURE_DECK);

    // A picture alone, which is what the role is: a template whose own layout carries a
    // title placeholder does not turn it into a role that has one.
    $full = array_search(FULL_BLEED_LAYOUT, $pptx->layoutNames(), true);

    expect($full)->not->toBeFalse();

    foreach (range(1, $pptx->slideCount()) as $number) {
        if ($pptx->layoutOf($number) !== (string) $full) {
            continue;
        }

        expect($pptx->shapeNames($number))->toBe(['image'])
            ->and($pptx->placeholderOf('title', $number))->toBeNull();

        return;
    }

    $this->fail(sprintf('No slide is bound to the "Full Bleed Picture" layout in %s.', '07-images.md'));
});

it('reveals each step on a click of its own', function () {
    [$pptx] = builtDeck('05-steps.md');

    // The first slide builds up in three steps, so it carries two clicks after the first
    // step: a deck that wrote one slide per step would carry three slides here instead.
    expect($pptx->reveals(1))->toHaveCount(2)
        ->and($pptx->reveals(1)[0])->not->toBeEmpty();
});

it('clicks a two-column build in the order the steps were written', function () {
    [$pptx] = builtDeck('05-steps.md');

    // The left column names step three, and the heading that opens the right column is at that
    // step too, so the first click carries a shape from both columns at once. The step written
    // after it comes second, so the chain runs in the order the deck was read rather than the
    // order the two columns are laid out in.
    expect($pptx->reveals(4))->toBe([['text', 'text'], ['text']]);
});

it('holds a picture back until its own click', function () {
    [$pptx] = builtDeck('05-steps.md');

    // A picture is measured into the room it will occupy whether or not it has appeared, so it
    // is the only thing the click that shows it carries. A renderer that wrote a slide per step
    // would put the picture on a slide of its own instead.
    expect($pptx->reveals(6))->toBe([['image']]);
});

it('draws the text after a region in the column a marker inside that region moved it to', function () {
    [$pptx] = builtDeck('05-steps.md');

    // The sentence after the closing `:::` is the whole of this deck's claim about a region and
    // a column on one slide, and the text alone cannot tell where it was drawn: a parser that
    // kept the region to the column it opened in would put the same words at the left column's
    // `x` and the page would read the same.
    $shapes = $pptx->positions(5);

    expect($pptx->slideText(5))->toContain('Right, after the closer')
        ->and($shapes)->toHaveCount(4)
        ->and($shapes[3]['x'])->toBeGreaterThan($shapes[2]['x']);
});

it('lays a two-column build out for a picture the first click has not shown', function () {
    [$pptx] = builtDeck('05-steps.md');

    // The last click is the picture, and it is in the right-hand column. The left column's own
    // steps come before it because the columns are laid out in order, so the picture is measured
    // into a column that has already been filled — which is the case a build-up has to get right
    // and the one a single-column example cannot show.
    expect($pptx->reveals(7))->toBe([['text', 'text'], ['image']]);
});

/**
 * Every shape of a slide, one per element, with the name the file gave it.
 *
 * Split on the opening tags rather than on a closing one, so a `p:pic` is a shape of its own
 * rather than the tail of the `p:sp` before it — which is what made a picture's shadow and its
 * name land in different shapes. The split also matches `<p:spPr>`, so a fragment with no name
 * of its own is reported as null rather than borrowing the next shape's.
 *
 * @return list<array{name: ?string, xml: string}>
 */
function shapeChunks(Pptx $pptx, int $slide = 1): array
{
    $parts = preg_split('#(?=<p:sp>|<p:graphicFrame>|<p:pic>)#', $pptx->part("ppt/slides/slide{$slide}.xml")) ?: [];
    $chunks = [];

    foreach ($parts as $xml) {
        preg_match('/name="([^"]*)"/', $xml, $found);

        $chunks[] = ['name' => $found[1] ?? null, 'xml' => $xml];
    }

    return $chunks;
}

/**
 * The shadow on a shape of the given name, as the four numbers the file carries, or null where
 * no shape of that name casts one.
 *
 * Read off the XML rather than through a support helper, because nothing else in the gallery
 * asks about an effect: a shape's own geometry is a thing `Pptx::positions()` answers, and
 * extending that to carry an `<a:effectLst>` would put a shadow on every deck the support
 * object reads for a reason no other deck has.
 *
 * The first shape **that has one**, not the first shape of that name, because the decks in this
 * gallery give several shapes the same name — `14-shadows.md` has nine shapes called `content`
 * and one of them casts a shadow — and a helper that stopped at the first `content` would
 * answer "no shadow" about a slide whose text block plainly has one.
 *
 * @return array{blur: int, dist: int, dir: int, alpha: int}|null
 */
function shadowOn(Pptx $pptx, string $name, int $slide = 1): ?array
{
    foreach (shapeChunks($pptx, $slide) as $chunk) {
        if ($chunk['name'] !== $name) {
            continue;
        }

        if (preg_match('#<a:outerShdw[^>]*\bblurRad="(\d+)"[^>]*\bdist="(\d+)"[^>]*\bdir="(\d+)"#', $chunk['xml'], $shadow) !== 1) {
            continue;
        }

        // The alpha is the one inside the shadow, which is the first `<a:alpha>` after the
        // `<a:outerShdw>` — a fill's would otherwise answer for it.
        preg_match('#<a:outerShdw.*?<a:alpha val="(\d+)"#s', $chunk['xml'], $alpha);

        return [
            'blur' => (int) $shadow[1],
            'dist' => (int) $shadow[2],
            'dir' => (int) $shadow[3] / 60000,
            'alpha' => (int) ($alpha[1] ?? 100000) / 1000,
        ];
    }

    return null;
}

/** The names of the shapes on a slide that carry a shadow, in the order the file draws them. */
function shadowedNames(Pptx $pptx, int $slide = 1): array
{
    $names = [];

    foreach (shapeChunks($pptx, $slide) as $chunk) {
        if ($chunk['name'] !== null && str_contains($chunk['xml'], '<a:outerShdw ')) {
            $names[] = $chunk['name'];
        }
    }

    return $names;
}

it('casts a different kind of shadow on five different kinds of object', function () {
    [$pptx] = builtDeck('14-shadows.md');

    // The row's claim, read off the file: five ink shapes, each with a shadow, and no two of
    // them the same shape. A deck that gave all five the same shadow would satisfy "five kinds"
    // and show one shadow, which is the claim that has rotted before.
    $shadowed = shadowedNames($pptx);

    expect(array_values(array_filter($shadowed, static fn (string $name): bool => $name !== 'content' && $name !== 'image')))
        ->toBe(['rectangle', 'roundrect', 'circle', 'hexagon', 'diamond']);
});

it('gives one shadow an offset with no blur, and another a blur with no offset', function () {
    [$pptx] = builtDeck('14-shadows.md');

    // "A hard-edged one with no blur at all" and "a glow with no offset" are the two halves of
    // what `<a:outerShdw>` can hold, and a writer that ignored one of them would leave both
    // drawing the same thing while the page said otherwise.
    expect(shadowOn($pptx, 'roundrect'))->toMatchArray(['blur' => 0, 'dist' => 95250])
        ->and(shadowOn($pptx, 'circle'))->toMatchArray(['blur' => 114300, 'dist' => 0])
        // "A soft shadow straight down": 90 degrees is straight down, in the sense rotate is.
        ->and(shadowOn($pptx, 'rectangle'))->toMatchArray(['dir' => 90])
        ->and(shadowOn($pptx, 'rectangle')['blur'])->toBeGreaterThan(0)
        ->and(shadowOn($pptx, 'rectangle')['dist'])->toBeGreaterThan(0);
});

it('throws one shadow at 45 degrees and one upward at 40%', function () {
    [$pptx] = builtDeck('14-shadows.md');

    expect(shadowOn($pptx, 'hexagon'))->toMatchArray(['dir' => 45])
        ->and(shadowOn($pptx, 'diamond'))->toMatchArray(['dir' => 180, 'alpha' => 40]);
});

it('casts a shadow on a text block and on the picture, which is a different element again', function () {
    [$pptx] = builtDeck('14-shadows.md');

    expect(shadowedNames($pptx))->toHaveCount(7)
        ->and(shadowOn($pptx, 'image'))->not->toBeNull()
        ->and(shadowOn($pptx, 'content'))->not->toBeNull();
});

it('leaves the caption boxes on the shadow slide without one', function () {
    [$pptx] = builtDeck('14-shadows.md');
    $slide = $pptx->part('ppt/slides/slide1.xml');

    // "The caption boxes have none", and the reason it is worth a test: a shape naming none of
    // the five attributes is written with no `<a:effectLst>` at all, so the captions are the
    // evidence that the feature does not reach every shape on the slide.
    preg_match_all('#<p:sp>(?:(?!</p:sp>).)*?name="content"#s', $slide, $captions);

    expect(substr_count($slide, '<a:effectLst>'))->toBe(7)
        ->and(count($captions[0]))->toBeGreaterThan(7);
});

it('covers the placed deck twice, at its ends, with the theme own near-black', function () {
    [$pptx] = builtDeck('13-placed-deck.md');

    // "A dark cover and a dark sign-off", each "a full-bleed
    // `::rectangle{fill=dk1 layer=back}`". Two claims, and they are different: *full-bleed* is
    // the rectangle covering the slide, and `layer=back` is the rectangle being drawn before
    // the flow — which is what puts the words on top of it. It is not drawn before the
    // layout's own title placeholder, because that placeholder is written first whatever the
    // deck asked for, so asserting `layer=back` means before the first `content` and not
    // before the first shape.
    foreach ([1, 8] as $slide) {
        $full = array_values(array_filter(
            $pptx->positions($slide),
            static fn (array $shape): bool => $shape['x'] === 0.0
                && $shape['y'] === 0.0
                && $shape['width'] === 960.0
                && $shape['height'] === 540.0,
        ));

        $names = $pptx->shapeNames($slide);

        expect($full)->toHaveCount(1)
            ->and($full[0]['name'])->toBe('rectangle')
            ->and($pptx->fillOf('rectangle', $slide))->toBe('#0a0a0a')
            ->and(array_search('rectangle', $names, true))->toBeLessThan(array_search('content', $names, true));
    }
});

it('fades decoration over the two dark slides and nowhere else', function () {
    [$pptx] = builtDeck('13-placed-deck.md');
    $count = 8;

    // "Translucent decoration over both dark slides" — two shapes on each of the ends, and
    // none on the six between, because a fade that reached the light slides would be a
    // different claim from the one the row makes.
    for ($slide = 1; $slide <= $count; $slide++) {
        preg_match_all('#<a:alpha val="(\d+)"/>#', $pptx->part("ppt/slides/slide{$slide}.xml"), $alphas);

        $faded = array_filter($alphas[1], static fn (string $value): bool => (int) $value < 100000);

        $ends = $slide === 1 || $slide === $count;

        expect($faded)->toHaveCount($ends ? 2 : 0);
    }
});

it('draws the placed deck out of free shapes and nothing else', function () {
    [$pptx] = builtDeck('13-placed-deck.md');

    // "built out of free shapes and nothing else", which is the claim the deck exists to make.
    // Every shape on every slide is one the deck placed: `rectangle`, `circle` and the text
    // blocks, plus the layout's own title on the cover.
    $names = [];

    for ($slide = 1; $slide <= 8; $slide++) {
        $names = array_merge($names, $pptx->shapeNames($slide));
    }

    expect(array_values(array_unique($names)))->toEqualCanonicalizing(['rectangle', 'circle', 'content', 'title']);
});

it('puts the placed deck agenda on five numbered circles', function () {
    [$pptx] = builtDeck('13-placed-deck.md');

    // "An agenda of numbered circles" — five of them, on the slide after the cover, and the
    // numbers are in the text rather than in the shape, which is why the text is checked too.
    expect(array_filter($pptx->shapeNames(2), static fn (string $name): bool => $name === 'circle'))->toHaveCount(5);

    foreach (['01', '02', '03', '04', '05'] as $number) {
        expect($pptx->slideText(2))->toContain($number);
    }
});

it('builds the placed deck four-pillar comparison out of four rectangles', function () {
    [$pptx] = builtDeck('13-placed-deck.md');

    // "A four-pillar comparison", which a Markdown table could not have drawn and a shape
    // grid can: four filled panels, side by side at the same height.
    $pillars = array_values(array_filter(
        $pptx->positions(6),
        static fn (array $shape): bool => $shape['name'] === 'rectangle',
    ));

    $lefts = array_column($pillars, 'x');
    $ordered = $lefts;
    sort($ordered);

    expect($pillars)->toHaveCount(4)
        ->and(array_values(array_unique(array_column($pillars, 'height'))))->toHaveCount(1)
        // Side by side, so each one starts further right than the last.
        ->and($lefts)->toBe($ordered)
        ->and(array_unique($lefts))->toHaveCount(4);
});

it('carries no contact detail the placed deck could belong to a real company for', function () {
    [$pptx] = builtDeck('13-placed-deck.md');
    $text = $pptx->slideText(1) . $pptx->slideText(8);

    // "The company is invented", and the deck is published, so the claim is that every address
    // in it is one nobody can be reached at: `.example` is reserved by the IANA precisely so
    // that it can appear in a document without reaching anybody, and 555 is the range held
    // back from real numbers.
    expect($text)->toContain('ridgeline.example')
        ->and($text)->toContain('+1 555 0100')
        // An address on any other domain, or a number outside the range held back from real
        // ones, is a detail somebody could be reached at.
        ->and(preg_match('/[\w.+-]+@[\w.-]*\.(?!example\b)[\w]+/', $text) === 1)->toBeFalse()
        ->and(preg_match_all('/\+[\d][\d ().-]{7,}/', $text, $numbers) === 0 ? [] : $numbers[0])
        ->each->toStartWith('+1 555 ');
});

it('draws a word in a style of the theme in the colour that style names', function () {
    [$pptx] = builtDeck('15-inline-styles.md');

    // "Three words and three colours in one box": the word that says nothing about itself
    // takes the colour of the box it is in, the word in the theme's `brand` style takes
    // the theme's `accent1` and is bold, and the word in `muted` takes the quiet ink at
    // fourteen points against the box's twenty-six.
    expect(runsOfShape($pptx, 'content', 2))->toBe([
        ['text' => 'Sextant ', 'colour' => 'F9E2AF', 'size' => 26, 'bold' => false],
        ['text' => 'Instrument Works', 'colour' => '89B4FA', 'size' => 26, 'bold' => true],
        ['text' => ' — ', 'colour' => 'F9E2AF', 'size' => 26, 'bold' => false],
        ['text' => 'est. 1934', 'colour' => 'BAC2DE', 'size' => 14, 'bold' => false],
    ]);
});

it('lays one property over a named style and leaves the rest of it alone', function () {
    [$pptx] = builtDeck('15-inline-styles.md');

    // "The raw form" needs no style of the theme's, and the word after it is in `brand`
    // with its colour replaced: it is still bold, because the attribute named no weight,
    // and it is still the size of the box, because the attribute named no size.
    expect(runsOfShape($pptx, 'content', 3, 0))->toBe([
        ['text' => 'Barge', 'colour' => 'A6E3A1', 'size' => 18, 'bold' => false],
        ['text' => ' · ', 'colour' => 'BAC2DE', 'size' => 20, 'bold' => false],
        ['text' => 'No. 4', 'colour' => 'A6E3A1', 'size' => 20, 'bold' => true],
    ]);
});

it('carries the messages the three refusals write', function () {
    [$pptx] = builtDeck('15-inline-styles.md');

    // A refusal cannot be shown by being refused — the deck would not build — so the deck
    // carries the syntax and the message beside it. The messages are the library's own:
    // the styles the theme does have, the two ways out of a label that holds Markdown, and
    // the reason a highlight cannot be written.
    expect($pptx->slideText(5))->toContain('The styles are: brand, muted.')
        ->and($pptx->slideText(5))->toContain('which is Markdown')
        ->and($pptx->slideText(6))->toContain('which the slides half does not draw');
});

it('counts the slides of the templates deck and not its definitions', function () {
    [$pptx] = builtDeck('16-templates.md');

    // "A definition is not a slide of the deck": the file has five slides and two
    // definitions, and the number at the foot of the last slide is the fifth of five.
    expect($pptx->slideCount())->toBe(5)
        ->and(runsOfShape($pptx, 'slide-number', 2)[0]['text'])->toBe('2 / 5')
        ->and(runsOfShape($pptx, 'slide-number', 5)[0]['text'])->toBe('5 / 5');
});

it('draws the deck own page number rather than the one the file holds', function () {
    [$pptx] = builtDeck('16-templates.md');
    [$other] = builtDeck('04-frontmatter.md');

    // The file's `page` is a bare number in `subtle` at ten points, and the deck's own is
    // `accent1` at twelve and carries the number of the deck after it. Two decks name the
    // same file and the same template, and the difference between them is the deck's own
    // definition of that name winning.
    $mine = runsOfShape($pptx, 'slide-number', 4)[0];
    $theirs = runsOfShape($other, 'slide-number', 5)[0];

    expect($mine['text'])->toBe('4 / 5')
        ->and($mine['colour'])->toBe('333333')
        ->and($mine['size'])->toBe(12)
        ->and($theirs['text'])->toBe('5')
        ->and($theirs['colour'])->toBe('4A4A4A')
        ->and($theirs['size'])->toBe(10);
});

it('draws the imported template on the slide that names it, and on no other', function () {
    [$pptx] = builtDeck('16-templates.md');

    // The band is the one piece of furniture that came out of `templates/templates.md` and
    // was not overridden, so it is on the slide that names it and nowhere else — and it is
    // across the head of the slide, which is where the file puts it.
    foreach (range(1, $pptx->slideCount()) as $slide) {
        $bands = array_values(array_filter(
            $pptx->positions($slide),
            static fn (array $shape): bool => $shape['name'] === 'rectangle',
        ));

        expect($bands)->toHaveCount($slide === 3 ? 1 : 0);

        if ($slide !== 3) {
            continue;
        }

        expect($bands[0]['x'])->toBe(0.0)
            ->and($bands[0]['y'])->toBe(0.0)
            ->and($bands[0]['width'])->toBe(960.0);
    }
});

it('draws the furniture of the corporate template on every slide that names it', function () {
    [$pptx] = builtDeck('17-corporate-template.md');

    // The wordmark, the rule and the footer are three templates of the deck's own templates
    // file, and a slide names the ones it wants. The five light slides name all three; the
    // cover and the two dark slides name the light wordmark and nothing else — a cover has
    // no page number, and a dark slide has no footer on it.
    foreach (range(1, $pptx->slideCount()) as $slide) {
        $names = $pptx->shapeNames($slide);

        expect($names)->toContain('image');

        if (in_array($slide, [1, 7, 8], true)) {
            expect($names)->not->toContain('slide-number');

            continue;
        }

        expect($names)->toContain('slide-number')
            ->and($names)->toContain('rectangle');
    }
});

it('writes the corporate template own name on the light slides and the light one on the dark', function () {
    [$pptx] = builtDeck('17-corporate-template.md');

    // The name is ink on a light page and white on a dark one, and the two are the same
    // named style with the colour laid over it — a deck cannot carry one colour on both
    // kinds of slide, and a templates file cannot decide for itself.
    $light = runOfText($pptx, 'Halden Instruments', 2);
    $dark = runOfText($pptx, 'Halden Instruments', 7);

    expect($light['colour'])->toBe('15243B')
        ->and($light['size'])->toBe(15)
        ->and($dark['colour'])->toBe('FFFFFF')
        ->and($dark['size'])->toBe(15);
});

it('numbers the light slides of the corporate template and not its cover', function () {
    [$pptx] = builtDeck('17-corporate-template.md');

    // The number is the deck's own, and a cover carries none: the template that draws it
    // names the footer, and the cover does not name the footer.
    expect(runsOfShape($pptx, 'slide-number', 2)[0]['text'])->toBe('2')
        ->and(runsOfShape($pptx, 'slide-number', 6)[0]['text'])->toBe('6');
});

/**
 * The run of the text shape that holds the given text, on the given slide.
 *
 * A slide may hold a dozen text shapes and the one that matters is named by what it says
 * rather than by where it is: the wordmark of the corporate template is drawn over what
 * the slide placed itself, so its place in the order is the end of the slide and not a
 * number anybody would want to write down.
 *
 * @return array{text: string, colour: string|null, size: float|null, bold: bool}
 */
function runOfText(Pptx $pptx, string $text, int $slide): array
{
    $shapes = preg_split('#(?=<p:sp>|<p:graphicFrame>|<p:pic>)#', $pptx->part("ppt/slides/slide{$slide}.xml")) ?: [];

    foreach ($shapes as $shape) {
        // A text shape and not a picture: the logo carries the company's name as its
        // alternative text, and a picture has no colour of its own to read.
        if (preg_match('#<p:cNvPr id="\d+" name="content"#', $shape) !== 1 || !str_contains($shape, $text)) {
            continue;
        }

        preg_match('#<a:srgbClr val="([0-9A-Fa-f]{6})"#', $shape, $colour);
        preg_match('#<a:rPr[^>]*\ssz="(\d+)"#', $shape, $size);

        return [
            'text' => $text,
            'colour' => $colour[1] ?? null,
            'size' => isset($size[1]) ? (int) $size[1] / 100 : null,
            'bold' => preg_match('#<a:rPr[^>]*\bb="1"#', $shape) === 1,
        ];
    }

    return ['text' => '', 'colour' => null, 'size' => null, 'bold' => false];
}
