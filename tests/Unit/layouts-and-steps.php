<?php

declare(strict_types=1);

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Document\Slide;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\TooManySlides;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Exception\UnsupportedColumn;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
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
 * `::click` builds a slide up, and where a role's geometry comes from.
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
        ->and($pptx->fontSize('text'))->toBe((float) $geometry->headingSize(1));
});

it('draws the heading a blank layout uses as its title at the level named', function () {
    // A blank layout has no title box, so its title is the heading in its body. `level:`
    // governs that one too, or the directive would do nothing at all on the role.
    $geometry = new SlideGeometry();
    $pptx = slideDeck("---\nlayout: blank\nlevel: 3\n---\n\n# One\n");

    expect($pptx->shapeNames())->toBe(['text'])
        ->and($pptx->fontSize('text'))->toBe((float) $geometry->headingSize(2));
});

it('draws a title named in the frontmatter on a blank layout, which has no title box', function () {
    // `title:` was accepted, checked as a string and drawn nowhere: the role has no title box
    // and the title was never pushed into the body, so the slide said nothing the deck asked
    // it to say. It is drawn as the heading a blank slide's title already is.
    $pptx = slideDeck("---\nlayout: blank\ntitle: Named\n---\n\nBody.\n");

    expect($pptx->shapeNames())->toBe(['text'])
        ->and($pptx->texts(1))->toBe(['Named', 'Body.']);
});

it('draws a blank layout\'s frontmatter title at the level it names', function () {
    // The same `level:` the body heading of a blank slide is read at, or the directive would
    // govern only the role that happens to have a heading in it.
    $pptx = slideDeck("---\nlayout: blank\ntitle: Named\nlevel: 3\n---\n\nBody.\n");

    expect($pptx->fontSize('text'))->toBe((float) (new SlideGeometry())->headingSize(2));
});

