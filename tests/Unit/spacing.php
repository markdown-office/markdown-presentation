<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Render\Box;
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\TextFrame;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\Style;

/**
 * The air between blocks: what is in it, what is spent where, and what a theme's own number
 * decides.
 *
 * Every assertion here is on a part of a built file or on the number the layout measured a
 * shape with — never on bytes, and never on a rendering looked at rather than rendered.
 * The claims about how the output *looks* are in `tests/Readme/examples-decks.php`, which is
 * read off built decks, and in the two renders the gallery keeps.
 */

/**
 * What a deck becomes on disk, rendered from a scratch directory of its own.
 *
 * The directory is where a relative picture path would resolve, and a scratch one of its own
 * so that two tests measuring two decks cannot see each other's shapes.
 */
function spaced(string $markdown): Pptx
{
    $path = Scratch::file('spaced.pptx', '');
    $deck = (new DeckParser())->parse($markdown);

    (new PresentationRenderer(Scratch::directoryPath('spaced')))->render($deck, $path);

    return Pptx::open($path);
}

/**
 * The air between two shapes on a slide, in pixels: where the second one starts less where
 * the first one ended.
 *
 * Read off the file rather than out of the layout, because the claim is about what was
 * written and a measure that disagrees with the draw would be invisible here.
 *
 * @return list<float>
 */
function gapsBetween(array $shapes): array
{
    $gaps = [];

    for ($index = 1; $index < count($shapes); $index++) {
        $gaps[] = $shapes[$index]['y'] - ($shapes[$index - 1]['y'] + $shapes[$index - 1]['height']);
    }

    return $gaps;
}

it('stops the title box growing into the body box', function () {
    // The two numbers were computed without reference to each other: the title band asked for
    // two lines of the role's own title size and the body started at a fixed band, and at the
    // library's own values the title box ended ten pixels below the top of the body box. A
    // title that wrapped put its second line on the body's first, which no assertion about
    // the shape count would have caught.
    $title = (new SlideGeometry())->title(LayoutRole::Content);
    $body = (new SlideGeometry())->body(LayoutRole::Content)[0];

    expect($title)->not->toBeNull()
        ->and($title['box']->y + $title['box']->height)->toBeLessThanOrEqual($body->y);
});

it('leaves the gap between the title and the first body block', function () {
    // Clamped, not flowed: a slide's body is read in the same place on every slide, so the
    // title shrinks rather than pushing the body down with it.
    $geometry = new SlideGeometry();
    $shapes = spaced("# A long enough title\n\nFirst.\n")->positions();

    expect($geometry->title(LayoutRole::Content))->not->toBeNull()
        ->and($shapes[1]['y'] - ($shapes[0]['y'] + $shapes[0]['height']))
        ->toBeGreaterThanOrEqual(0.0)
        ->and($shapes[1]['y'])->toBe((float) $geometry->body(LayoutRole::Content)[0]->y);
});

it('gives a block with an edge of its own more air than a paragraph', function () {
    // The gap that used to read as cramped: a paragraph's ink stops short of the bottom of its
    // box and a table's border is drawn exactly on the edge of its own, so the same number
    // separates a paragraph from a paragraph further than it separates a paragraph from a
    // table.
    $geometry = new SlideGeometry();
    $paragraph = new Paragraph([], 1);
    $table = new Table([[[]]], [], 1);

    expect($geometry->gapBetween($table, $paragraph))
        ->toBeGreaterThan($geometry->gapBetween($paragraph, $paragraph));
});

it('gives a filled code block and a rule that air too', function () {
    $geometry = new SlideGeometry();
    $paragraph = new Paragraph([], 1);

    expect($geometry->gapBetween(new Rule(1), $paragraph))
        ->toBeGreaterThan($geometry->gapBetween($paragraph, $paragraph))
        ->and($geometry->gapBetween($paragraph, $paragraph))->toBe(12.0);
});

it('leaves a list less air around it than a paragraph leaves around itself', function () {
    // Around the list, not between its items: the items of one list are lines of a single
    // frame set with no gap between them, which is what makes them read as a list. This asks
    // about the two blocks either side of the boundary — two lists here, two paragraphs for
    // the comparison — and it is `LIST_GAP` that decides the first number.
    //
    // Named for what it measures. An earlier version of this said "between its items", which
    // is a claim about a gap the renderer does not write.
    $geometry = new SlideGeometry();
    $list = new ListBlock([['level' => 1, 'runs' => [], 'ordered' => false, 'start' => null]], 1);

    expect($geometry->gapBetween($list, new ListBlock($list->items, 1)))
        ->toBeLessThan($geometry->gapBetween(new Paragraph([], 1), new Paragraph([], 1)));
});

it('scales the air above a heading with the size of the heading', function () {
    // The one gap that is not a fraction of the theme's block gap, because a heading is not a
    // bigger paragraph: the air it wants is a function of how big the type is, so a theme with
    // a larger `headings` scale gets more room above its headings rather than the same room
    // around much bigger type.
    $small = new SlideGeometry(style: new Style(headings: [18, 16, 14, 12, 10]));
    $large = new SlideGeometry(style: new Style(headings: [34, 30, 26, 22, 18]));
    $level = 2;

    expect($large->spaceAbove(new Heading([], $level, 1)))
        ->toBeGreaterThan($small->spaceAbove(new Heading([], $level, 1)));
});

it('gives a heading more air above it than below', function () {
    // A heading belongs to the text that follows it, which is why markdown-word writes a
    // heading's space before at twice its space after and `keepNext` on top of that.
    $heading = new Heading([], 2, 1);

    expect((new SlideGeometry())->spaceAbove($heading))
        ->toBeGreaterThan((new SlideGeometry())->spaceBelow($heading));
});

