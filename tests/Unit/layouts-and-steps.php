<?php

declare(strict_types=1);

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Document\Slide;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\ThemeDirectory;

/**
 * The three mapping decisions a deck author can argue with: which layout a slide gets, how
 * `v-click` builds a slide up, and where a role's geometry comes from.
 *
 * Every assertion reads the written file. "The deck said `layout: two-column`" is only
 * checkable through the layout part the slide is bound to, which is what makes the mapping a
 * claim about the `.pptx` and not about the renderer's intentions.
*/

function slideDeck(string $markdown): Pptx
{
    $deck = (new DeckParser())->parse($markdown);
    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer(imageDirectory()))->render($deck, $path);

    return Pptx::open($path);
}

it('uses the content layout for a slide that names none', function () {
    // The default is what makes a deck that says nothing about layout render at all, and it
    // has to be a layout the theme maps rather than a guess.
    expect(slideDeck("# One\n")->layoutNames())->toBe([1 => 'Title and Content']);
});

it('gives a slide the layout its role maps to', function () {
    // This is the whole point of a role: the deck writes the portable name and the theme
    // decides what it is called in the file.
    $pptx = slideDeck("---\nlayout: two-column\n---\n# One\n");

    expect($pptx->layoutNames())->toBe([1 => 'Two Content'])
        ->and($pptx->layoutOf(1))->toBe('1');
});

it('builds one layout per role the deck uses, and no others', function () {
    // A theme that maps two of the five roles would otherwise fail on the three its deck
    // never asked for, which is not the same failure as a deck asking for one it did not map.
    $pptx = slideDeck("---\nlayout: title\n---\n# One\n\n---\nlayout: blank\n---\n# Two\n");

    expect($pptx->layoutNames())->toBe([1 => 'Title Slide', 2 => 'Blank']);
});

it('binds each slide to the layout its own role asks for', function () {
    $pptx = slideDeck("---\nlayout: title\n---\n# One\n\n---\nlayout: blank\n---\n# Two\n");

    expect($pptx->layoutOf(1))->toBe('1')
        ->and($pptx->layoutOf(2))->toBe('2');
});

it('refuses a layout role that is not one, and names the ones there are', function () {
    expect(fn () => slideDeck("---\nlayout: three-column\n---\n# One\n"))
        ->toThrow(
            UnknownLayoutRole::class,
            'Slide 1 names the layout "three-column", which is not a layout role. '
            . 'The roles are: title, content, two-column, section, blank.',
        );
});

it('refuses a layout the theme does not map, and names the ones it maps', function () {
    // A role the theme does not map is the theme's to fix, and its message already says so.
    $themes = ThemeDirectory::create([
        'theme.yml' => "name: Sparse\n",
        'layouts.yml' => "layouts:\n  title: Title Slide\n  content: Title and Content\n",
    ]);

    $path = Scratch::file('deck.pptx', '');
    $deck = (new DeckParser())->parse("---\ntheme: {$themes}\nlayout: section\n---\n# One\n");

    expect(fn () => (new PresentationRenderer())->render($deck, $path))
        ->toThrow(UnknownLayoutRole::class, 'maps no "section" layout. It maps: title, content');
});

it('refuses a layout that is not a string, and names the roles', function () {
    expect(fn () => slideDeck("---\nlayout:\n  name: nested\n---\n# One\n"))
        ->toThrow(
            MalformedFrontmatter::class,
            'has a "layout" that is not a layout role — one of: title, content, two-column, section, blank.',
        );
});

it('refuses a layout that is only whitespace, and names the roles', function () {
    expect(fn () => slideDeck("---\nlayout: \"  \"\n---\n# One\n"))
        ->toThrow(MalformedFrontmatter::class, 'one of: title, content, two-column, section, blank');
});

it('names the slide whose layout is not one', function () {
    expect(fn () => slideDeck("# One\n\n---\nlayout: three-column\n---\n# Two\n"))
        ->toThrow(UnknownLayoutRole::class, 'Slide 2 names the layout "three-column"');
});

