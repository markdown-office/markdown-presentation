<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Document\Slide;
use MarkdownPresentation\Exception\MalformedFrontmatter;

/**
 * Slide templates: a piece of furniture written once and drawn on every slide that names it.
 *
 * The deck this came from wrote the same wordmark and the same rule on all twenty-eight of
 * its slides, and the repetition was the fault: change the wordmark and twenty-eight slides
 * had to be found and changed. A template is that furniture written once.
 *
 * Split into the three things that have to agree about it — what a definition is, which
 * slides are slides, and what a slide that names a template gets — because each of them is
 * wrong on its own in a way the others do not catch.
 */

/** A deck with a template definition in it and two slides that name it. */
const WITH_TEMPLATE = <<<'MARKDOWN'
    ---
    template: brand
    ---
    ::line{left=5% top=72.889% width=30% height=0.185%}

    ---
    layout: blank
    template: brand
    ---

    ---
    layout: blank
    template: brand
    ---
    MARKDOWN;

it('does not count a template definition as a slide of the deck', function () {
    // The whole of what a definition is: it is furniture, it is not a slide, and it does
    // not have a number. A deck that counted it would be a deck whose third slide is the
    // page number of the second.
    $deck = (new DeckParser())->parse(WITH_TEMPLATE);

    expect($deck->count())->toBe(2)
        ->and($deck->slides())->toHaveCount(2)
        ->and($deck->definitions())->toHaveCount(1);
});

it('numbers the slides of a deck over the slides and not over the definitions', function () {
    // The numbering is the deck's own: a definition takes no number and the slide after it
    // carries on as though the definition were not there.
    $deck = (new DeckParser())->parse(WITH_TEMPLATE);

    expect(array_map(static fn (Slide $slide): int => $slide->index(), $deck->slides()))->toBe([0, 1]);
});

it('reads a template definition as the template it defines', function () {
    $deck = (new DeckParser())->parse(WITH_TEMPLATE);
    $definition = $deck->definitions()[0];

    expect($definition->name())->toBe('brand')
        ->and($definition->body())->toContain('::line{');
});

it('reads a slide that names a template with another directive as a slide', function () {
    // A slide that names a template and says nothing else defines one; a slide that names
    // it along with the layout it is drawn in draws it.
    $deck = (new DeckParser())->parse(WITH_TEMPLATE);

    expect($deck->slides()[0]->frontmatter())->toBe(['layout' => 'blank', 'template' => 'brand']);
});

it('refuses a template definition whose name is not a name', function () {
    expect(fn () => (new DeckParser())->parse("---\ntemplate: [a, b]\n---\n"))
        ->toThrow(MalformedFrontmatter::class, 'A template definition names the template it defines');
});

it('reads the deck frontmatter out of the first slide that is one', function () {
    // A deck that opens with a definition has no frontmatter of its own until the first
    // slide that is one, and the theme it names is the deck's theme.
    $deck = (new DeckParser())->parse("---\ntemplate: brand\n---\n::line{}\n\n---\ntitle: Deck\n---\n\n# One\n");

    expect($deck->frontmatter())->toBe(['title' => 'Deck'])
        ->and($deck->count())->toBe(1);
});
