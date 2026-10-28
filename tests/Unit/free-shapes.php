<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception as MarkdownException;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Render\CommonMark\InlineTranslator;
use MarkdownPresentation\Render\Free\FreeAutoShape;
use MarkdownPresentation\Render\Free\FreePicture;
use MarkdownPresentation\Render\Free\FreeShape;
use MarkdownPresentation\Render\Free\FreeShapes;
use MarkdownPresentation\Render\Free\FreeSlideNumber;
use MarkdownPresentation\Render\Free\FreeText;
use MarkdownPresentation\Render\Free\ShapeKind;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SlideBlocks;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Theme\Theme;
use MarkdownPresentation\Theme\ThemeResolver;

/**
 * The free-placement directives: a shape a deck puts itself rather than one the layout puts.
 *
 * Split three ways because there are three things to be wrong about. What a deck writes is
 * read into shapes — names, attributes, measurements, refusals — and that is checked against
 * the parser alone, because a parser is useful on its own. What a shape becomes in the file is
 * checked against the parts, because there is no other implementation to check a render
 * against and comparing two `.pptx` bytes would only prove that two zips differ. And the two
 * rules that hold the whole thing together — one directive is one shape, and a placed shape
 * does not disturb the layout's own — are checked on their own because neither is visible in
 * any single case.
 */

/** A deck whose first slide carries whatever the test wrote and nothing else. */
function freeShapeDeck(string $markdown): SlideBlocks
{
    return freeShapeParse($markdown);
}

/**
 * One slide of Markdown read into blocks and placed shapes.
 *
 * The theme is the library's own because a free shape may be written in its colours, and
 * resolving one needs the theme the deck named — see {@see Theme}.
 */
function freeShapeParse(string $markdown): SlideBlocks
{
    return freeShapeRead($markdown, Scratch::directory());
}

/**
 * The same, with the directory a deck's relative picture path resolves against.
 */
function freeShapeRead(string $markdown, string $directory): SlideBlocks
{
    return (new BlockTranslator())->parse(
        $markdown,
        1,
        $directory,
        new PathScope([$directory]),
        Theme::defaults(),
    );
}

/**
 * The shapes of one slide, read through the parser.
 *
 * @return list<FreeShape>
 */
function placedShapes(string $markdown): array
{
    return freeShapeParse($markdown)->shapes();
}

/** The one shape of a slide, which is what every test here writes. */
function placedShape(string $markdown): FreeShape
{
    $shapes = placedShapes($markdown);

    expect($shapes)->toHaveCount(1);

    return $shapes[0];
}

/** A rendered deck of one slide, opened for reading its parts. */
function freeShapeDeckFile(string $markdown): Pptx
{
    $path = Scratch::file('free.pptx', '');

    (new PresentationRenderer(directory: Scratch::directory()))
        ->render((new DeckParser())->parse($markdown), $path);

    return Pptx::open($path);
}