it('writes a title slide with a title box high on the slide', function () {
    // A title layout that drew its title where a content slide does would be a title layout
    // nobody could tell from a content one.
    $geometry = new SlideGeometry();
    $title = $geometry->title(LayoutRole::Title);
    $content = $geometry->title(LayoutRole::Content);

    expect($title['box']->y)->toBeGreaterThan($content['box']->y)
        ->and($title['size'])->toBeGreaterThan($content['size']);
});

it('writes a section slide with a centred title', function () {
    expect((new SlideGeometry())->title(LayoutRole::Section)['alignment'])->toBe('ctr');
});

it('leaves a blank layout with no title box at all', function () {
    // A blank layout that drew a title would not be blank, and the way to put a title on such
    // a slide is to write a heading in its body.
    expect((new SlideGeometry())->title(LayoutRole::Blank))->toBeNull();
});

it('keeps the body of a blank layout from where a title would have ended', function () {
    $geometry = new SlideGeometry();

    expect($geometry->body(LayoutRole::Blank)[0]->y)
        ->toBeLessThan($geometry->body(LayoutRole::Content)[0]->y);
});

it('gives a two-column layout two body boxes and every other role one', function () {
    $geometry = new SlideGeometry();

    expect($geometry->body(LayoutRole::TwoColumn))->toHaveCount(2)
        ->and($geometry->body(LayoutRole::Content))->toHaveCount(1)
        ->and($geometry->body(LayoutRole::Title))->toHaveCount(1)
        ->and($geometry->body(LayoutRole::Section))->toHaveCount(1)
        ->and($geometry->body(LayoutRole::Blank))->toHaveCount(1);
});

it('puts the two columns of a two-column layout side by side and the same width', function () {
    $geometry = new SlideGeometry();
    [$left, $right] = $geometry->body(LayoutRole::TwoColumn);

    expect($left->width)->toBe($right->width)
        ->and($right->x)->toBeGreaterThan($left->x + $left->width)
        ->and($right->x + $right->width)->toBeLessThanOrEqual(SlideGeometry::WIDTH - SlideGeometry::MARGIN);
});

it('shifts a heading down the slide as it gets deeper', function () {
    $geometry = new SlideGeometry();

    expect($geometry->headingSize(1))->toBeGreaterThan($geometry->headingSize(3))
        ->and($geometry->headingSize(3))->toBeGreaterThan($geometry->headingSize(6));
});

it('stops shrinking a heading at the sixth level', function () {
    // Markdown has six levels; a heading deeper than that has nothing left to shrink to, and
    // failing on it would be refusing a document CommonMark accepts.
    $geometry = new SlideGeometry();

    expect($geometry->headingSize(7))->toBe($geometry->headingSize(6));
});

it('reads a named level as the depth a title of that level would be drawn at', function () {
    // `level: 1` is the layout's own title and every level below it is a heading of that
    // depth in the same body, which is the whole of what the pair means: a title that is not
    // a heading, told how deep to read.
    $geometry = new SlideGeometry();

    expect($geometry->titleSize(28, 1))->toBe(28)
        ->and($geometry->titleSize(28, 2))->toBe($geometry->headingSize(1))
        ->and($geometry->titleSize(28, 3))->toBe($geometry->headingSize(2))
        ->and($geometry->titleSize(28, 7))->toBe($geometry->headingSize(6));
});

it('draws a title named in the frontmatter at the level it names', function () {
    // `level:` was in the directive list, was type-checked, and changed no pixel: the title
    // box was drawn at the layout's size whatever it said.
    expect(slideDeck("---\ntitle: Named\nlevel: 3\n---\n\nSome text.\n")->fontSize('title'))
        ->toBe((float) (new SlideGeometry())->headingSize(2));
});

