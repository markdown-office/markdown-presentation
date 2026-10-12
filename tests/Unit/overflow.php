<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ContentOverflow;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * What happens to a slide whose content does not fit.
 *
 * The rule is clamp, then report — never overlap and never say nothing. A block is drawn as
 * small as the room allows, and every block that had to be drawn that way is listed by
 * {@see PresentationRenderer::overflows()}; a caller who would rather have no file asks for
 * the strict renderer and gets {@see ContentOverflow} instead.
 *
 * The whole of it is here because the alternative — a slide quietly missing its last block —
 * is the failure this library exists to avoid, and a test that only checked the file would
 * not notice it happening.
 */

/** The paragraph these decks are made too long out of, repeated by `str_repeat()`. */
const FILLER = "Text.\n\n";

/**
 * Sixty lines of code in a fenced block, which is the block these decks overflow on.
 *
 * A function rather than a constant because the same sixty lines are wanted four times and
 * the number that makes it overflow is the claim: 60 × 19px plus the insets does not fit a
 * 396-pixel body box, and a shorter block would stop being the thing under test.
 */
function tallCodeBlock(): string
{
    return "```\n" . str_repeat("line;\n", 60) . "```\n";
}

/** Render a deck, returning the renderer, because the report is on the renderer. */
function overflowing(string $markdown, bool $strict = false): PresentationRenderer
{
    $renderer = new PresentationRenderer(strict: $strict);
    $renderer->render((new DeckParser())->parse($markdown), Scratch::file('deck.pptx', ''));

    return $renderer;
}

it('reports nothing for a deck that fits its slides', function () {
    expect(overflowing("# One\n\nA line or two of text.\n")->overflows())->toBe([]);
});

it('reports a block it had to make smaller, and how much smaller', function () {
    // Sixty lines of code at 12pt is 60 × 19px plus the insets, in a body box 396px tall.
    // It is drawn as small as the room allows, and this is where that is said out loud.
    expect(overflowing(tallCodeBlock())->overflows())->toBe([[
        'slide' => 1,
        'step' => 1,
        'column' => 0,
        'block' => 'code',
        'wanted' => 1150,
        'available' => 396,
    ]]);
});

it('reports every shape a full column left no room for, not only the first', function () {
    // A column that has run out stays out, so every shape below the point it ran out is
    // clamped too. One entry for the first would say the column recovered.
    //
    // A code block between two runs of text is what makes this a test of several entries: a
    // run of paragraphs is one shape now, so forty of them are one entry and forty paragraphs
    // on their own would prove nothing about the ones below the point the column ran out.
    $overflows = overflowing(
        str_repeat(FILLER, 30) . "```\nline;\n```\n\n" . str_repeat("More.\n\n", 30),
    )->overflows();

    expect(count($overflows))->toBeGreaterThan(1)
        ->and(array_column($overflows, 'block'))->toBe(['text', 'code', 'text']);
});

it('reports one entry for a run of paragraphs, because it is one shape', function () {
    // The other half: forty paragraphs that do not fit are one shape that did not fit, and an
    // entry for each of them would say forty things were missing where one is.
    expect(overflowing(str_repeat(FILLER, 40))->overflows())->toHaveCount(1);
});

it('names the slide and the step a block overflowed on', function () {
    // A `::click` build-up puts the same blocks on several slides, and step 2 is where the
    // second block arrives — so "slide 1" on its own does not say which drawing of that
    // slide ran out of room.
    $overflows = overflowing(
        "# One\n\n" . str_repeat(FILLER, 20) . "::click\n\n" . str_repeat("More.\n\n", 20),
    )->overflows();

    expect(array_column($overflows, 'slide'))->toBe(array_fill(0, count($overflows), 1))
        ->and(array_values(array_unique(array_column($overflows, 'step'))))->toBe([1, 2]);
});

it('reports the column a block overflowed in', function () {
    // Two columns overflow independently, and the second is the one a reader looks at last.
    $overflows = overflowing(
        "---\nlayout: two-column\n---\n\n# Both\n\n" . str_repeat("Left.\n\n", 20) . "## Right\n\n"
        . str_repeat("Right.\n\n", 20),
    )->overflows();

    expect(array_column($overflows, 'column'))->toContain(1);
});

it('empties the report on each render rather than accumulating it', function () {
    // A layout belongs to one deck: a caller converting several in a loop wants the last
    // one's overflows, not all of them.
    $renderer = overflowing(str_repeat(FILLER, 40));
    $renderer->render((new DeckParser())->parse("Fits.\n"), Scratch::file('deck.pptx', ''));

    expect($renderer->overflows())->toBe([]);
});

it('refuses a deck that overflows when the renderer is strict', function () {
    // Strict is for a caller that would rather have no file than a file with a slide on it
    // that is missing something, and the message has to say which slide and what.
    expect(fn () => overflowing(tallCodeBlock(), strict: true))
        ->toThrow(
            ContentOverflow::class,
            'Slide 1, step 1, column 1: its code needs 1150px of height and 396px are left.',
        );
});

it('writes no file when a strict render refuses the deck', function () {
    // The overflow is found while the slides are being built, which is before anything is
    // saved — so a refused deck leaves no half-written file behind.
    $path = Scratch::file('deck.pptx', '');

    expect(fn () => (new PresentationRenderer(strict: true))->render(
        (new DeckParser())->parse(tallCodeBlock()),
        $path,
    ))->toThrow(ContentOverflow::class);

    expect(filesize($path))->toBe(0);
});

it('writes a file for a deck that overflows when the renderer is not strict', function () {
    // The pair with the one above: the file is the point of the non-strict renderer, and a
    // clamp that refused to write anything would be strict with extra steps.
    expect(overflowing(tallCodeBlock())->overflows())->not->toBe([]);
});
