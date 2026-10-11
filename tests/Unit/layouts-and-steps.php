<?php

declare(strict_types=1);

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Document\Slide;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\TooManySlides;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Render\BlockParser;
use MarkdownPresentation\Render\Box;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Render\TemplateGeometry;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Tests\Support\Doctest;
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

/** The placeholder names a written layout carries, as the layout part spells them. */
const TITLE_SLOT = 'title';
const BODY_ONE_SLOT = 'body 1';
const BODY_TWO_SLOT = 'body 2';

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
    // A theme that maps only some roles would otherwise fail on the ones its deck never asked
    // for, which is not the same failure as a deck asking for one it did not map.
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
            . 'The roles are: title, content, two-column, section, blank, image-text, image-title, image-slide.',
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
            'has a "layout" that is not a layout role — one of: title, content, two-column, section, blank, image-text, image-title, image-slide.',
        );
});

it('refuses a layout that is only whitespace, and names the roles', function () {
    expect(fn () => slideDeck("---\nlayout: \"  \"\n---\n# One\n"))
        ->toThrow(MalformedFrontmatter::class, 'one of: title, content, two-column, section, blank, image-text, image-title, image-slide');
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

it('takes a role\'s title and body from a template where one covers it', function () {
    // A theme that ships a `template.pptx` says where its layouts put their boxes, and those
    // are what the slide is drawn in — measured against, so a narrower box wraps earlier.
    $title = new Box(96, 48, 700, 60);
    $body = new Box(96, 140, 700, 320);
    $geometry = (new SlideGeometry())->with(['content' => ['title' => $title, 'body' => [$body]]]);

    expect($geometry->title(LayoutRole::Content)['box'])->toEqual($title)
        ->and($geometry->body(LayoutRole::Content))->toEqual([$body]);
});

it('leaves the roles a template says nothing about where the library puts them', function () {
    // A template that maps only some layouts is a theme that works, not one that fails on
    // the three its deck never asked for — the rule `layouts.yml` already follows.
    $geometry = (new SlideGeometry())->with(['content' => ['body' => [new Box(0, 0, 10, 10)]]]);

    expect($geometry->body(LayoutRole::TwoColumn))->toHaveCount(2)
        ->and($geometry->title(LayoutRole::Content)['box'])->toEqual(
            (new SlideGeometry())->title(LayoutRole::Content)['box'],
        );
});

it('keeps the type a title is drawn at where the library puts it', function () {
    // A template states its type in a master rather than in a layout, and adopting a whole
    // typographic system would resize the type of every deck that shipped one.
    $geometry = (new SlideGeometry())->with(['title' => ['title' => new Box(0, 0, 10, 10)]]);

    expect($geometry->title(LayoutRole::Title)['size'])->toBe(40)
        ->and($geometry->title(LayoutRole::Title)['alignment'])->toBe('l');
});

it('leaves a blank layout without a title whatever a template\'s blank layout carries', function () {
    // The role is blank because it has no title box, and a template that happens to put a
    // title placeholder on its own blank layout does not turn it into a different role.
    $geometry = (new SlideGeometry())->with(['blank' => ['title' => new Box(0, 0, 10, 10)]]);

    expect($geometry->title(LayoutRole::Blank))->toBeNull();
});

it('keeps the body of a blank layout from where a title would have ended', function () {
    $geometry = new SlideGeometry();

    expect($geometry->body(LayoutRole::Blank)[0]->y)
        ->toBeLessThan($geometry->body(LayoutRole::Content)[0]->y);
});

it('gives the two-column roles two body boxes and every other role one', function () {
    // Two roles divide a slide into columns; everything else stacks one block under another.
    $geometry = new SlideGeometry();
    $one = [LayoutRole::Title, LayoutRole::Content, LayoutRole::Section, LayoutRole::Blank, LayoutRole::ImageTitle, LayoutRole::ImageSlide];

    expect($geometry->body(LayoutRole::TwoColumn))->toHaveCount(2)
        ->and($geometry->body(LayoutRole::ImageText))->toHaveCount(2);

    foreach ($one as $role) {
        expect($geometry->body($role))->toHaveCount(1);
    }
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

it('stops shrinking a heading at the deepest level the scale names', function () {
    // A heading deeper than the scale runs takes the smallest size there is rather than
    // failing, which is what keeps a document CommonMark accepts from being refused.
    $geometry = new SlideGeometry();

    expect($geometry->headingSize(6))->toBe($geometry->headingSize(5))
        ->and($geometry->headingSize(7))->toBe($geometry->headingSize(5));
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

it('draws a title named in the frontmatter on a blank layout, which has no title box', function () {
    // `title:` was accepted, checked as a string and drawn nowhere: the role has no title box
    // and the title was never pushed into the body, so the slide said nothing the deck asked
    // it to say. It is drawn as the heading a blank slide's title already is.
    $pptx = slideDeck("---\nlayout: blank\ntitle: Named\n---\n\nBody.\n");

    expect($pptx->shapeNames())->toBe(['heading', 'text'])
        ->and($pptx->texts(1))->toBe(['Named', 'Body.']);
});

it('draws a blank layout\'s frontmatter title at the level it names', function () {
    // The same `level:` the body heading of a blank slide is read at, or the directive would
    // govern only the role that happens to have a heading in it.
    $pptx = slideDeck("---\nlayout: blank\ntitle: Named\nlevel: 3\n---\n\nBody.\n");

    expect($pptx->fontSize('heading'))->toBe((float) (new SlideGeometry())->headingSize(2));
});

it('draws a blank layout\'s title once, whether it came from the frontmatter or a heading', function () {
    // The heading in the body is that role's title already; adding the frontmatter's on top
    // of it would put the same line on the slide twice, which is what the title box does for
    // every other role.
    $pptx = slideDeck("---\nlayout: blank\ntitle: Named\n---\n\n# Named\n\nBody.\n");

    expect($pptx->shapeNames())->toBe(['heading', 'text'])
        ->and($pptx->texts(1))->toBe(['Named', 'Body.']);
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
        ->toBe(['title', 'text', 'heading', 'text'])
        ->and($shapes[1]['x'])->toBeLessThan($shapes[2]['x'])
        ->and($shapes[2]['x'])->toBe($shapes[3]['x']);
});

it('leaves the right column empty for a two-column slide that writes no second heading', function () {
    // It asked for two columns and wrote one, which is honest: the alternative would be
    // guessing which block was meant to be the other column.
    $shapes = slideDeck("---\nlayout: two-column\n---\n# Both\n\nOnly left.\n")->positions();

    expect(array_map(static fn (array $shape): string => $shape['name'], $shapes))
        ->toBe(['title', 'text']);
});

it('keeps both columns of a two-column layout the same height', function () {
    $geometry = new SlideGeometry();

    expect(array_unique(array_map(
        static fn ($box): int => $box->height,
        $geometry->body(LayoutRole::TwoColumn),
    )))->toHaveCount(1);
});

/*
 * The three picture layouts. Each is a role the template can carry rather than drawing of its
 * own, so what is asserted is what reaches the file: the layout part is named the way the
 * theme names it, it carries the placeholders for the boxes the slides are drawn in, and the
 * boxes are the ones {@see SlideGeometry} puts the role at.
 */

it('divides an image-text layout into a picture column and a text column', function () {
    // The claim that makes it an image layout rather than a two-column one: the picture gets
    // the wider column, because the reader looks at it first.
    [$picture, $text] = (new SlideGeometry())->body(LayoutRole::ImageText);

    expect($picture->width)->toBeGreaterThan($text->width)
        ->and($picture->x + $picture->width)->toBeLessThan($text->x);
});

it('leaves an image-text layout\'s text column wide enough for the estimate to be wrong', function () {
    // Both of a picture's columns are laid out from an estimate of how many lines its text wraps
    // to, and the estimate reads a table of Helvetica's widths against a face that is usually
    // wider. Where the estimate is short the two paragraphs are drawn on top of one another, and
    // LibreOffice's preview does not show it — PowerPoint's own render does.
    [, $text] = (new SlideGeometry())->body(LayoutRole::ImageText);

    // The ratio is 5:4 rather than the 3:2 a picture would prefer, and this is what holds it
    // there. {@see ShapeWriter} lays a column out from a table of Helvetica's widths and a
    // theme's own face is wider than Helvetica, so the text column has to be wide enough that
    // being wrong by a word does not put a paragraph on top of the one above it.
    //
    // Five ninths of what the two columns share is a shade under half the body, and that is
    // the number: a 3:2 split would leave the text on two fifths, which is where the overlap
    // was found. Stated against the share rather than as a width in pixels so that the bound
    // moves with the theme's margin rather than becoming a number chosen after the fact.
    expect($text->width * 5)->toBeGreaterThan(
        (new SlideGeometry())->body(LayoutRole::Content)[0]->width * 2,
    );
});

it('separates the columns of an image-text layout by the same gutter as everything else', function () {
    // A gap of its own would be a second indentation to keep in step with this one.
    [$picture, $text] = (new SlideGeometry())->body(LayoutRole::ImageText);

    expect($text->x - $picture->x - $picture->width)->toBe(SlideGeometry::GUTTER);
});

it('gives the two columns of an image-text layout the same height and the same top', function () {
    [$picture, $text] = (new SlideGeometry())->body(LayoutRole::ImageText);

    expect($picture->height)->toBe($text->height)
        ->and($picture->y)->toBe($text->y);
});

it('splits an image-text slide at the first heading of the second level', function () {
    // The same rule `two-column` divides by, so the layout a deck names is the one it gets:
    // picture and text before the `##` on the left, everything after it on the right.
    $shapes = slideDeck("---\nlayout: image-text\n---\n# Both\n\nLeft.\n\n## Right\n\nRight side.\n")->positions();

    expect($shapes[1]['x'])->toBeLessThan($shapes[2]['x'])
        ->and($shapes[2]['x'])->toBe($shapes[3]['x']);
});

it('gives an image-text layout a title box and a body placeholder for each of its columns', function () {
    $pptx = slideDeck("---\nlayout: image-text\n---\n# One\n\nLeft.\n\n## Right\n\nRight side.\n");

    expect($pptx->layoutNames())->toBe([1 => 'Picture Left'])
        ->and(array_keys($pptx->layoutPlaceholders()))->toBe([TITLE_SLOT, BODY_ONE_SLOT, BODY_TWO_SLOT]);
});

it('gives an image-title layout a headline and a body tall enough to be worth looking at', function () {
    // A picture that is only as tall as a content slide's body would make the role a content
    // slide with an image in it; the headline is what the layout is for.
    $geometry = new SlideGeometry();
    [$body] = $geometry->body(LayoutRole::ImageTitle);

    expect($geometry->title(LayoutRole::ImageTitle))->not->toBeNull()
        ->and($body->height)->toBeGreaterThan($geometry->body(LayoutRole::Content)[0]->height);
});

it('writes an image-title layout named the way the theme names it, with a title placeholder', function () {
    $pptx = slideDeck("---\nlayout: image-title\n---\n# One\n\nText.\n");

    expect($pptx->layoutNames())->toBe([1 => 'Picture with Caption'])
        ->and(array_keys($pptx->layoutPlaceholders()))->toBe([TITLE_SLOT, BODY_ONE_SLOT])
        ->and($pptx->placeholderOf('title'))->toBe('type="title"');
});

it('gives an image slide the whole page, with no margin to keep', function () {
    // Full bleed, which is the whole of what separates this role from `blank`: the picture
    // goes to the edge of the page rather than stopping at the margin.
    [$body] = (new SlideGeometry())->body(LayoutRole::ImageSlide);

    expect($body->x)->toBe(0)
        ->and($body->y)->toBe(0)
        ->and($body->width)->toBe(SlideGeometry::WIDTH)
        ->and($body->height)->toBe(SlideGeometry::HEIGHT);
});

it('leaves an image slide with no title box, whatever its layout is called', function () {
    // A picture alone is the layout; a headline drawn over it would not be the picture alone,
    // and the template's own layout naming it is not a reason to draw one.
    expect((new SlideGeometry())->title(LayoutRole::ImageSlide))->toBeNull();
});

it('writes an image slide whose layout carries only a body placeholder', function () {
    $pptx = slideDeck("---\nlayout: image-slide\n---\n\nJust a picture.\n");

    expect($pptx->layoutNames())->toBe([1 => 'Full Bleed Picture'])
        ->and(array_keys($pptx->layoutPlaceholders()))->toBe([BODY_ONE_SLOT]);
});

it('draws a title named in the frontmatter as a heading on an image slide', function () {
    // The same answer `blank` gives, because there is no box to draw it in either way.
    $pptx = slideDeck("---\nlayout: image-slide\ntitle: Named\n---\n\nBody.\n");

    expect($pptx->shapeNames())->toBe(['heading', 'text'])
        ->and($pptx->texts(1))->toBe(['Named', 'Body.']);
});

it('answers the title box question the same way whichever side asks it', function () {
    // Two places answer it — the role and the geometry — and a disagreement between them would
    // draw a title a role says it has no box for.
    foreach (LayoutRole::cases() as $role) {
        expect((new SlideGeometry())->title($role) !== null)->toBe($role->hasTitleBox());
    }
});

it('leaves a template that covers the picture layouts free to place them', function () {
    // The precedence rule, on the roles that carry pictures: a template's *Picture Left* says
    // where its two columns are, and the deck is drawn there rather than where
    // {@see SlideGeometry} would put them.
    $geometry = (new TemplateGeometry(Doctest::root() . '/tests/fixtures/themes/templated/template.pptx'))->boxes();
    [$picture, $text] = $geometry['Picture Left']['body'];

    expect($picture->x)->toBeLessThan($text->x)
        ->and($geometry['Picture Left']['title']->y)->toBeLessThan($picture->y);
});

it('takes a picture placeholder as one of a template\'s columns', function () {
    // A template's picture is not a `body` placeholder, and reading only the ones called
    // `body` would take its text column and leave its picture column behind.
    expect((new TemplateGeometry(Doctest::root() . '/tests/fixtures/themes/templated/template.pptx'))->boxes()['Picture Left']['body'])
        ->toHaveCount(2);
});

it('leaves a full-bleed picture where the library puts it rather than where the template does', function () {
    // The template's own full-bleed layout carries a title placeholder, and adopting it would
    // give a role with no title box one — the same rule `blank` already follows.
    $geometry = (new SlideGeometry())->with([
        'image-slide' => ['title' => new Box(0, 0, 10, 10), 'body' => [new Box(0, 0, 10, 10)]],
    ]);

    expect($geometry->title(LayoutRole::ImageSlide))->toBeNull();
});

/**
 * A deck of `$slides` slides that each build up to `$steps`.
 *
 * What a deck uses to get past a ceiling on one slide: every slide is inside it and the
 * deck is over it.
 */
function steppedDeck(int $slides, int $steps): string
{
    $slide = steppedSlide($steps);

    return str_repeat($slide . "\n---\n\n", $slides - 1) . $slide;
}

/**
 * One slide of {@see steppedDeck()}, with `$steps` numbered steps in it.
 *
 * Split out because the ceiling on a step and the deck written over the ceiling are the
 * same slide at two different numbers, and a reader of the second who cannot see the first
 * has to work out that `MAX_STEP` is the same shape as the `2` above it.
 */
function steppedSlide(int $steps): string
{
    return sprintf("# One\n\n<!-- v-click=%d -->\n\nText.\n", $steps);
}

/** A slide whose one `<!-- v-click -->` splits it in two, which is the smallest build-up. */
const ONE_STEP = "# One\n\nFirst.\n\n<!-- v-click -->\n\nSecond.\n";

it('writes one slide for a slide with no steps', function () {
    expect(slideDeck("# One\n\nText.\n")->slideCount())->toBe(1);
});

it('writes a slide per step a v-click names', function () {
    // PowerPoint has no "press a key to reveal this", so the build-up becomes the slides a
    // presenter would click through anyway.
    expect(slideDeck(ONE_STEP)->slideCount())->toBe(2);
});

it('reveals each step on its own slide and keeps the earlier ones', function () {
    $pptx = slideDeck(ONE_STEP);

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

it('refuses a v-click step larger than one slide could carry', function () {
    // The measured attack: 32 bytes of deck naming `v-click=2147483647` asked for two
    // billion slides, and `v-click=99999999999999999999` saturates the cast to
    // `PHP_INT_MAX` and hangs the process rather than failing it. The step number written is
    // the ceiling plus one, not the twenty-digit one, so the suite cannot be the thing that
    // hangs -- and the message quotes the number back rather than the `PHP_INT_MAX` it
    // saturated to.
    expect(fn () => slideDeck(steppedSlide(BlockParser::MAX_STEP + 1)))
        ->toThrow(UnknownMarker::class, sprintf('Slide 1 has a v-click step "%d"', BlockParser::MAX_STEP + 1));
});

it('refuses a step too large to be held in an integer at all', function () {
    // A twenty-digit number is a different input that reaches the same line, and the same
    // refusal answers both: whatever the cast saturates it to, the deck wrote twenty digits.
    expect(fn () => slideDeck("# One\n\n<!-- v-click=99999999999999999999 -->\n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a v-click step "99999999999999999999"');
});

it('renders a slide whose build-up is as long as one slide may carry', function () {
    // The other side of the ceiling: a build-up that is long is not refused, so the bound
    // cannot be met by drawing fewer steps than the deck wrote. Bounded on the way there,
    // because the point is the number the marker accepts rather than the size of the file.
    expect(slideDeck(steppedSlide(BlockParser::MAX_STEP))->slideCount())
        ->toBe(BlockParser::MAX_STEP);
});

it('refuses a deck with more slides than a presentation may carry', function () {
    // BlockParser::MAX_STEP bounds one slide, so a deck gets past it by having several:
    // three slides of 400 steps each is under the per-slide ceiling 1200 times over and
    // 1200 written slides out of about 60 bytes of Markdown. The refusal comes before the
    // first slide is built, so no file is written.
    expect(fn () => slideDeck(steppedDeck(3, 400)))
        ->toThrow(TooManySlides::class, 'This deck asks for 1200 slides, and one presentation may carry 1000');
});

it('leaves a deck on the far side of the deck ceiling alone', function () {
    // The boundary again, from the other end: 1000 slides is rendered rather than refused,
    // because a ceiling that refuses the number it names is a limit nobody can rely on.
    expect(slideDeck(steppedDeck(2, 500))->slideCount())->toBe(PresentationRenderer::MAX_SLIDES);
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
    $pptx = slideDeck(ONE_STEP);

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<p:cSld name="slide 1"')
        ->and($pptx->part('ppt/slides/slide2.xml'))->toContain('<p:cSld name="slide 1"');
});

it('counts the steps of every slide towards the file, not of one', function () {
    $pptx = slideDeck("# One\n\nA.\n\n<!-- v-click -->\n\nB.\n\n---\n\n# Two\n\nC.\n\n<!-- v-click -->\n\nD.\n");

    expect($pptx->slideCount())->toBe(4)
        ->and($pptx->slideText(4))->toBe('Two | C. | D.');
});