/** A one-pixel PNG, written where a deck may read a picture from. */
function freeShapePicture(): string
{
    return Scratch::file('logo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    ));
}

it('reads a free shape as a shape rather than as a block', function () {
    // The whole distinction: a placed shape takes no column, is not measured against the
    // layout's box and is carried beside the blocks rather than among them.
    $content = freeShapeDeck("::rectangle{left=10% top=20% width=40% height=30%}\n");

    expect($content->blocks())->toBe([])
        ->and($content->shapes())->toHaveCount(1)
        ->and(placedShape("::rectangle{}\n")->kind)->toBe(ShapeKind::Rectangle);
});

it('reads every name in the vocabulary', function (string $name) {
    // One case per name rather than a loop over the enum, so a case added to the enum without
    // a body here fails rather than passing untested. `content` is a container and carries a
    // body, because that is the only shape in the vocabulary that has one.
    $shape = $name === 'content'
        ? placedShape(":::content\nWords.\n:::\n")
        : placedShape(sprintf("::%s{}\n", $name));

    expect($shape->kind->value)->toBe($name)
        ->and($shape->name())->toBe($name);
})->with([
    'rectangle', 'roundrect', 'ellipse', 'circle', 'triangle',
    'diamond', 'chevron', 'hexagon', 'arrow', 'line', 'content', 'slide-number',
]);

it('names a directive it does not draw, and every name it does', function () {
    // The point of refusing rather than ignoring: the message is the fix, and it is the only
    // place a deck author can read what the vocabulary is.
    expect(fn () => placedShapes("::rect{a=1}\n"))
        ->toThrow(
            UnknownDirective::class,
            'The free shapes are: arrow, chevron, circle, content, diamond, ellipse, hexagon, '
            . 'image, line, rectangle, roundrect, slide-number, triangle.',
        );
});

it('refuses a container fence it cannot draw, and names the one container it can', function () {
    // Every three-colon line whose name it does not draw stays a marker, so this is where a
    // fence is refused — and it has to say what a placed text block looks like, or a deck that
    // wrote `:::content` is told the three colons do something else entirely.
    expect(fn () => placedShapes("::: note\nSomething.\n:::\n"))
        ->toThrow(UnknownDirective::class, 'a placed text block is ":::content" with its Markdown')
        ->and(fn () => placedShapes("::: {.panel}\nSomething.\n:::\n"))
        ->toThrow(UnknownDirective::class, 'opens the container "::: {.panel}"');
});

it('carries the flag that writes the number of the deck after the number of the slide', function () {
    // The one attribute of the page number that is not a placement: it says whether the
    // number of the deck follows the number of the slide.
    $shape = placedShape("::slide-number{of=true}\n");

    expect($shape)->toBeInstanceOf(FreeSlideNumber::class)
        ->and($shape->of)->toBeTrue();
});

it('refuses an `of` that is neither true nor false', function () {
    // Every other value in this vocabulary is refused with a sentence, and this one was the
    // exception: `getBool()` answers false for a word it does not know, so `of=maybe` was a
    // page number with no deck count on it and nothing said so.
    expect(fn () => placedShapes("::slide-number{of=maybe}\n"))
        ->toThrow(MalformedShape::class, 'A flag is true or false: of=true, of=false.');
});

it('reads a bare `of` as the flag it asks for', function () {
    // `{bold}` on a text style means bold, and a bare attribute asks for the attribute
    // rather than for its value — which is the flag.
    expect(placedShape("::slide-number{of}\n")->of)->toBeTrue()
        ->and(placedShape("::slide-number{of=false}\n")->of)->toBeFalse()
        ->and(placedShape("::slide-number{}\n")->of)->toBeFalse();
});

it('places a size as a fraction of the slide on each of its axes', function () {
    // 40% of 960 and 30% of 540 — the two axes differ, which is why a percentage is resolved
    // against the one it names.
    $box = placedShape("::rectangle{left=10% top=20% width=40% height=30%}\n")->placement->box;

    expect([$box->x, $box->y, $box->width, $box->height])->toBe([96, 108, 384, 162]);
});

it('places a size as pixels, and a length in points as points', function () {
    // Two readings of a bare number, and this is the difference: a position is pixels and a
    // size of type is points, because that is what each is written in everywhere else here.
    $box = placedShape("::rectangle{left=40 top=20 width=320 height=200}\n")->placement->box;
    $shape = placedShape(":::content{left=0 top=0 width=100 height=100 font-size=24}\nText.\n:::\n");

    expect([$box->x, $box->width])->toBe([40, 320])
        ->and($shape)->toBeInstanceOf(FreeText::class)
        ->and($shape->style->size)->toBe(24);
});

it('converts a percentage of nothing into a refusal rather than a number', function () {
    // There is no axis a stroke's width is a fraction of, and resolving one against the slide
    // anyway would be a number nobody asked for.
    expect(fn () => placedShapes("::rectangle{stroke-width=50%}\n"))
        ->toThrow(MalformedShape::class, 'is a number of points or of pixels, not a percentage');
});

it('refuses a length that is not a number, and says the three spellings', function () {
    expect(fn () => placedShapes("::rectangle{left=blue}\n"))
        ->toThrow(MalformedShape::class, 'left=40%, left=320, font-size=24pt');
});

it('names the separator where the attributes were written with commas', function () {
    // A value runs to the next space, so `left=10%, top=20%` is one value of `10%,` and
    // one attribute nobody reads. A message that blames the value sends the author to
    // change a number that was right.
    expect(fn () => placedShapes("::rectangle{left=10%, top=20%}\n"))
        ->toThrow(MalformedShape::class, 'Attributes are separated by a space, not a comma');
});

it('names the separator where the attributes were written with commas and nothing else', function () {
    // The other spelling of the same mistake: nothing separates the two attributes, so
    // the first value swallows the second.
    expect(fn () => placedShapes("::rectangle{fill=red,stroke=blue}\n"))
        ->toThrow(MalformedShape::class, 'Attributes are separated by a space, not a comma');
});

it('still says what a length may be where the separator was wrong', function () {
    // The separator is the mistake, and the value is what a length has to be as well: a
    // message that named only one of them would leave the author with half the rule.
    expect(fn () => placedShapes("::rectangle{left=10%, top=20%}\n"))
        ->toThrow(MalformedShape::class, 'left=40%, left=320, font-size=24pt');
});

it('leaves a list of attributes separated by spaces alone', function () {
    expect(placedShape("::rectangle{left=10% top=20% width=50% height=20%}\n")->kind)
        ->toBe(ShapeKind::Rectangle);
});

it('refuses a negative length, because a free shape lies within the slide', function () {
    expect(fn () => placedShapes("::rectangle{left=-40}\n"))
        ->toThrow(MalformedShape::class, 'A length is not negative');
});

it('refuses a bare attribute, which asks for the attribute rather than its value', function () {
    // `{width}` is the RFC's spelling of "this attribute is present". Answering it with the
    // default would draw a full-bleed shape out of what was a typo.
    expect(fn () => placedShapes("::rectangle{width}\n"))
        ->toThrow(MalformedShape::class, 'A bare attribute asks for the attribute rather than for its value');
});

it('refuses a size with no room in it, because that is one PowerPoint repairs the file over', function () {
    // `a:ext` is an `ST_PositiveCoordinate`: a zero width is not a thin shape but an invalid
    // one.
    expect(fn () => placedShapes("::rectangle{width=0}\n"))
        ->toThrow(MalformedShape::class, 'A size is at least 1px');
});

it('anchors a box at the corner the anchor names', function (string $anchor, int $x, int $y) {
    // Nine anchors, each answered by where the box lands rather than by the word: `left=50%`
    // is the middle of the slide under `anchor=center` and the left edge of it under `nw`.
    expect(placedShape(sprintf("::rectangle{left=50%% top=50%% width=20%% height=20%% anchor=%s}\n", $anchor))
        ->placement->box)
        ->toMatchArray(['x' => $x, 'y' => $y]);
})->with([
    ['nw', 480, 270],
    ['n', 384, 270],
    ['ne', 288, 270],
    ['e', 288, 216],
    ['se', 288, 162],
    ['s', 384, 162],
    ['sw', 480, 162],
    ['w', 480, 216],
    ['center', 384, 216],
]);

it('refuses an anchor it does not have, and names the nine it does', function () {
    expect(fn () => placedShapes("::rectangle{anchor=middle}\n"))
        ->toThrow(MalformedShape::class, 'center, e, n, ne, nw, s, se, sw, w');
});

it('refuses a layer it does not have, and names the two it does', function () {
    expect(fn () => placedShapes("::rectangle{layer=under}\n"))
        ->toThrow(MalformedShape::class, 'A layer is one of: back, front.');
});

it('refuses a box past the edge of the slide, with the numbers that put it there', function () {
    // Clamped instead, this would be a shape quietly drawn somewhere the author did not ask
    // for, and nothing in the file would say so.
    expect(fn () => placedShapes("::rectangle{left=900 width=120}\n"))
        ->toThrow(
            MalformedShape::class,
            'Slide 1 places "rectangle" at left 900px with a width of 120px, which reaches 1020px. '
            . 'A free shape lies within the 960px wide slide: write left=0 width=100% for one that reaches the edge.',
        );
});

it('refuses a box above or to the left of the slide as well', function () {
    expect(fn () => placedShapes("::rectangle{left=900 width=100}\n"))
        ->toThrow(MalformedShape::class, '960px wide slide')
        ->and(fn () => placedShapes("::rectangle{top=500 height=100}\n"))
        ->toThrow(MalformedShape::class, '540px tall slide');
});

it('reaches the edge exactly, because that is what full bleed is', function () {
    // The refusal above would be unusable if `left=0 width=100%` were also refused, and it is
    // the one spelling of a full-bleed panel.
    $box = placedShape("::rectangle{left=0 top=0 width=100% height=100%}\n")->placement->box;

    expect([$box->x, $box->y, $box->width, $box->height])->toBe([0, 0, 960, 540]);
});

it('makes a circle out of one size', function () {
    // One size and the other follows it, which is what a circle means.
    expect(placedShape("::circle{width=80}\n")->placement->box)
        ->toMatchArray(['width' => 80, 'height' => 80]);
});

it('resolves both of a circle\'s percentages against one axis, so it is square', function () {
    // Resolved against their own axes they would be 96 by 54 — an ellipse that reads as a
    // mistake rather than as a circle, from the spelling that was most obviously meant.
    expect(placedShape("::circle{width=10% height=10%}\n")->placement->box)
        ->toMatchArray(['width' => 54, 'height' => 54]);
});

it('refuses a circle given two sizes that are not the same size', function () {
    // Squaring it silently would draw something other than the box the deck wrote, and the
    // reader would have no way of telling which of the two numbers was ignored.
    expect(fn () => placedShapes("::circle{width=120 height=80}\n"))
        ->toThrow(MalformedShape::class, 'give one size or the other');
});

it('gives a circle with no size the biggest one the slide\'s height allows', function () {
    // 100% of 960 by 100% of 540 would be 420px too wide and would be refused for reaching past
    // the right edge, which is not what a shape with no size is.
    expect(placedShape("::circle{}\n")->placement->box)
        ->toMatchArray(['width' => 540, 'height' => 540]);
});

it('paints a fill and a stroke from the theme\'s own colours', function () {
    // `panel` is the panel a code block gets and `accent1` the accent a rule is drawn in, so a
    // free shape and a laid-out one are the same colour on the same theme.
    $paint = placedShape("::rectangle{fill=panel stroke=accent1}\n")->paint;

    expect($paint->fill?->getRGB())->toBe('E7E6E6')
        ->and($paint->stroke?->getRGB())->toBe('4472C4');
});

it('resolves the four names a shape plays rather than the slot behind them', function (string $role, string $hex) {
    expect(placedShape(sprintf("::rectangle{fill=%s}\n", $role))->paint->fill?->getRGB())->toBe($hex);
})->with([
    ['page', 'FFFFFF'],
    ['ink', '000000'],
    ['subtle', '44546A'],
    ['panel', 'E7E6E6'],
]);

it('takes a colour as hex, with or without the # an author writes it with', function (string $written) {
    expect(placedShape(sprintf("::rectangle{fill=%s}\n", $written))->paint->fill?->getRGB())->toBe('4C1D95');
})->with([['#4C1D95'], ['4C1D95']]);

it('has no fill or stroke where the deck named none', function () {
    // `none` is a value rather than an absence: the first is drawn `a:noFill` and the second
    // would inherit whatever the theme gives it, and a free shape has nothing to inherit from.
    $paint = placedShape("::rectangle{}\n")->paint;

    expect($paint->fill)->toBeNull()
        ->and($paint->stroke)->toBeNull();
});

it('refuses a colour name nothing is', function (string $attribute) {
    expect(fn () => placedShapes(sprintf("::rectangle{%s=chartreuse}\n", $attribute)))
        ->toThrow(MalformedShape::class, 'A colour is either no colour at all (none or transparent), a role (page, ink, subtle, panel), a theme slot');
})->with([['fill'], ['stroke']]);

it('accepts a colour written as one of the names the concept draft used', function () {
    // `dark1` is the concept draft's name for `dk1`, and a deck that names a colour the old
    // way means the same colour: the spelling is not the thing being refused.
    expect(placedShape("::rectangle{fill=dark1}\n")->paint->fill)->not->toBeNull();
});

it('names the names the concept draft used in the advice for a colour nothing is', function () {
    // The advice is what a reader is shown when they get a colour wrong, and it has to name
    // every colour that would have been right — including the six the concept draft used,
    // which are names like any other.
    expect(fn () => placedShapes("::rectangle{fill=chartreuse}\n"))
        ->toThrow(MalformedShape::class, 'dark1, light1, dark2, light2, hyperlink, followedHyperlink');
});

it('leaves a colour whole where the deck named no alpha', function () {
    // Null rather than 100, so that an opaque shape is written with the six characters of
    // colour every other shape on the slide has rather than an `<a:alpha>` that changes
    // nothing.
    expect(placedShape("::rectangle{fill=accent1 alpha=100}\n")->paint->fill?->getRGB())->toBe('4472C4');
});

it('refuses an alpha that is not a percentage of one', function (string $written) {
    expect(fn () => placedShapes(sprintf("::rectangle{fill=accent1 alpha=%s}\n", $written)))
        ->toThrow(MalformedShape::class, 'An alpha is a whole number of percent from 0 to 100');
})->with([['dark'], ['150'], ['1.5']]);

it('reads a stroke width in points, which is what an outline is asked for', function () {
    expect(placedShape("::rectangle{stroke=accent1 stroke-width=2}\n")->paint->strokeWidth)->toBe(2.0)
        ->and(placedShape("::rectangle{stroke=accent1 stroke-width=2px}\n")->paint->strokeWidth)
        ->toBe(1.5);
});

it('rounds a corner only on a shape that has corners', function () {
    expect(placedShape("::roundrect{border-radius=12}\n")->paint->radius)->toBe(12)
        ->and(placedShape("::roundrect{}\n")->paint->radius)->toBe(0);
});

it('refuses a corner radius on a shape that has none, and names the one that does', function () {
    expect(fn () => placedShapes("::rectangle{border-radius=12}\n"))
        ->toThrow(MalformedShape::class, 'Write it as "::roundrect".');
});

it('refuses an attribute the directive does not read, and names the ones it does', function () {
    // The one mistake in this vocabulary that would otherwise leave no trace: a shape drawn
    // without the fill that was asked for, on a deck that looks rendered.
    expect(fn () => placedShapes("::image{src=\"logo.png\" padding=8}\n"))
        ->toThrow(MalformedShape::class, 'The attributes are: alt, anchor, fit, height, layer, left, rotate, shadow-alpha, shadow-blur, shadow-color, shadow-direction, shadow-distance, src, top, width.');
});

it('gives each directive its own attribute vocabulary', function (ShapeKind $kind, string $last) {
    // Read off the enum rather than a list here, so a directive cannot gain an attribute
    // without this noticing which one gained it.
    $attributes = $kind->attributes();

    expect($attributes)->toContain('left', 'top', 'width', 'height', 'anchor', 'layer')
        ->and($attributes)->toContain($last)
        ->and($attributes)->toBe(array_values(array_unique($attributes)));
})->with([
    [ShapeKind::Rectangle, 'stroke-width'],
    [ShapeKind::RoundRect, 'border-radius'],
    [ShapeKind::Image, 'src'],
    [ShapeKind::Content, 'padding'],
]);

it('refuses a class or an id, because there is no stylesheet for either to select', function () {
    expect(fn () => placedShapes("::rectangle{.panel}\n"))
        ->toThrow(MalformedShape::class, 'which it does not read');
});

it('refuses a label in brackets, which a free shape has nowhere to put', function () {
    expect(fn () => placedShapes("::rectangle[Panel]{}\n"))
        ->toThrow(MalformedShape::class, 'has no title of its own');
});

it('reads the Markdown between a placed text block\'s fences', function () {
    // The body is the ordinary blocks, read by the ordinary walk: a heading keeps the theme's
    // scale for its depth and a list is a list.
    $frame = placedShape(":::content\n## Two\n\n- one\n:::\n")->frame;

    expect(array_map(static fn ($block): string => $block::class, $frame->blocks()))
        ->toBe([Heading::class, ListBlock::class]);
});

it('refuses a body that is not type, because one directive is one shape', function () {
    // A table, a code block, a picture and a rule are each a shape of their own, and the
    // renderer counts the shapes it writes by position — so a body that could produce two would
    // put the click chain's ids and the ones in the file out of step.
    expect(fn () => placedShapes(":::content\n| A | B |\n| --- | --- |\n| 1 | 2 |\n:::\n"))
        ->toThrow(MalformedShape::class, 'whose body holds a table')
        ->and(fn () => placedShapes(":::content\n```\nline;\n```\n:::\n"))
        ->toThrow(MalformedShape::class, 'whose body holds a code block')
        ->and(fn () => placedShapes(":::content\n---\n:::\n"))
        ->toThrow(MalformedShape::class, 'whose body holds a rule');
});

it('refuses a picture inside a placed text block', function () {
    // A picture on a line of its own is one here as it is in a column — and refused for the
    // same reason a table is.
    expect(fn () => placedShapes(sprintf(":::content\n![alt](%s)\n:::\n", basename(freeShapePicture()))))
        ->toThrow(MalformedShape::class, 'whose body holds a picture');
});

it('refuses a directive inside a placed text block', function () {
    expect(fn () => placedShapes(":::content\n::circle{}\n:::\n"))
        ->toThrow(MalformedShape::class, 'whose body holds a directive');
});

it('refuses a step marker inside a placed text block, which is one box on one step', function () {
    expect(fn () => placedShapes(":::content\n::click\n:::\n"))
        ->toThrow(MalformedShape::class, 'one box on one step');
});

it('refuses a placed text block with no body', function () {
    // An empty box is one a reader can select and not type anything into.
    expect(fn () => placedShapes(":::content{}\n:::\n"))
        ->toThrow(MalformedShape::class, 'with no body');
});

it('sets a placed text block\'s type from its attributes', function () {
    $style = placedShape(
        ":::content{color=accent2 font-size=20 font-family=heading align=center vertical-align=bottom padding=12}\nWords.\n:::\n",
    )->style;

    expect($style->colour?->getRGB())->toBe('ED7D31')
        ->and($style->size)->toBe(20)
        ->and($style->face)->toBe('Calibri')
        ->and($style->align)->toBe('ctr')
        ->and($style->verticalAlign)->toBe('b')
        ->and($style->padding)->toBe(12.0);
});

it('leaves a placed text block\'s type alone where the deck named none of it', function () {
    // Null rather than the theme's numbers, because the frame is already drawn in them and a
    // value written again is a value that could disagree with what the theme says.
    $style = placedShape(":::content\nWords.\n:::\n")->style;

    expect($style->size)->toBeNull()
        ->and($style->face)->toBeNull()
        ->and($style->padding)->toBeNull()
        ->and($style->align)->toBe('l')
        // `color` is the one that is answered rather than left: it names the theme's own ink,
        // which is what a body would have been drawn in, and null would be the absence of a
        // colour where there is never the absence of one.
        ->and($style->colour?->getRGB())->toBe('000000');
});

it('reads a family name as a theme slot or as a literal', function (string $written, string $face) {
    // A theme that has not set a face has not made the name wrong, so a slot it does not know
    // is read as a family name rather than refused.
    expect(placedShape(sprintf(":::content{font-family=%s}\nWords.\n:::\n", $written))->style->face)
        ->toBe($face);
})->with([
    ['heading', 'Calibri'],
    ['mono', 'Consolas'],
    ['Inter', 'Inter'],
    ['"JetBrains Mono"', 'JetBrains Mono'],
]);

it('refuses a size of type below half a point, which is a box with nothing in it', function () {
    expect(fn () => placedShapes(":::content{font-size=0.2}\nWords.\n:::\n"))
        ->toThrow(MalformedShape::class, 'at least half a point');
});

it('refuses an alignment it does not have, and names the four it does', function (string $attribute) {
    expect(fn () => placedShapes(sprintf(":::content{%s=sideways}\nWords.\n:::\n", $attribute)))
        ->toThrow(MalformedShape::class, 'It is one of:');
})->with([['align'], ['vertical-align']]);

it('reads a placed picture through the same path scope an image in a column goes through', function () {
    $src = basename(freeShapePicture());
    $shape = placedShape(sprintf("::image{src=\"%s\" alt=Logo}\n", $src));

    expect($shape)->toBeInstanceOf(FreePicture::class)
        ->and($shape->image->written)->toBe($src)
        ->and($shape->image->alt)->toBe('Logo')
        ->and($shape->fit)->toBe('contain');
});


it('refuses a picture that names no file', function () {
    expect(fn () => placedShapes("::image{}\n"))
        ->toThrow(MalformedShape::class, 'A picture names the file it draws');
});

it('refuses a picture that is not a file, by the same failure an image is', function () {
    // One set of rules for both spellings, so a deck cannot read a file by writing a directive
    // that it could not read by writing an image.
    expect(fn () => placedShapes("::image{src=\"nope.png\"}\n"))
        ->toThrow(MarkdownException::class, 'is not a file');
});

it('fits a picture by containment or by stretch, and refuses a crop', function () {
    $src = basename(freeShapePicture());

    expect(placedShape(sprintf("::image{src=\"%s\" fit=stretch}\n", $src))->fit)->toBe('stretch')
        ->and(fn () => placedShapes(sprintf("::image{src=\"%s\" fit=cover}\n", $src)))
        ->toThrow(MalformedShape::class, 'which is a crop rather than a fit')
        ->and(fn () => placedShapes(sprintf("::image{src=\"%s\" fit=squish}\n", $src)))
        ->toThrow(MalformedShape::class, 'A fit is one of: contain, stretch');
});

it('writes each free shape as one shape of its own', function () {
    // The invariant the whole click chain rests on: a shape's id is its position in the
    // slide's shape collection, and the renderer counts them rather than looking them up.
    $src = basename(freeShapePicture());
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{}\n\n::circle{width=60}\n\n"
        . sprintf("::image{src=\"%s\"}\n\n:::content{left=0 top=400 width=300 height=100}\nWords.\n:::\n", $src),
    );

    expect($deck->shapeNames())
        ->toContain('rectangle', 'circle', 'image', 'content');
});

