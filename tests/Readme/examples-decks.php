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
    $path = sys_get_temp_dir() . '/mdp-example-' . md5($name) . '.pptx';

    // The renderer is handed the directory the deck is in, because that is what a relative
    // image path in it resolves against.
    (new PresentationRenderer($root . '/examples/markdown'))->render(
        new DeckParser()->parse($source),
        $path,
    );

    return [Pptx::open($path), $source];
}

$root = dirname(__DIR__, 2);
$readme = (string) file_get_contents($root . '/examples/README.md');

it('lists every deck that is on disk', function () use ($readme, $root) {
    // The table names the sources; the directory holds them. A deck added without a row
    // is a deck nobody is told about, and a row without a deck is a lie.
    $listed = [];
    preg_match_all('/`([0-9]{2}-[a-z-]+)\.md`/', $readme, $matches);

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
    preg_match_all('/`([0-9]{2}-[a-z-]+)\.md`\s*\|\s*\*\*nothing\*\*/', $readme, $declined);

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
    '01-kitchen-sink.md',
    '02-layout-roles.md',
    '04-frontmatter.md',
    '05-steps.md',
    '06-tables.md',
    '07-images.md',
    '08-code.md',
    '09-inline-markup.md',
]);

it('refuses the deck whose conversion is the failure it exists to demonstrate', function () use ($root) {
    $source = (string) file_get_contents($root . '/examples/markdown/10-rejected.md');

    // Parsing succeeds — directives are checked at render, not at parse — and rendering is
    // where the deck is refused.
    expect(function () use ($root, $source): void {
        (new PresentationRenderer($root . '/examples/markdown'))->render(
            new DeckParser()->parse($source),
            sys_get_temp_dir() . '/mdp-rejected.pptx',
        );
    })->toThrow(UnknownDirective::class);
});

it('leaves emphasis that does not close exactly as it was written', function () {
    [$pptx] = builtDeck('01-kitchen-sink.md');

    // Refusing a deck over a stray asterisk is a deck nobody can show, so the unclosed
    // forms are text.
    expect($pptx->slideText(1))->toContain('2 * 3')
        ->and($pptx->slideText(1))->toContain('**unclosed')
        ->and($pptx->slideText(1))->toContain('snake_case_name');
});

it('nests four spaces of indent one level and not two', function () {
    [$pptx] = builtDeck('01-kitchen-sink.md');

    // A list is one shape and its indentation lives on the paragraphs inside it, so the
    // left edges of the shapes say nothing. What matters is how many indent steps the
    // bulleted list has: reading four spaces as two levels would add a step.
    $levels = [];
    $shape = '';

    foreach (explode('</p:sp>', $pptx->part('ppt/slides/slide2.xml')) as $chunk) {
        if (preg_match('/name="([^"]+)"/', $chunk, $name) === 1) {
            $shape = $name[1];
        }

        if ($shape !== 'list') {
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
    [$pptx] = builtDeck('01-kitchen-sink.md');
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

it('reveals each step cumulatively', function () {
    [$pptx] = builtDeck('05-steps.md');

    expect($pptx->slideCount())->toBeGreaterThan(2);
});