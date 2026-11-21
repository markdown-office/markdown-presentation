<?php

declare(strict_types=1);

use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Render\CommonMark\InlineTranslator;
use MarkdownPresentation\Render\Run;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Theme\TextStyle;
use MarkdownPresentation\Theme\TextStyles;
use MarkdownPresentation\Theme\Theme;

/**
 * A theme with one named style in it, so that a label may name one.
 *
 * Built in code rather than read from a file, because what these tests are about is the
 * break inside a label and not where a theme comes from.
 */
function themeWithBrand(): Theme
{
    return Theme::fromArray(
        ['name' => 'Acme'],
        'theme.yml',
        new TextStyles(['brand' => new TextStyle(colour: 'accent1')]),
    );
}

/**
 * The runs of the words of a slide, as the renderer reads them.
 *
 * A list item is one line of text on a slide and a paragraph is a block, and the two are
 * read with different breaks — which is what the tests below are about.
 *
 * @return list<Run>
 */
function runsOfSlide(string $markdown): array
{
    $blocks = (new BlockTranslator())->parse(
        $markdown,
        1,
        Scratch::directory(),
        deckScope(Scratch::directory()),
        themeWithBrand(),
    )->blocks();

    $block = $blocks[0] ?? null;

    return match (true) {
        $block instanceof ListBlock => $block->items[0]['runs'],
        $block instanceof Paragraph => $block->runs,
        default => [],
    };
}

it('draws a label in a list item as one line of text', function () {
    // A list item written across two lines is one line of text on the slide, and the
    // label in it is part of that line: the break inside the label is the break of the
    // item, and the item's own line is a space.
    expect(InlineTranslator::text(runsOfSlide("- :brand[one\n  two]\n")))->toBe('one two');
});

it('draws a label in a paragraph with the break in the middle of it', function () {
    // A paragraph written across two lines is one block with the break in the middle of
    // it, and the label in it keeps that break: the label is part of the paragraph.
    expect(InlineTranslator::text(runsOfSlide("A :brand[three\nfour] word.\n")))->toBe("A three\nfour word.");
});

it('draws a link in a list item as one line of text', function () {
    // The label of a link is text, and the text of a list item is one line of it.
    expect(InlineTranslator::text(runsOfSlide("- [one\n  two](https://example.com)\n")))->toBe('one two');
});

it('draws the words of a picture in a list item as one line of text', function () {
    // A picture inside a sentence is text, and its words are the label it was written
    // with.
    expect(InlineTranslator::text(runsOfSlide("- a ![one\n  two](logo.png) b\n")))->toBe('a !one two b');
});