it('writes an ink shape as the autoshape its name means', function (string $name, string $geometry) {
    // Read off the file rather than off the enum, because the mapping from a name to an OOXML
    // geometry is the thing that has to be right and the enum is only the claim.
    $deck = freeShapeDeckFile(sprintf("---\nlayout: blank\n---\n\n::%s{}\n", $name));

    expect($deck->part(SLIDE_ONE))
        ->toContain(sprintf('<a:prstGeom prst="%s">', $geometry));
})->with([
    ['rectangle', 'rect'],
    ['roundrect', 'roundRect'],
    ['ellipse', 'ellipse'],
    ['circle', 'ellipse'],
    ['triangle', 'triangle'],
    ['diamond', 'diamond'],
    ['chevron', 'chevron'],
    ['hexagon', 'hexagon'],
    ['arrow', 'rightArrow'],
    ['line', 'rect'],
]);

it('writes a full-bleed panel where the deck put it', function () {
    $deck = freeShapeDeckFile("---\nlayout: blank\n---\n\n::rectangle{left=10% top=20% width=40% height=30%}\n");

    expect($deck->positionOf('rectangle'))
        ->toMatchArray(['x' => 96.0, 'y' => 108.0, 'width' => 384.0, 'height' => 162.0]);
});

it('paints a shape with the fill and the stroke it was given', function () {
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{fill=accent1 stroke=accent2 stroke-width=3}\n",
    );

    // An autoshape's stroke is an outline, so it is an `a:ln` and not the `a:ln` a text box
    // takes — both are `a:ln`, and what differs is the colour written into it.
    expect($deck->part(SLIDE_ONE))
        ->toContain('<a:srgbClr val="4472C4">')
        ->toContain('<a:srgbClr val="ED7D31">')
        ->toContain(sprintf('<a:ln w="%d">', (int) round(3 * 4 / 3 * 9525)));
});