it('leaves a title at the layout size where the frontmatter names no level', function () {
    expect(slideDeck("---\ntitle: Named\n---\n\nSome text.\n")->fontSize('title'))
        ->toBe((float) (new SlideGeometry())->title(LayoutRole::Content)['size']);
});

it('draws a heading that became the title at the level the frontmatter names', function () {
    // The heading is dropped from the body and the title box is what draws it, so the level
    // the frontmatter names is the level it is drawn at — a `level:` that governs nothing
    // here would leave the directive inert on every slide that opens on a heading.
    expect(slideDeck("---\nlevel: 3\n---\n\n# One\n")->fontSize('title'))
        ->toBe((float) (new SlideGeometry())->headingSize(2));
});

it('leaves a heading in the body at its own depth when the frontmatter names a title', function () {
    // Two shapes in two boxes, and `level:` is about the title: the `#` in the body is a
    // block of the slide's own, and a level that moved it too would resize a heading nobody
    // named.
    $pptx = slideDeck("---\ntitle: Named\nlevel: 3\n---\n\n# Body\n");
    $geometry = new SlideGeometry();

    expect($pptx->fontSize('title'))->toBe((float) $geometry->headingSize(2))
        ->and($pptx->fontSize('heading'))->toBe((float) $geometry->headingSize(1));
});

it('draws the heading a blank layout uses as its title at the level named', function () {
    // A blank layout has no title box, so its title is the heading in its body. `level:`
    // governs that one too, or the directive would do nothing at all on the role.
    $geometry = new SlideGeometry();
    $pptx = slideDeck("---\nlayout: blank\nlevel: 3\n---\n\n# One\n");

    expect($pptx->shapeNames())->toBe(['heading'])
        ->and($pptx->fontSize('heading'))->toBe((float) $geometry->headingSize(2));
});

it('refuses a level that is not a positive integer, on a slide built by hand', function () {
    // The parser checks the directive it reads; this is the check for a Slide a caller
    // constructed, the same one `theme` and `layout` get.
    $deck = new Deck('', [new Slide(0, '# One', '# One', ['level' => 'two'], 'One', 1, null, 0, 1)]);
    $path = Scratch::file('deck.pptx', '');

    expect(fn () => (new PresentationRenderer())->render($deck, $path))
        ->toThrow(MalformedFrontmatter::class, 'has a "level" that is not a positive integer');
});

it('puts everything before the second heading in the left column and everything after in the right', function () {
    // The heading is what divides the columns, so it belongs to the column it opens and the
    // text above it stays in the one that was already there.
    $shapes = slideDeck("---\nlayout: two-column\n---\n# Both\n\nLeft.\n\n## Right\n\nRight side.\n")->positions();

    expect(array_map(static fn (array $shape): string => $shape['name'], $shapes))
        ->toBe(['title', 'paragraph', 'heading', 'paragraph'])
        ->and($shapes[1]['x'])->toBeLessThan($shapes[2]['x'])
        ->and($shapes[2]['x'])->toBe($shapes[3]['x']);
});

it('leaves the right column empty for a two-column slide that writes no second heading', function () {
    // It asked for two columns and wrote one, which is honest: the alternative would be
    // guessing which block was meant to be the other column.
    $shapes = slideDeck("---\nlayout: two-column\n---\n# Both\n\nOnly left.\n")->positions();

    expect(array_map(static fn (array $shape): string => $shape['name'], $shapes))
        ->toBe(['title', 'paragraph']);
});

it('keeps both columns of a two-column layout the same height', function () {
    $geometry = new SlideGeometry();

    expect(array_unique(array_map(
        static fn ($box): int => $box->height,
        $geometry->body(LayoutRole::TwoColumn),
    )))->toHaveCount(1);
});

it('writes one slide for a slide with no steps', function () {
    expect(slideDeck("# One\n\nText.\n")->slideCount())->toBe(1);
});