it('draws a blank layout\'s title once, whether it came from the frontmatter or a heading', function () {
    // The heading in the body is that role's title already; adding the frontmatter's on top
    // of it would put the same line on the slide twice, which is what the title box does for
    // every other role.
    $pptx = slideDeck("---\nlayout: blank\ntitle: Named\n---\n\n# Named\n\nBody.\n");

    expect($pptx->shapeNames())->toBe(['text'])
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

it('moves the blocks after a ::column into the next column', function () {
    // The marker is what divides the columns, so the blocks below it belong to the column it
    // opens and the text above it stays in the one that was already there.
    //
    // The text is asserted as well as the geometry, and it is the marker claim: a renderer
    // that has never heard of `::column` puts both paragraphs in the first column and prints
    // the marker on the slide, and the text is what says so.
    $pptx = slideDeck("---\nlayout: two-column\n---\n# Both\n\nLeft.\n\n::column\n\nRight side.\n");
    $shapes = $pptx->positions();

    expect($pptx->texts(1))->toBe(['Both', 'Left.', 'Right side.'])
        ->and(array_map(static fn (array $shape): string => $shape['name'], $shapes))
        ->toBe(['title', 'text', 'text'])
        ->and($shapes[1]['x'])->toBeLessThan($shapes[2]['x']);
});

it('leaves a heading in the column it was written in', function () {
    // A `##` is an ordinary heading now. It used to be the only way to reach the second
    // column, so one written above a `::column` stays with the text above it — which is what
    // a reader of the deck would have to check every one of these slides to find out.
    //
    // The text is asserted with the geometry because a heading is a claim about what is
    // drawn: the positions say which column a shape is in, and only the text says that the
    // shape in it is the heading the deck wrote above the marker.
    $pptx = slideDeck("---\nlayout: two-column\n---\n# Both\n\n## Above\n\nLeft.\n\n::column\n\nRight.\n");
    $shapes = $pptx->positions();

    expect($pptx->texts(1))->toBe(['Both', 'Above', 'Left.', 'Right.'])
        ->and(array_map(static fn (array $shape): string => $shape['name'], $shapes))
        ->toBe(['title', 'text', 'text'])
        ->and($shapes[1]['x'])->toBeLessThan($shapes[2]['x']);
});

it('leaves the right column empty for a two-column slide that writes no ::column', function () {
    // It asked for a layout with two columns and wrote one, which is honest: the alternative
    // would be guessing which block was meant to be the other column.
    $shapes = slideDeck("---\nlayout: two-column\n---\n# Both\n\nOnly left.\n")->positions();

    expect(array_map(static fn (array $shape): string => $shape['name'], $shapes))
        ->toBe(['title', 'text']);
});

it('draws nothing for a ::column nothing was written after, because the layout has the column', function () {
    // The marker is consumed rather than drawn as a paragraph, and the text says so: the
    // literal `::column` on the slide would be a marker the deck drew instead of reading.
    // Whether the slide renders is the layout's question and not this one's — the same marker
    // on a role with one column is refused below, and nothing about the two decks differs
    // except what is written under it.
    $pptx = slideDeck("---\nlayout: two-column\n---\n# Both\n\nLeft.\n\n::column\n");

    expect($pptx->texts(1))->toBe(['Both', 'Left.'])
        ->and($pptx->shapeNames())->toBe(['title', 'text']);
});

it('refuses a ::column nothing was written after, on a layout with too few columns', function () {
    // Measured by what the deck wrote rather than by the block it left behind, because a rule
    // that turned on what follows the last marker of a slide would make a deck's spelling
    // depend on its last line. Both decks here rendered before: one marker past the end of a
    // one-column slide, and two more markers than a two-column layout has columns for.
    expect(fn () => slideDeck("# One\n\nLeft.\n\n::column\n"))
        ->toThrow(UnsupportedColumn::class, 'Slide 1 has a "::column", and the "content" layout has 1 column. '
            . '"::column" moves the blocks after it into the next column, so a slide that uses one needs a '
            . 'layout with more columns than this.')
        ->and(fn () => slideDeck("---\nlayout: two-column\n---\n# One\n\nOne.\n\n::column\n\nTwo.\n\n::column\n\n::column\n"))
        ->toThrow(UnsupportedColumn::class, 'Slide 1 has a "::column", and the "two-column" layout has 2 columns. '
            . '"::column" moves the blocks after it into the next column, so a slide that uses one needs a '
            . 'layout with more columns than this.');
});

it('refuses a ::column on a role with one column, naming the role and what it has', function () {
    expect(fn () => slideDeck("# One\n\nLeft.\n\n::column\n\nRight.\n"))
        ->toThrow(UnsupportedColumn::class, 'Slide 1 has a "::column", and the "content" layout has 1 column. '
            . '"::column" moves the blocks after it into the next column, so a slide that uses one needs a '
            . 'layout with more columns than this.');
});

it('refuses a third ::column on a two-column slide with the same message', function () {
    // The same deck wanting a layout this one cannot be, and not a misspelled marker. One
    // refusal for every way of reaching it: a marker on a one-column role, a third marker on a
    // two-column slide, and either of those at the end of a slide where nothing follows.
    expect(fn () => slideDeck("---\nlayout: two-column\n---\n# Both\n\nOne.\n\n::column\n\nTwo.\n\n::column\n\nThree.\n"))
        ->toThrow(UnsupportedColumn::class, 'Slide 1 has a "::column", and the "two-column" layout has 2 columns. '
            . '"::column" moves the blocks after it into the next column, so a slide that uses one needs a '
            . 'layout with more columns than this.');
});

it('refuses two ::column markers inside one region on a two-column slide', function () {
    // The one case the column leaving a region could have changed: the markers are counted as
    // the walk reaches them and the column leaves the region with them, so a deck that asked
    // for a third column inside a region has asked for one however it was written. It is
    // refused where a third marker at the top level is, with the same message.
    expect(fn () => slideDeck("---\nlayout: two-column\n---\n# Both\n\nLeft.\n\n:::click\n\n::column\n\nTwo.\n\n::column\n\nThree.\n:::\n"))
        ->toThrow(UnsupportedColumn::class, 'Slide 1 has a "::column", and the "two-column" layout has 2 columns. '
            . '"::column" moves the blocks after it into the next column, so a slide that uses one needs a '
            . 'layout with more columns than this.');
});

it('names the slide a ::column the layout cannot offer is on', function () {
    // The slide is in the message because it is the half the author has to go and look at, and
    // a build-up of ten slides has nine other places this could have been written.
    expect(fn () => slideDeck("# One\n\n---\n\n# Two\n\nLeft.\n\n::column\n\nRight.\n"))
        ->toThrow(UnsupportedColumn::class, 'Slide 2 has a "::column", and the "content" layout has 1 column.');
});

it('never reads a ::column as a step, or a ::click as a column', function () {
    // The whole two-colon grammar rests on the pair not colliding, so both directions are asked
    // rather than reasoned about. A `::column` that also opened a step would put the right
    // column's blocks on step two and cost a click the reader spends to see no change.
    //
    // The shapes are what ask the first half: a marker that moved a step draws the same three
    // shapes and still opens no chain on its own, so `reveals()` on its own is satisfied by a
    // parser that has no `::column` in it at all. What is drawn has to be in the test.
    $columns = slideDeck("---\nlayout: two-column\n---\n# One\n\nLeft.\n\n::column\n\nRight.\n");
    $clicked = slideDeck("---\nlayout: two-column\n---\n# One\n\nLeft.\n\n::column\n\nRight.\n\n::click\n\nThird.\n");

    expect($columns->shapeNames(1))->toBe(['title', 'text', 'text'])
        ->and($columns->reveals(1))->toBe([])
        ->and($clicked->reveals(1))->toBe([['text']]);
});

it('keeps a build-up written across two columns on the steps the deck named', function () {
    // Both directions at once: the `::column` moves the block to the right column and moves no
    // step, so the click after it is still the first click the reader spends.
    $pptx = slideDeck("---\nlayout: two-column\n---\n# One\n\nLeft.\n\n::column\n\nRight.\n\n::click\n\nThird.\n");

    expect($pptx->texts(1))->toBe(['One', 'Left.', 'Right.', 'Third.'])
        ->and($pptx->reveals(1))->toBe([['text']])
        ->and($pptx->positions()[2]['x'])->toBeGreaterThan($pptx->positions()[1]['x']);
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

it('splits an image-text slide at its ::column', function () {
    // The marker rather than the role decides, so the layout a deck names is the one it gets:
    // the picture and the text before the marker on the left, everything after it on the right.
    $shapes = slideDeck("---\nlayout: image-text\n---\n# Both\n\nLeft.\n\n::column\n\n## Right\n\nRight side.\n")->positions();

    expect($shapes[1]['x'])->toBeLessThan($shapes[2]['x']);
});

it('gives an image-text layout a title box and a body placeholder for each of its columns', function () {
    $pptx = slideDeck("---\nlayout: image-text\n---\n# One\n\nLeft.\n\n::column\n\nRight side.\n");

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

    expect($pptx->shapeNames())->toBe(['text'])
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
 * A deck of `$slides` slides that each name `$steps` as a step.
 *
 * What a deck looks like when a marker is pushed to the ceiling: every slide is inside
 * {@see BlockTranslator::MAX_STEP} and the deck is over {@see PresentationRenderer::MAX_SLIDES}.
 */
function steppedDeck(int $slides, int $steps): string
{
    $slide = steppedSlide($steps);

    return str_repeat($slide . "\n---\n\n", $slides - 1) . $slide;
}

/**
 * One slide of {@see steppedSlide()}, with `$steps` named as the step its text is on.
 *
 * Split out because the ceiling on a step and a deck of that many slides are two different
 * questions now, and a reader of the second who cannot see the first has to work out that
 * `MAX_STEP` and `MAX_SLIDES` are nothing to do with each other.
 */
function steppedSlide(int $steps): string
{
    return sprintf("# One\n\n::click=%d\n\nText.\n", $steps);
}

/**
 * A deck of `$slides` slides that build up not at all.
 *
 * The deck ceiling, asked of the deck rather than of its markers: one source slide is one
 * written slide, so the only way over it is to write that many slides.
 */
function slidesDeck(int $slides): string
{
    return str_repeat("# One\n\n---\n\n", $slides - 1) . "# One\n";
}

/** A slide whose one `::click` splits it in two, which is the smallest build-up. */
const ONE_STEP = "# One\n\nFirst.\n\n::click\n\nSecond.\n";

it('writes one slide for a slide with no steps', function () {
    expect(slideDeck("# One\n\nText.\n")->slideCount())->toBe(1);
});

it('writes a build-up as one slide rather than one slide per step', function () {
    // The whole of the change: `::click` used to mean "write this slide again with more on
    // it", which put three copies of a slide in the package for a three-step build. It means
    // "reveal this on the next click" now, and the click chain on the one slide is what says so.
    expect(slideDeck(ONE_STEP)->slideCount())->toBe(1);
});

it('puts every step of a build-up on the slide, hidden until their click', function () {
    // The shapes are all there from the start — that is what makes them animatable at all —
    // and the steps a reader sees are read off the click chain rather than off which slides
    // exist. So this asserts both halves: everything is written, and it is revealed in order.
    $pptx = slideDeck(ONE_STEP);

    expect($pptx->texts(1))->toBe(['One', 'First.', 'Second.'])
        ->and($pptx->reveals(1))->toBe([['text']]);
});

it('leaves the first step off the click chain, because nothing hides it', function () {
    // A branch in `<p:timing>` waits for a click. The first step is on the slide before the
    // reader clicks anything, so naming it in a chain would cost a click that reveals a slide
    // which was already showing — which is the same reason the title, written once and never
    // changing, is in no step at all.
    $pptx = slideDeck(ONE_STEP);

    expect($pptx->shapeNames(1))->toBe(['title', 'text', 'text'])
        ->and($pptx->reveals(1))->toBe([['text']]);
});

it('does not spend a click on a step nothing was written on', function () {
    // `::click=3` leaves step two empty. It used to be written as a slide drawn exactly like
    // the one before it, which is a click the reader spends to see no change; the chain drops
    // it instead, so one click reaches the text.
    $pptx = slideDeck("# One\n\nFirst.\n\n::click=3\n\nThird.\n");

    expect($pptx->slideCount())->toBe(1)
        ->and($pptx->reveals(1))->toBe([['text']]);
});

it('gives one shape per click its own box rather than merging the text around it', function () {
    // A frame is one shape and a shape is revealed whole, so a run of paragraphs either side
    // of a marker cannot share one: the second click would bring the first click's text back
    // with it. Two frames of one paragraph each are what a build-up is walked in — and they
    // sit exactly where one frame of both put them, because the gap between two frames is the
    // gap between two paragraphs of a frame and that is the same number either way. The
    // equality below is that claim: the second paragraph starts at the same height either way.
    $one = slideDeck("# One\n\nFirst.\n\nSecond.\n")->positions(1);
    $split = slideDeck(ONE_STEP)->positions(1);

    expect(array_column($one, 'name'))->toBe(['title', 'text'])
        ->and(array_column($split, 'name'))->toBe(['title', 'text', 'text'])
        ->and($split[1]['height'])->toBe($split[2]['height'])
        ->and($one[1]['height'])->toBe($split[1]['height'] + ($split[2]['y'] - $split[1]['y']));
});

it('names the slide a ::click step is on', function () {
    expect(fn () => slideDeck("# One\n\n::click=two\n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a step "two"');
});

it('refuses a ::click step that does not go forwards', function () {
    expect(fn () => slideDeck("# One\n\n::click=3\n\nText.\n\n::click=2\n\nMore.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a step 2 after step 3');
});

it('refuses a ::click equals with no number', function () {
    expect(fn () => slideDeck("# One\n\n::click=\n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "::click=" step marker with no number');
});

it('refuses a ::click step larger than one slide could carry', function () {
    // The measured attack: 16 bytes of deck naming `::click=2147483647` asked for two
    // billion slides, and `::click=99999999999999999999` saturated the cast to
    // `PHP_INT_MAX`, which is the value this refuses rather than trusts. The step number
    // written is the ceiling plus one, not the twenty-digit one, so the suite cannot be the
    // thing that asks for two billion -- and the message quotes the number back rather than
    // the `PHP_INT_MAX` it saturated to.
    expect(fn () => slideDeck(steppedSlide(BlockTranslator::MAX_STEP + 1)))
        ->toThrow(UnknownMarker::class, sprintf('Slide 1 has a step "%d"', BlockTranslator::MAX_STEP + 1));
});

it('refuses a step too large to be held in an integer at all', function () {
    // A twenty-digit number is a different input that reaches the same line, and the same
    // refusal answers both: whatever the cast saturates it to, the deck wrote twenty digits.
    expect(fn () => slideDeck("# One\n\n::click=99999999999999999999\n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a step "99999999999999999999"');
});

it('renders a slide whose build-up is as long as one slide may name', function () {
    // The other side of the ceiling: a build-up that is long is not refused, so the bound
    // cannot be met by dropping steps the deck wrote. It is the number the marker accepts
    // rather than the size of the file that is being checked — a step no longer costs a slide,
    // so this writes one however long the chain is.
    $pptx = slideDeck(steppedSlide(BlockTranslator::MAX_STEP));

    expect($pptx->slideCount())->toBe(1)
        ->and(substr_count($pptx->part(SLIDE_ONE), '<p:cond delay="indefinite"/>'))->toBe(1);
});

it('refuses a deck with more slides than a presentation may carry', function () {
    // One source slide is one written slide, so the deck ceiling is the deck's own slide
    // count: the amplification it used to exist for — forty slides of markers asking for
    // hundreds of written slides out of forty lines — went with a step being an animation
    // rather than a slide. The refusal still comes before the first slide is built.
    expect(fn () => slideDeck(slidesDeck(PresentationRenderer::MAX_SLIDES + 1)))
        ->toThrow(TooManySlides::class, sprintf(
            'This deck asks for %d slides, and one presentation may carry %d.',
            PresentationRenderer::MAX_SLIDES + 1,
            PresentationRenderer::MAX_SLIDES,
        ));
});

it('leaves a deck on the far side of the deck ceiling alone', function () {
    // The boundary again, from the other end: 1000 slides is rendered rather than refused,
    // because a ceiling that refuses the number it names is a limit nobody can rely on.
    expect(slideDeck(slidesDeck(PresentationRenderer::MAX_SLIDES))->slideCount())
        ->toBe(PresentationRenderer::MAX_SLIDES);
});

it('reads a :::click region as one step of its own', function () {
    // The boundary and its fences are one thing: what is between them is read at the step the
    // opener names, through the same counter a bare marker moves, so there is no second
    // numbering path for the three-colon form to drift away from.
    $pptx = slideDeck("# One\n\nFirst.\n\n:::click\n\nSecond.\n\n:::\n\nThird.\n");

    expect($pptx->slideCount())->toBe(1)
        ->and($pptx->texts(1))->toBe(['One', 'First.', 'Second.', 'Third.'])
        ->and($pptx->reveals(1))->toBe([['text']]);
});

it('names the step a region opener names, the way a bare marker does', function () {
    // Step three with nothing on step two is one click, not two: the step nothing was written
    // on is not a click a reader spends, whichever spelling named it.
    $pptx = slideDeck("# One\n\nFirst.\n\n:::click=3\n\nThird.\n\n:::\n");

    expect($pptx->slideCount())->toBe(1)
        ->and($pptx->texts(1))->toBe(['One', 'First.', 'Third.'])
        ->and($pptx->reveals(1))->toBe([['text']]);
});

it('reads two regions as two steps rather than as one', function () {
    $pptx = slideDeck("# One\n\n:::click\n\nSecond.\n\n:::\n\n:::click\n\nThird.\n\n:::\n");

    expect($pptx->slideCount())->toBe(1)
        ->and($pptx->texts(1))->toBe(['One', 'Second.', 'Third.'])
        ->and($pptx->reveals(1))->toBe([['text'], ['text']]);
});

it('moves the step on for a ::click written inside a region', function () {
    // One counter, read once: the region's contents are at the step its opener named, and a
    // marker inside them moves that same counter rather than starting a walk of its own.
    $pptx = slideDeck("# One\n\n:::click\n\nSecond.\n\n::click\n\nThird.\n\n:::\n");

    expect($pptx->slideCount())->toBe(1)
        ->and($pptx->texts(1))->toBe(['One', 'Second.', 'Third.'])
        ->and($pptx->reveals(1))->toBe([['text'], ['text']]);
});

it('never matches a bare ::click as a region, or a :::click as a bare marker', function () {
    // The whole grammar rests on the pair not colliding, so both directions are asked rather
    // than reasoned about: the four lines below differ only in their colon count.
    expect(slideDeck("# One\n\nFirst.\n\n::click\n\nSecond.\n")->reveals(1))->toBe([['text']])
        ->and(slideDeck("# One\n\nFirst.\n\n:::click\n\nSecond.\n\n:::\n")->reveals(1))->toBe([['text']])
        ->and(slideDeck("# One\n\nFirst.\n\n::click\n\nSecond.\n\n:::click\n\nThird.\n\n:::\n")->reveals(1))
        ->toBe([['text'], ['text']]);
});

it('refuses a region nothing closes, rather than reading the rest of the slide as it', function () {
    // Everything after the opener would otherwise be one step, which is a deck that meant
    // three written as one and a file that says so with no complaint anywhere.
    expect(fn () => slideDeck("# One\n\nFirst.\n\n:::click\n\nSecond.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 opens a ":::click" region that nothing closes');
});

it('refuses a ::: that closes nothing, rather than drawing three colons on a slide', function () {
    expect(fn () => slideDeck("# One\n\nFirst.\n\n:::\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a ":::" that closes nothing');
});

it('quotes a marker the way the deck wrote it rather than the way it is spelled here', function () {
    // Every two-colon marker is read in either case, so a refusal that named `::column=2` for
    // a deck that wrote `::Column=2` is a report about a line its author does not have. The
    // argument is not enough to answer for the line either: `MARKER`, `REGION` and `COLUMN`
    // capture what is after the equals and nothing else, so these three factories were each
    // rebuilding a marker rather than being told one — and a region opener that named a
    // step was reported as the bare `:::click` it shares a pattern with.
    expect(fn () => slideDeck("# One\n\nLeft.\n\n::Column=2\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "::Column=2", which is not a column marker')
        ->and(fn () => slideDeck("# One\n\n::Click=\n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "::Click=" step marker with no number')
        ->and(fn () => slideDeck("# One\n\nFirst.\n\n:::Click=3\n\nSecond.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 opens a ":::Click=3" region that nothing closes');
});

it('reports the marker the deck wrote and recommends the one this library reads', function () {
    // The line a refusal reports and the marker it recommends are two different claims, and
    // they take two different spellings. Every two-colon marker is read in either case, so a
    // deck that wrote `::Click=` is told about `"::Click="` — naming `"::click"` would send its
    // author looking for a line they did not write. The advice is a claim about this library's
    // grammar rather than about that file, so it stays canonical whatever was written: advice
    // that echoed the mistake would teach the mistake.
    //
    // Both halves are in the expected text rather than asserted one after the other, because a
    // message that quoted both spellings, or advised both, would satisfy either on its own.
    expect(fn () => slideDeck("# One\n\n::Click=\n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "::Click=" step marker with no number. '
            . 'Write "::click" for the next step.')
        ->and(fn () => slideDeck("# One\n\nLeft.\n\n::Column=2\n\nRight.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "::Column=2", which is not a column marker: the marker '
            . 'takes no argument. Write "::column" on a line of its own')
        ->and(fn () => slideDeck("# One\n\nFirst.\n\n:::Click=3\n\nSecond.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 opens a ":::Click=3" region that nothing closes. '
            . 'A region ends at a ":::" on a line of its own.');
});

it('names a marker the layout cannot offer as advice, never as a line from the deck', function () {
    // The other side of the same line, and the one with no quotation to balance it. This
    // refusal is raised from a count of markers measured against a layout's boxes, so the line
    // the deck wrote is gone by the time the sentence is built — there is nothing here to echo,
    // and threading one would mean carrying the spelling of every marker on the slide through
    // the renderer to name a marker the author already has in their file. A deck that wrote
    // `::Column` is therefore told about `"::column"`, the marker this library documents.
    expect(fn () => slideDeck("# One\n\nLeft.\n\n::Column\n"))
        ->toThrow(UnsupportedColumn::class, 'Slide 1 has a "::column", and the "content" layout has 1 column. '
            . '"::column" moves the blocks after it into the next column')
        ->and(fn () => slideDeck("---\nlayout: two-column\n---\n# One\n\nOne.\n\n::Column\n\nTwo.\n\n::Column\n\n::Column\n"))
        ->toThrow(UnsupportedColumn::class, 'Slide 1 has a "::column", and the "two-column" layout has 2 columns.');
});

it('quotes the whitespace out of a marker, because the writer cannot see it either', function () {
    // `::click=  ` is refused as a marker with no number, and the two trailing spaces are the
    // only difference between it and the `::click=` above. Quoting them would not have made
    // the message more exact — it would have put the disagreement somewhere the reader
    // cannot compare it — so the quote is the trimmed line, which is what is in the file.
    expect(fn () => slideDeck("# One\n\n  ::click=   \n\nText.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "::click=" step marker with no number')
        ->and(fn () => slideDeck("# One\n\n  :::Click  \n\nSecond.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 opens a ":::Click" region that nothing closes');
});

it('quotes a marker without the control characters a deck can put on that line', function () {
    // The spelling in a refusal is the deck's own text, and a marker line is the one place a
    // deck gets to write bytes rather than words. Quoted raw, a carriage return moves the
    // cursor to the start of the line and an escape sequence can clear it — so the
    // author's own marker is what a terminal or a CI log shows, above the sentence explaining
    // what to do about it. Both are dropped rather than escaped: there is no reading of a
    // step number in which a repainted line was more useful than a missing character.
    $hostile = "two\x1b[2J\rthree";

    try {
        slideDeck("# One\n\n::click=" . $hostile . "\n\nText.\n");
        $this->fail('A step marker carrying a control character was not refused.');
    } catch (UnknownMarker $refusal) {
        expect($refusal->getMessage())->toContain('Slide 1 has a step "two[2J"')
            ->and($refusal->getMessage())->not->toContain("\x1b")
            ->and($refusal->getMessage())->not->toContain("\r");
    }
});

it('refuses a ::note nothing closes, rather than taking the rest of the slide as the note', function () {
    // Read as a note it swallows every line after the opener into the notes pane, and a deck
    // whose slides are empty is not one anybody can act on.
    expect(fn () => slideDeck("# One\n\nFirst.\n\n::note\nNever closed.\n"))
        ->toThrow(UnknownDirective::class, 'Slide 1 opens a "::note" that nothing closes');
});

it('refuses an HTML comment, which this format has no use for', function () {
    expect(fn () => slideDeck("# One\n\n<!-- v-click -->\n"))
        ->toThrow(UnknownDirective::class, 'which is not something a slide carries');
});

it('opens a step after the block a marker closes rather than before it', function () {
    // Advancing first would put the block the writer wrote above the marker on the step below
    // it, which is the reverse of what they meant. It shows in which shape the click reveals.
    $pptx = slideDeck("# One\n\nFirst.\n::click\n\nSecond.\n");

    expect($pptx->texts(1))->toBe(['One', 'First.', 'Second.'])
        ->and($pptx->shapeNames(1))->toBe(['title', 'text', 'text']);
});

it('draws a marker on the last line as a step rather than as a note', function () {
    // A marker on the last line runs to the end of the slide, which is what a speaker note
    // does too. Read as a note it took the step with it: nothing on step two, and a notes
    // pane reading "::click" that a presenter would say out loud.
    $pptx = slideDeck("# One\n\nFirst.\n\n::click\n\nSecond.\n\n::click\n");

    expect($pptx->slideCount())->toBe(1)
        ->and($pptx->texts(1))->toBe(['One', 'First.', 'Second.'])
        ->and($pptx->reveals(1))->toBe([['text']])
        ->and($pptx->parts())->not->toContain('ppt/notesSlides/notesSlide1.xml');
});

it('refuses a trailing marker it cannot draw rather than writing it in the notes pane', function () {
    // A marker this renderer cannot draw is a failure wherever it stands. Only the notes pane
    // was swallowing it, which turned a mistake into something a presenter would read aloud.
    expect(fn () => slideDeck("# One\n\n::click=two\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a step "two"');
});

it('binds a slide that builds up to the layout its role names', function () {
    // One slide out per slide in, so the question this used to ask — whether every step of a
    // source slide got the same layout — cannot come up: there is one slide and it has the one
    // its `layout:` named.
    $pptx = slideDeck("---\nlayout: title\n---\n# One\n\nFirst.\n\n::click\n\nSecond.\n");

    expect($pptx->layoutNames()[(int) $pptx->layoutOf(1)])->toBe('Title Slide');
});

it('names a slide after the slide of the deck it came from', function () {
    $pptx = slideDeck(ONE_STEP);

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<p:cSld name="slide 1"');
});

it('gives each slide of a deck its own click chain rather than the first one', function () {
    // The chains are keyed by slide number, so a deck that steps every slide writes a timing
    // onto each of them — and a build-up on slide one must not be written onto slide two,
    // which is a deck whose second slide reveals shapes that are not on it.
    $pptx = slideDeck("# One\n\nA.\n\n::click\n\nB.\n\n---\n\n# Two\n\nC.\n\n::click\n\nD.\n");

    expect($pptx->slideCount())->toBe(2)
        ->and($pptx->slideText(1))->toBe('One | A. | B.')
        ->and($pptx->slideText(2))->toBe('Two | C. | D.')
        ->and($pptx->reveals(1))->toBe([['text']])
        ->and($pptx->reveals(2))->toBe([['text']]);
});