it('writes an alpha as the percentage that was asked for', function () {
    $deck = freeShapeDeckFile("---\nlayout: blank\n---\n\n::circle{width=60 fill=accent1 alpha=90}\n");

    expect($deck->part(SLIDE_ONE))->toContain('<a:alpha val="90000"/>');
});

it('writes a placed text block as one box carrying the Markdown inside it', function () {
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n:::content{left=40 top=60 width=400 height=200}\n## Two\n\n- one\n- two\n:::\n",
    );

    expect($deck->shapeNames())->toContain('content')
        ->and($deck->slideText())->toContain('one')
        ->and($deck->slideText())->toContain('Two');
});

it('writes a placed text block\'s type at the size and in the face it was given', function () {
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n:::content{font-size=30 font-family=mono color=accent3}\nWords.\n:::\n",
    );

    expect($deck->fontSize('content'))->toBe(30.0)
        ->and($deck->part(SLIDE_ONE))->toContain('<a:latin typeface="Consolas"/>')
        ->and($deck->part(SLIDE_ONE))->toContain('<a:srgbClr val="A5A5A5">');
});

it('writes a placed picture in the box the deck put it in', function () {
    $src = basename(freeShapePicture());
    $deck = freeShapeDeckFile(sprintf(
        "---\nlayout: blank\n---\n\n::image{src=\"%s\" left=100 top=50 width=200 height=100}\n",
        $src,
    ));

    // The description a screen reader reads falls back to the written path, which is the only
    // description of where the deck found the picture that reaches the file.
    expect($deck->shapeNames())->toContain('image')
        ->and($deck->part(SLIDE_ONE))->toContain(sprintf('descr="%s"', $src));
});