it('writes a slide per step a v-click names', function () {
    // PowerPoint has no "press a key to reveal this", so the build-up becomes the slides a
    // presenter would click through anyway.
    expect(slideDeck("# One\n\nFirst.\n\n<!-- v-click -->\n\nSecond.\n")->slideCount())->toBe(2);
});

it('reveals each step on its own slide and keeps the earlier ones', function () {
    $pptx = slideDeck("# One\n\nFirst.\n\n<!-- v-click -->\n\nSecond.\n");

    expect($pptx->texts(1))->toBe(['One', 'First.'])
        ->and($pptx->texts(2))->toBe(['One', 'First.', 'Second.']);
});

it('writes a numbered step as the step it names', function () {
    $pptx = slideDeck("# One\n\nFirst.\n\n<!-- v-click=3 -->\n\nThird.\n");

    expect($pptx->slideCount())->toBe(3)
        ->and($pptx->texts(3))->toBe(['One', 'First.', 'Third.']);
});

it('names the slide a v-click step is on', function () {
    expect(fn () => slideDeck("# One\n\n<!-- v-click=two -->\n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a v-click step "two"');
});

it('refuses a v-click step that does not go forwards', function () {
    expect(fn () => slideDeck("# One\n\n<!-- v-click=3 -->\n\nText.\n\n<!-- v-click=2 -->\n\nMore.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a v-click step 2 after step 3');
});

it('refuses a v-click equals with no number', function () {
    expect(fn () => slideDeck("# One\n\n<!-- v-click= -->\n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "<!-- v-click= -->" step marker with no number');
});

it('opens a step after the block a marker closes rather than before it', function () {
    // Advancing first would put the block the writer wrote above the marker on the step below
    // it, which is the reverse of what they meant.
    $pptx = slideDeck("# One\n\nFirst.\n<!-- v-click -->\n\nSecond.\n");

    expect($pptx->texts(1))->toBe(['One', 'First.'])
        ->and($pptx->texts(2))->toBe(['One', 'First.', 'Second.']);
});

it('draws a marker on the last line as a step rather than as a note', function () {
    // A marker on the last line runs to the end of the slide, which is what a speaker note
    // does too. Read as a note it took the step with it: nothing on step two, and a notes
    // pane reading "v-click" that a presenter would say out loud.
    $pptx = slideDeck("# One\n\nFirst.\n\n<!-- v-click -->\n\nSecond.\n\n<!-- v-click -->\n");

    expect($pptx->slideCount())->toBe(2)
        ->and($pptx->texts(2))->toBe(['One', 'First.', 'Second.'])
        ->and($pptx->parts())->not->toContain('ppt/notesSlides/notesSlide1.xml');
});

it('refuses a trailing marker it cannot draw rather than writing it in the notes pane', function () {
    // A marker this renderer cannot draw is a failure wherever it stands. Only the notes pane
    // was swallowing it, which turned a mistake into something a presenter would read aloud.
    expect(fn () => slideDeck("# One\n\n<!-- v-click=two -->\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a v-click step "two"');
});

it('gives every step of one source slide the same layout', function () {
    $pptx = slideDeck("---\nlayout: title\n---\n# One\n\nFirst.\n\n<!-- v-click -->\n\nSecond.\n");

    expect($pptx->layoutOf(1))->toBe($pptx->layoutOf(2));
});

it('names every step of a slide after the slide of the deck it came from', function () {
    $pptx = slideDeck("# One\n\nFirst.\n\n<!-- v-click -->\n\nSecond.\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<p:cSld name="slide 1"')
        ->and($pptx->part('ppt/slides/slide2.xml'))->toContain('<p:cSld name="slide 1"');
});

it('counts the steps of every slide towards the file, not of one', function () {
    $pptx = slideDeck("# One\n\nA.\n\n<!-- v-click -->\n\nB.\n\n---\n\n# Two\n\nC.\n\n<!-- v-click -->\n\nD.\n");

    expect($pptx->slideCount())->toBe(4)
        ->and($pptx->slideText(4))->toBe('Two | C. | D.');
});