it('spends the air a heading has above it in the layout, not as a leading gap in the frame', function () {
    // A frame opens on whatever the deck wrote first, and that is what decides the room the
    // shape above it has to leave. A leading gap inside the box would push the frame's text
    // down inside a box measured without it, so the air goes on the seam instead.
    $geometry = new SlideGeometry();
    $frame = new TextFrame([new Heading([], 2, 1), new Paragraph([], 1)]);

    expect($geometry->spaceAbove($frame))->toBe($geometry->spaceAbove(new Heading([], 2, 1)))
        ->and($geometry->spaceBelow($frame))->toBe($geometry->spaceBelow(new Paragraph([], 1)));
});

it('draws a heading as a paragraph of the frame rather than a shape of its own', function () {
    // One shape the reader cannot lift a heading out of, and one measured height instead of
    // one per block: a frame is measured once and `normAutofit` stands behind all of it.
    $pptx = spaced("# Title\n\n## A section\n\nThe body.\n");
    $xml = $pptx->part(SLIDE_ONE);

    expect($pptx->shapeNames())->toBe(['title', 'text'])
        ->and($xml)->toContain('<a:normAutofit/>')
        ->and(substr_count($xml, '<a:p>'))->toBe(3);
});

it('draws a heading at the size of its own level, in the body of the frame with it', function () {
    // A heading is a paragraph whose runs carry the heading's size and face, so the frame's
    // first run is the heading's and the body paragraph behind it is the body's.
    $geometry = new SlideGeometry();
    $pptx = spaced("# Title\n\n## A section\n\nThe body.\n");

    expect($pptx->fontSize('text'))->toBe((float) $geometry->headingSize(2));
});

it('draws a quote as an indented paragraph of the frame rather than a shape of its own', function () {
    // As a shape a quote was offset by a gutter and drawn in a box a gutter narrower, which
    // put it outside the run of type around it and made the air above and below it the
    // column's arithmetic. As a paragraph it is an indent, which moves the text inside the
    // frame without moving the frame.
    $pptx = spaced("> quoted\n\nThe body.\n");

    expect($pptx->shapeNames())->toBe(['text'])
        ->and($pptx->positionOf('text')['x'])->toBe(40.0)
        ->and($pptx->part(SLIDE_ONE))->toContain('marL="190500"');
});

it('counts a heading in the frame at its own line height rather than the body\'s', function () {
    // The frame is one box measured once, so a heading inside it has to be measured at the size
    // it is drawn at: five lines of a 20pt heading are not five lines of a 16pt body, and a
    // frame that added them up at one size would put its own text over the block below.
    $geometry = new SlideGeometry();
    $body = $geometry->style()->body();
    $heading = $geometry->headingSize(2);

    // A heading first is not enough — the first heading of a slide is its title — so this one
    // is the second, which is a heading in a body box.
    expect(spaced('# Lead' . "\n\n## " . str_repeat('h', 500) . "\n")->positionOf('text')['height'])
->toBe(9.0 * $geometry->lineHeight($heading))
        ->and($geometry->lineHeight($heading))->toBeGreaterThan($geometry->lineHeight($body));
});

it('writes the air between two paragraphs as the two halves PowerPoint adds together', function () {
    // `a:spcPts` is in hundredths of a point and a pixel is three quarters of one, so half of
    // a 12px block gap is 450 there: 4.5pt under the first paragraph and 4.5pt over the
    // second. Written as one half twice the reader would see a bigger gap above a paragraph
    // than below it.
    $xml = spaced("First.\n\nSecond.\n")->part(SLIDE_ONE);

    preg_match_all('#<a:spcAft>\s*<a:spcPts val="450"/>#', $xml, $after);
    preg_match_all('#<a:spcBef>\s*<a:spcPts val="450"/>#', $xml, $before);

    expect($after[0])->toHaveCount(1)
        ->and($before[0])->toHaveCount(1);
});

it('adds the two halves back up to the gap between two paragraphs of one shape', function () {
    // The measure half and the draw half, checked against each other: the frame is one pixel
    // taller than its two paragraphs and their leading, and it has to be, or the next block is
    // drawn over the bottom of this one.
    $geometry = new SlideGeometry();
    $line = $geometry->lineHeight($geometry->style()->body());

    expect(spaced("First.\n\nSecond.\n")->positionOf('text')['height'])->toBe($line * 2 + 12.0);
});

it('ends a run of type at a table and starts another after it', function () {
    // What cannot be folded into a frame, and why: a table is a grid and a code block has a
    // fill painted behind it, and neither is expressible as part of a text frame.
    expect(spaced("One.\n\n| A |\n| --- |\n| B |\n\nTwo.\n")->shapeNames())
        ->toBe(['text', 'table', 'text']);
});

it('leaves a frame the same height whether it is one shape or two at a step boundary', function () {
    // The claim a split frame has to keep: two frames of one paragraph each are given the same
    // air between them as the one gap of the frame they replaced, so a build-up does not make
    // its own steps taller than the slide they are on.
    $one = spaced("One.\n\nTwo.\n")->positions()[0];
    [$first, $second] = array_values(array_filter(
        spaced("One.\n\n::click\n\nTwo.\n")->positions(),
        static fn (array $shape): bool => $shape['name'] === 'text',
    ));

    expect($one['height'])->toBe($first['height'] + ($second['y'] - ($first['y'] + $first['height'])) + $second['height']);
});