it('writes a shape behind the layout\'s own where the deck asked for one', function () {
    // A later shape in `p:spTree` is drawn over an earlier one, so `layer=back` is written
    // first and the text lands on top of it. This is the whole of what the layer is.
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{fill=panel layer=back}\n\nThe words.\n",
    );

    expect(array_search('rectangle', $deck->shapeNames(), true))
        ->toBeLessThan(array_search('text', $deck->shapeNames(), true));
});

it('writes a shape over the layout\'s own where the deck asked for that', function () {
    $deck = freeShapeDeckFile("---\nlayout: blank\n---\n\n::rectangle{fill=panel}\n\nThe words.\n");

    expect(array_search('rectangle', $deck->shapeNames(), true))
        ->toBeGreaterThan(array_search('text', $deck->shapeNames(), true));
});

it('puts a placed shape on the step the deck wrote it above', function () {
    // A free shape is on the step it was written on and nothing else, so the click above it
    // reveals it like any other shape. The shape above the marker stays on step 1 and is never
    // revealed by a click, which is what "a click that reveals nothing is a click the reader
    // spends to see no change" rules out.
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{}\n\n::click\n\n::circle{width=60}\n",
    );

    expect($deck->reveals())->toHaveCount(1)
        ->and($deck->reveals()[0])->toBe(['circle'])
        ->and($deck->shapeNames())->toBe(['rectangle', 'circle']);
});

it('leaves the layout\'s own shapes where they were', function () {
    // The point of the layer: a panel behind a content slide's title changes nothing about the
    // title, and a badge over one does not move it either.
    $deck = freeShapeDeckFile("# One\n\nThe words.\n\n::rectangle{fill=panel layer=back}\n");

    expect($deck->shapeNames())->toContain('title', 'text', 'rectangle')
        ->and($deck->positionOf('title'))->not->toBeNull();
});

it('keeps a placed shape on a slide whose title came from its front matter', function () {
    // The title of a blank slide is a heading the renderer draws itself, so this is the path
    // where the slide's blocks are rebuilt — and a shape written on such a slide has to
    // survive it.
    $deck = freeShapeDeckFile("---\ntitle: One\n---\n\n::rectangle{fill=panel}\n");

    expect($deck->shapeNames())->toContain('rectangle')
        ->and($deck->slideText())->toContain('One');
});

it('reports a placed text block that did not fit, in the column it is in', function () {
    // Same clamp-then-say rule as any other block, with no column behind the entry — there is
    // no column, and putting `0` there would be a number in front of nothing.
    $renderer = new PresentationRenderer();
    $renderer->render(
        (new DeckParser())->parse(
            "---\nlayout: blank\n---\n\n:::content{left=0 top=0 width=400 height=20}\n" . str_repeat("Words.\n\n", 40) . ":::\n",
        ),
        Scratch::file('deck.pptx', ''),
    );

    expect($renderer->overflows())->toHaveCount(1)
        ->and($renderer->overflows()[0]['column'])->toBeNull()
        ->and($renderer->overflows()[0]['block'])->toBe('content')
        ->and($renderer->overflows()[0]['wanted'])->toBeGreaterThan($renderer->overflows()[0]['available']);
});

it('refuses a placed text block that did not fit when the renderer is strict', function () {
    $renderer = new PresentationRenderer(strict: true);

    expect(fn () => $renderer->render(
        (new DeckParser())->parse(
            "---\nlayout: blank\n---\n\n:::content{left=0 top=0 width=400 height=20}\n" . str_repeat("Words.\n\n", 40) . ":::\n",
        ),
        Scratch::file('deck.pptx', ''),
    ))->toThrow(MarkdownException::class, 'its "content" text block needs');
});

it('resolves a free shape\'s colour through the theme the deck named', function () {
    // A deck written in slots survives a theme swap and one written in hex does not, which is
    // the whole of what the theme engine is for — so a free shape's `fill=panel` has to be the
    // panel of the theme in force and not of the library's defaults.
    $theme = __DIR__ . '/../../themes/corporate-dark/theme.yml';

    if (!is_file($theme)) {
        return;
    }

    $shape = (new BlockTranslator())->parse(
        "::rectangle{fill=accent1}\n",
        1,
        Scratch::directory(),
        new PathScope([Scratch::directory()]),
        (new ThemeResolver())->resolve('corporate-dark')->theme(),
    )->shapes()[0];

    expect($shape->paint->fill?->getRGB())->toBe('89B4FA');
});

it('places a shape where the deck wrote it, whatever the layout\'s box is', function () {
    // A free shape is not measured against the body box, and the default that proves it is
    // the whole slide rather than the column: `::rectangle{}` with no size is full bleed.
    expect(placedShape("::rectangle{}\n")->placement->box)
        ->toMatchArray(['x' => 0, 'y' => 0, 'width' => 960, 'height' => 540]);
});

it('reads no layer onto a shape as one written after the layout\'s own', function () {
    expect(placedShape("::rectangle{}\n")->isBack())->toBeFalse()
        ->and(placedShape("::rectangle{layer=back}\n")->isBack())->toBeTrue();
});

