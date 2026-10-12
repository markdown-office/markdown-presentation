<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Render\PresentationRenderer;
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