it('reads an ink shape as one shape and a placed text block as another', function () {
    // Two classes rather than one with a nullable payload on each, so the writer cannot be
    // handed a picture with no picture in it.
    expect(placedShape("::rectangle{}\n"))->toBeInstanceOf(FreeAutoShape::class)
        ->and(placedShape(":::content\nWords.\n:::\n"))->toBeInstanceOf(FreeText::class);
});

it('names the vocabulary from the kinds rather than from a list that could stop agreeing', function () {
    // The message above reads these names off the enum, so a name the library draws cannot go
    // missing from the refusal that says what it draws.
    $names = array_map(static fn (ShapeKind $kind): string => $kind->value, ShapeKind::cases());

    expect(FreeShapes::names())->toBe($names === [] ? [] : (function (array $n): array {
        sort($n);

        return $n;
    })($names));
});

it('reads a directive name as it is spelled, whatever its case', function () {
    // A deck cannot be asked to care about case, and `::Rectangle` is not a different
    // directive from `::rectangle`.
    expect(placedShape("::Rectangle{}\n")->kind)->toBe(ShapeKind::Rectangle)
        ->and(placedShape(":::CONTENT\nWords.\n:::\n"))->toBeInstanceOf(FreeText::class);
});

it('reads nothing as a name where a container was written without one', function () {
    expect(FreeShapes::kindOf(null))->toBeNull()
        ->and(FreeShapes::kindOf('  RECTANGLE '))->toBe(ShapeKind::Rectangle);
});

it('resolves a size of type to the whole points PHPPresentation wants', function () {
    // `Font::setSize()` takes an integer, so a size of 17.4 points is rounded rather than
    // refused — and this is the assertion that says which way.
    expect(placedShape(":::content{font-size=17.4}\nWords.\n:::\n")->style->size)
        ->toBe(17);
});

it('leaves the theme to decide the type a placed text block is drawn in', function () {
    // Nothing named, nothing written: the frame is drawn in the theme's own body size, and
    // `Style::body()` is the number that is — see {@see Style}.
    $shape = placedShape(":::content\nWords.\n:::\n");

    expect($shape->style->size)->toBeNull()
        ->and(Theme::defaults()->style()->body())->toBe(16);
});
it('writes a stroke width of no points as a hairline', function () {
    // `a:ln` is written in pixels and a deck is written in points, so a quarter of a point is
    // three tenths of a pixel — which the format has no way to say, and which a hairline is.
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n::rectangle{stroke=accent1 stroke-width=0}\n",
    );

    expect($deck->part(SLIDE_ONE))->toContain(sprintf('<a:ln w="%d">', 9525));
});

it('writes a corner radius on a rounded rectangle only', function () {
    $rounded = freeShapeDeckFile("---\nlayout: blank\n---\n\n::roundrect{border-radius=16}\n");
    $square = freeShapeDeckFile("---\nlayout: blank\n---\n\n::rectangle{}\n");

    // The adjustment is what rounds it, and it is a fraction of the shorter side rather than a
    // count of pixels — so the radius is not the number the deck wrote and a rectangle carries
    // no adjustment at all, which is why `::rectangle{border-radius=…}` is refused rather than
    // drawn with the radius quietly dropped.
    expect($rounded->part(SLIDE_ONE))->toMatch('/<a:gd name="adj" fmla="val [1-9]\d*"\/>/')
        ->and($square->part(SLIDE_ONE))->toContain('<a:avLst/>');
});

it('draws an HTML block inside a placed text block as the words in it', function () {
    // The same rule as in a column: this library has no opinion about what a deck says about
    // HTML and draws the text. A tag on a line of its own is a block in CommonMark and one
    // inside a sentence is not, so the two are asked about separately.
    $block = placedShape(":::content\n<div>A block.</div>\n:::\n")->frame->blocks()[0];
    $inline = placedShape(":::content\nText with <b>bold</b> in it.\n:::\n")->frame->blocks()[0];

    expect($block)->toBeInstanceOf(Paragraph::class)
        ->and(InlineTranslator::text($block->runs))->toContain('A block.')
        ->and($inline)->toBeInstanceOf(Paragraph::class);
});

it('writes a placed picture it had to convert, at the size of the conversion', function () {
    // A WebP is the case that matters: PHPPresentation measures a drawing from the path it was
    // given and cannot measure bytes, so a converted picture has to carry its own dimensions
    // and a placed one that was not converted carries none. `::image` takes both paths.
    $webp = convertible('webp', 40, 20);

    if ($webp === null) {
        $this->markTestSkipped('This GD build cannot write a WebP.');
    }

    $shape = freeShapeRead(sprintf("::image{src=\"%s\"}\n", basename($webp)), imageDirectory())
        ->shapes()[0];

    expect($shape)->toBeInstanceOf(FreePicture::class)
        ->and($shape->image->converted)->not->toBeNull();

    $path = Scratch::file('deck.pptx', '');
    (new PresentationRenderer(directory: imageDirectory()))->render(
        (new DeckParser())->parse(sprintf(
            "---\nlayout: blank\n---\n\n::image{src=\"%s\" left=20 top=20 width=300 height=120}\n",
            basename($webp),
        )),
        $path,
    );
    $deck = Pptx::open($path);

    // The 40×20 conversion is two to one and the box is two and a half, so the picture keeps
    // its own proportions inside it — which is `fit=contain` doing what it says on a placed
    // picture as it does on one in a column.
    expect($deck->positionOf('image'))->toMatchArray(['width' => 240.0, 'height' => 120.0]);
});

it('fits a placed picture inside its box, whatever its ratio', function (int $wide, int $tall, int $side) {
    // The regression this guards is a picture drawn *wider than the box it was given*:
    // `AbstractGraphic::setWidth()` re-derives the height from the ratio and `setHeight()`
    // then re-derives the width from that, so placing with two calls compounds the ratio and a
    // 2:1 picture in a square box comes back at 2x the width. It is invisible for a picture
    // whose ratio matches its box, which is why the square box is here and not a matched one.
    $png = Scratch::file('ratio.png', '');
    // The picture has the ratio the case is about, which a fixed one cannot.
    imagepng(imagecreatetruecolor($wide, $tall), $png);

    $path = Scratch::file('deck.pptx', '');
    (new PresentationRenderer(directory: Scratch::directory()))->render(
        (new DeckParser())->parse(sprintf(
            "::image{src=\"%s\" left=10 top=10 width=%d height=%d}\n",
            basename($png),
            $side,
            $side,
        )),
        $path,
    );
    $drawn = Pptx::open($path)->positionOf('image');

    expect($drawn['width'])->toBeLessThanOrEqual((float) $side)
        ->and($drawn['height'])->toBeLessThanOrEqual((float) $side);
})->with([
    'a square picture in a square box' => [30, 30, 300],
    'a landscape picture in a square box' => [40, 20, 300],
    'a portrait picture in a square box' => [20, 40, 300],
    'a landscape picture in a wide box' => [40, 20, 400],
    'a portrait picture in a wide box' => [20, 40, 400],
]);

it('leaves a placed picture it did not convert alone, and stretches one that asked for it', function () {
    // The other arm of the same fork: a PNG goes in as a path and PHPPresentation measures it.
    // `stretch` is the one flag that differs — contain keeps the ratio and fits inside, and
    // stretch takes the box exactly.
    $deck = freeShapeDeckFile(sprintf(
        "---\nlayout: blank\n---\n\n::image{src=\"%s\" fit=stretch width=300 height=120}\n",
        basename(freeShapePicture()),
    ));

    expect($deck->positionOf('image'))->toMatchArray(['width' => 300.0, 'height' => 120.0]);
});

it('writes a stroke and a padding onto a placed text block', function () {
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n:::content{left=40 top=40 width=400 height=200 stroke=accent2 "
        . "stroke-width=2 padding=20}\nWords.\n:::\n",
    );

    // A border left alone writes `LINE_SINGLE`, so the absence of one has to be said rather
    // than left — and a stroke one has to reach the file rather than the paragraph.
    expect($deck->part(SLIDE_ONE))
        ->toContain('<a:ln')
        ->toContain('<a:srgbClr val="ED7D31">')
        ->toContain('lIns="190500"')
        ->toContain('tIns="190500"');
});

it('writes a placed text block\'s alignment, horizontal and vertical', function (string $attribute, string $written) {
    // Two different elements and two different spellings, which is the whole reason the words
    // are translated here rather than carried down: `align` is a paragraph's `a:pPr algn`, and
    // `vertical-align` is the text body's `a:bodyPr anchor`.
    $deck = freeShapeDeckFile(sprintf(
        "---\nlayout: blank\n---\n\n:::content{%s=%s}\nWords.\n:::\n",
        $attribute,
        $written,
    ));

    // PHPPresentation's own spellings, which are not the words a deck is written in.
    $wanted = match (true) {
        $attribute === 'align' => 'algn="ctr"',
        $written === 'bottom' => 'anchor="b"',
        default => 'anchor="ctr"',
    };

    expect($deck->part(SLIDE_ONE))->toContain($wanted);
})->with([
    ['align', 'center'],
    ['vertical-align', 'center'],
    ['vertical-align', 'bottom'],
]);

it('draws a paragraph inside a placed text block with its inline markup intact', function () {
    $frame = placedShape(":::content\nSome **bold** and *italic*.\n:::\n")->frame;

    expect($frame->blocks()[0])->toBeInstanceOf(Paragraph::class)
        ->and(InlineTranslator::text($frame->blocks()[0]->runs))->toBe('Some bold and italic.');
});

it('refuses a step marker written with a label, as it refuses the bare form', function () {
    // `::click` is a name {@see \MarkdownPresentation\Render\CommonMark\Refusals} lets past —
    // it is the marker's own name — so it is `::click[label]` that first arrives at the walk
    // under a name this library does not draw. Asserting it says the assertion holds: a `null`
    // reaching the dispatch would draw nothing rather than refuse.
    expect(fn () => placedShapes("::click[label]\n"))
        ->toThrow(UnknownDirective::class, 'which is not something a slide draws');
});

it('leaves the type of a placed text block in the theme\'s own where it named none', function () {
    // Nothing to walk: the early return is the shape of that answer, and a frame drawn in the
    // theme's body size and face is already right.
    expect(placedShape(":::content\nWords.\n:::\n")->style->face)->toBeNull();
});

it('measures a placed text block at the size it named, not at the theme\'s body size', function () {
    // The bug this guards is a clamp, not a crash, and it only shows where the box sits
    // *between* the two heights. A box taller than both fits either way, and a box shorter
    // than both overflows either way — measuring 24pt of text at the theme's 16pt makes it
    // look shorter than it is, so the shape is cut to room it did not need and the block is
    // reported as lost when it was not. 38px of 24pt against 26px of 16pt, in a 32px box, is
    // that case exactly: right, it overflows; measured at the body size, it does not.
    $tight = "# One\n\n:::content{left=0 top=0 width=400 height=32 font-size=24}\nOne line.\n:::\n";
    $renderer = new PresentationRenderer(directory: Scratch::directory());

    $renderer->render((new DeckParser())->parse($tight), Scratch::file('tight.pptx', ''));
    $overflows = $renderer->overflows();

    expect(freeShapeDeckFile($tight)->part(SLIDE_ONE))->toContain('sz="2400"')
        ->and($overflows)->toHaveCount(1)
        ->and($overflows[0]['block'])->toBe('content')
        ->and($overflows[0]['wanted'])->toBe(38)
        ->and($overflows[0]['available'])->toBe(32)
        ->and($overflows[0]['column'])->toBeNull();
});

it('turns a shape by the angle it was given, in every kind of shape', function (string $markdown, string $name) {
    // `rot` is written on the `a:xfrm` of a text box, an autoshape and a picture, and the
    // only thing this has to do is hand the angle to all three: 45 degrees is 2,700,000
    // sixtieths of a degree, which is what `a:xfrm rot` counts in.
    $markdown = $markdown === 'PLACEHOLDER'
        ? sprintf('::image{src="%s" rotate=45}', basename(freeShapePicture()))
        : $markdown;

    $deck = freeShapeDeckFile(sprintf("---\nlayout: blank\n---\n\n%s\n", $markdown));

    expect($deck->part(SLIDE_ONE))->toContain('<a:xfrm rot="2700000"')
        ->and($deck->shapeNames())->toContain($name);
})->with([
    'an ink shape'   => ['::arrow{width=200 height=80 rotate=45}', 'arrow'],
    'a text block'   => [":::content{width=300 height=120 rotate=45}\nWords.\n:::", 'content'],
    'a picture'      => ['PLACEHOLDER', 'image'],
]);

it('writes no rotation at all where the deck named none', function () {
    // `a:xfrm rot` is written only for a non-zero angle, so an unrotated shape carries no
    // attribute at all rather than `rot="0"`.
    $deck = freeShapeDeckFile("---\nlayout: blank\n---\n\n::rectangle{}\n");

    expect($deck->part(SLIDE_ONE))->not->toContain('rot=');
});

it('turns a shape the same way whichever way round the angle is written', function (string $written, int $degrees) {
    // Negative and past a turn are both things a model writes, and both mean an angle rather
    // than a refusal. Wrapping also bounds the number: `setRotation()` is multiplied by sixty
    // thousand and returned as an `int`, so an unbounded angle is a `TypeError` on a deck
    // rather than a failure anybody can act on.
    $deck = freeShapeDeckFile(sprintf(
        "---\nlayout: blank\n---\n\n::rectangle{width=100 height=100 rotate=%s}\n",
        $written,
    ));

    expect($deck->part(SLIDE_ONE))->toContain(sprintf('<a:xfrm rot="%d"', $degrees * 60000));
})->with([
    ['45', 45],
    ['-45', 315],
    ['315', 315],
    ['-12', 348],
    ['400', 40],
    ['-400', 320],
    ['45deg', 45],
    ['99999999999', 279],
]);

it('writes no rotation for a whole turn or for none at all', function (string $written) {
    // A full turn is no rotation, and `a:xfrm rot` is written only for a non-zero angle — so
    // `rotate=360` comes back as a shape with no `rot` on it rather than as `rot="0"`. Which is
    // the other half of the wrap: a `%` that left a negative alone would write a negative
    // angle, and one that mapped a turn to 360 would write a rotation the author did not ask
    // for.
    $deck = freeShapeDeckFile(sprintf(
        "---\nlayout: blank\n---\n\n::rectangle{width=100 height=100 rotate=%s}\n",
        $written,
    ));

    expect($deck->part(SLIDE_ONE))->not->toContain('rot=');
})->with(['0', '360', '-360', '720']);

it('refuses a rotation that is not a whole number of degrees', function (string $written) {
    // `a:xfrm rot` is in sixtieths of a degree and `setRotation()` is an `int`, so a fraction
    // is a `TypeError` rather than a rounded angle. Refused with the spelling that works.
    // `rotate=45 turns` is not here: the space splits it into a rotation and an attribute
    // called `turns`, which the attribute vocabulary refuses first and for a different reason.
    expect(fn () => placedShapes(sprintf("::rectangle{rotate=%s}\n", $written)))
        ->toThrow(MalformedShape::class, 'A rotation is a whole number of degrees');
})->with(['22.5', '45.5', 'quarter', '45deg2', '%', '1e3', '']);

it('turns a text block with its shape, which is what a label on a tilted arrow is', function () {
    // The composable answer to putting text in a shape, and the reason the vocabulary has no
    // `text` attribute: a `:::content` box is a shape, so it carries `rotate` too, and a box
    // given the same angle as the arrow under it turns with it.
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n::arrow{left=5% top=34% width=32% height=15% fill=accent1 rotate=-12}\n\n"
        . ":::content{left=5% top=38% width=32% height=7% rotate=-12 align=center font-size=16}\n**Retention**\n:::\n",
    );

    // One turn on each shape, and the two are at the same angle — which is the whole claim.
    // `-12` is written as 348 because the reader wraps, and 348 is the same angle.
    expect(substr_count($deck->part(SLIDE_ONE), 'rot="20880000"'))->toBe(2)
        ->and($deck->shapeNames())->toBe(['arrow', 'content']);
});

it('puts a label in the shape it belongs to, as one shape rather than two', function () {
    // The claim that separates `label` from `:::content`: one `<p:sp>` carrying both the
    // geometry and the words. Double-click it in PowerPoint and the cursor is in the arrow,
    // which is what two overlapping shapes cannot offer.
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n::arrow{width=200 height=60 label=\"Retention 94%\"}\n",
    );

    expect($deck->shapeNames())->toBe(['arrow'])
        ->and($deck->part(SLIDE_ONE))->toContain('<a:t><![CDATA[Retention 94%]]></a:t>')
        ->and(substr_count($deck->part(SLIDE_ONE), '<p:sp>'))->toBe(1);
});

it('writes no words for a shape with no label', function () {
    // The element itself is there either way: `p:sp` has a `txBody` whether or not it has
    // anything in it, and PHPPresentation writes the empty run to fill it. What matters is that
    // no words appear, which is the only part a reader sees.
    $deck = freeShapeDeckFile("---\nlayout: blank\n---\n\n::rectangle{}\n");

    expect($deck->slideText())->toBe('')
        ->and($deck->part(SLIDE_ONE))->toContain('<a:t><![CDATA[]]></a:t>');
});

it('writes a label in the body colour of the theme it was rendered with', function () {
    // No `a:rPr` is written, so the words are drawn in `tx1` — whatever the master's colour map
    // makes text. That is why a label follows `dark:` and why it has contrast against the page
    // rather than against the shape's own fill.
    $slide = "---\nlayout: blank\n---\n\n::arrow{width=200 height=60 fill=accent1 label=\"Hi\"}\n";
    $deck = freeShapeDeckFile($slide);

    expect($deck->part(SLIDE_ONE))->not->toContain('<a:rPr')
        ->and($deck->part('ppt/slideMasters/slideMaster1.xml'))->not->toBe('')
        ->and($deck->part('ppt/slides/slide1.xml'))->not->toContain('<a:rPr');
});

it('turns a shape with a label on it, because the words are in the shape', function () {
    // The other half of the trade against `:::content`: no second `rotate` to keep in step,
    // because there is no second shape.
    $deck = freeShapeDeckFile(
        "---\nlayout: blank\n---\n\n::arrow{width=200 height=60 rotate=-12 label=\"Retention\"}\n",
    );

    // `-12` is written as 348 because the reader wraps, and 348 is the same angle.
    expect($deck->part(SLIDE_ONE))->toContain('<a:xfrm rot="20880000"')
        ->and($deck->shapeNames())->toBe(['arrow']);
});

it('refuses a label on a picture, which has no text body to put one in', function (string $attribute) {
    expect(fn () => placedShapes(sprintf("::image{src=\"%s\" %s}\n", basename(freeShapePicture()), $attribute)))
        ->toThrow(MalformedShape::class, 'which it has nowhere to put');
})->with(['label="Hi"', 'label=""']);

it('refuses a label on a placed text block, whose body is already its text', function () {
    // Putting the same words in the same place twice, which is what both label refusals are
    // about: a picture has no body to hold one and a text block already has one full.
    expect(fn () => placedShapes(":::content{label=\"Hi\"}\nWords.\n:::\n"))
        ->toThrow(MalformedShape::class, 'the body between the fences is already its text');
});

it('gives the page number its own reason for having nowhere to put a label', function () {
    // The page number is not a text block and has no body at all: the number of the slide is
    // written into it by the renderer. A message that describes the body between the fences
    // sends the author looking for a fence the page number has not got.
    $message = function (string $markdown): string {
        try {
            placedShapes($markdown);
        } catch (MalformedShape $refusal) {
            return $refusal->getMessage();
        }

        return '';
    };

    expect($message("::slide-number{label=\"Hi\"}\n"))
        ->toContain('the number of the slide is written into it by the renderer')
        ->and($message("::slide-number{label=\"Hi\"}\n"))
        ->not->toBe($message(":::content{label=\"Hi\"}\nWords.\n:::\n"));
});

it('refuses a bare label, which asks for the attribute rather than for its value', function () {
    expect(fn () => placedShapes("::arrow{width=100 height=60 label}\n"))
        ->toThrow(MalformedShape::class, 'A bare attribute asks for the attribute rather than for its value');
});

it('reads no label onto a shape that was not written with one', function () {
    // The default, and what a picture and a text block carry: nothing reads it, so it is empty
    // rather than absent, and there is no third state to get wrong.
    expect(placedShape("::rectangle{}\n")->label)->toBe('')
        ->and(placedShape("::rectangle{label=\"Hi\"}\n")->label)->toBe('Hi');
});
