<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedTemplate;
use MarkdownPresentation\Exception\UnsupportedColumn;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Render\TemplateGeometry;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\Template;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\LayoutRole;

/*
 * A theme that ships a `template.pptx`, read.
 *
 * The template under `tests/fixtures/themes/templated/` is the one every claim here is
 * checked against, rather than one written moments earlier: a reader that only works on a
 * file its own test made is a reader nobody has shown works on a template.
 *
 * @phpstan-import-type Shape from Template
 */

/** Where the theme that ships a `template.pptx` is committed, and the file inside it. */
const TEMPLATE_THEME = '/tests/fixtures/themes/templated';
const TEMPLATED_PPTX = '/template.pptx';

/**
 * The layouts mapping a theme built for these tests writes, and the layout name it maps to.
 *
 * Both are constants rather than literals because every test below that builds a theme maps
 * the same role onto the same layout: a test that mapped `content` somewhere else would be
 * testing something else, and spelling it out each time is how that happens quietly.
 */
const CONTENT_LAYOUT = "layouts:\n  content: Title and Content\n";

/**
 * The theme beside the committed fixture, on its own.
 */
function templatedTheme(): string
{
    return dirname(__DIR__, 2) . TEMPLATE_THEME;
}

/**
 * A deck written against `$theme`, as the frontmatter that points at one.
 *
 * A function rather than a constant because every deck below opens with the same
 * `"---\ntheme: "` and some of them carry a `layout:` in between, which is spelled as the
 * frontmatter's body rather than as a second argument at each call site.
 *
 * @param string $frontmatter Anything to put beside `theme:` — a `layout:` and nothing
 *                            else, or '' for a deck that names none.
 */
function themed(string $theme, string $frontmatter = '', string $body = "# One\n\nText.\n"): string
{
    return "---\ntheme: " . $theme . "\n" . $frontmatter . "---\n" . $body;
}

/**
 * A template written from a description, in a directory of its own.
 *
 * @param list<array{name: string, shapes: list<Shape>}> $layouts
 * @param list<Shape>                                    $master
 * @param array{cx: int, cy: int}                        $page
 */
function builtTemplate(
    array $layouts = [],
    ?array $master = null,
    array $page = Template::PAGE,
    bool $layoutHasMaster = true,
): string {
    return Template::write(
        Scratch::directoryPath('template') . '/template.pptx',
        $layouts,
        $master,
        $page,
        $layoutHasMaster,
    );
}

/**
 * @return Pptx
 */
function templatedDeck(string $markdown): Pptx
{
    $path = Scratch::file('templated.pptx', '');

    (new PresentationRenderer())->render((new DeckParser())->parse($markdown), $path);

    return Pptx::open($path);
}

/**
 * A template whose `ppt/presentation.xml` is exactly the given size.
 *
 * The exact size is the point of the fixture: a ceiling is only worth a test where a part is on one
 * side of it and another on the other, and a part of "about" a size is on neither.
 */
function templateWithPartOf(int $size): string
{
    $path = Scratch::file('oversized.pptx', '');

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/presentation.xml', presentationOf($size));
    $zip->close();

    return $path;
}

/**
 * A `ppt/presentation.xml` of exactly the given size.
 *
 * Padded with a comment rather than with spaces, because a comment is something the reader parses
 * and a run of spaces in front of the root element is not.
 */
function presentationOf(int $size): string
{
    $declaration = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $document = '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:sldSz cx="12192000" cy="6858000"/></p:presentation>';

    // A comment is `<!--`, the padding, and `-->`.
    $padding = $size - strlen($declaration) - strlen($document) - 7;

    return $declaration . '<!--' . str_repeat('x', $padding) . '-->' . $document;
}

/**
 * A template whose part says it is smaller than it is.
 *
 * The size is written into the archive twice — in the local file header and in the central
 * directory — and both are patched, so the archive claims a part of a hundred bytes and holds a
 * megabyte. A reader that believed the claim would read the hundred bytes and never see what is
 * behind them.
 */
function templateThatLiesAboutItsPart(): string
{
    $path = Scratch::file('liar.pptx', '');

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/presentation.xml', presentationOf(1024 * 1024));
    $zip->close();

    $bytes = (string) file_get_contents($path);

    // The uncompressed size sits at 22 in the local file header and at 24 in the directory entry.
    $local = (int) strpos($bytes, "PK\x03\x04");
    $central = (int) strrpos($bytes, "PK\x01\x02");

    $bytes = substr_replace($bytes, pack('V', 100), $local + 22, 4);
    $bytes = substr_replace($bytes, pack('V', 100), $central + 24, 4);

    file_put_contents($path, $bytes);

    return $path;
}

it('scales a template\'s page onto the one this library writes', function () {
    // The fixture's page is 12192000 × 6858000 EMU, a stock 16:9 PowerPoint slide and 1280 ×
    // 720 of the pixels a `Box` counts in. Dividing by the 9525 EMU a pixel would put every
    // one of its placeholders off the right and the bottom of a 960-wide slide; scaling by the
    // ratio of the two pages puts them where its designer put them.
    $boxes = (new TemplateGeometry(templatedTheme() . TEMPLATED_PPTX))->boxes();

    expect($boxes[TITLE_AND_CONTENT]['title']->x)->toBe(66)
        ->and($boxes[TITLE_AND_CONTENT]['title']->y)->toBe(54)
        ->and($boxes[TITLE_AND_CONTENT]['title']->width)->toBe(828)
        ->and($boxes[TITLE_AND_CONTENT]['body'][0]->height)->toBe(324);
});

it('orders a layout\'s columns by the index they carry', function () {
    // The fixture writes *Two Content*'s second column first, because a reader that took
    // them in the order the file lists them would put the right column where the left one goes.
    $columns = (new TemplateGeometry(templatedTheme() . TEMPLATED_PPTX))->boxes()['Two Content']['body'];

    expect($columns[0]->x)->toBe(66)
        ->and($columns[1]->x)->toBe(500)
        ->and($columns[0]->width)->toBe($columns[1]->width);
});

it('takes a placeholder a layout does not place from its master', function () {
    // *Two Content* states no position for its title, which is what PowerPoint writes for a
    // layout that does not move the title it inherited. The master puts it at 365125 EMU down
    // a 6858000 EMU page, which is 29 pixels down a 540-pixel one.
    $boxes = (new TemplateGeometry(templatedTheme() . TEMPLATED_PPTX))->boxes();

    expect($boxes['Two Content']['title']->y)->toBe(29)
        ->and($boxes['Two Content']['title']->height)->toBe(104);
});

it('leaves out a placeholder it cannot draw in', function () {
    // A slide number is a placeholder too. Laid out as body text it would be a column of
    // nothing on every slide.
    expect((new TemplateGeometry(templatedTheme() . TEMPLATED_PPTX))->boxes()[TITLE_AND_CONTENT])
        ->toHaveKeys(['title', 'body']);
});

it('leaves a layout with nothing in it saying nothing at all', function () {
    // Office's own *Blank* has no placeholders, so a role mapped to it keeps the geometry the
    // library puts it at rather than inheriting an empty column.
    expect((new TemplateGeometry(templatedTheme() . TEMPLATED_PPTX))->boxes())
        ->not->toHaveKey('Blank');
});

it('draws a slide in the geometry its theme\'s template states', function () {
    // The claim that makes a template worth shipping: the layout part and the slide agree,
    // so *Reset* restores what the slide already has.
    $pptx = templatedDeck(themed(templatedTheme()));
    $placeholder = $pptx->layoutPlaceholders()['title'];

    expect($pptx->positionOf('title'))->toEqual($placeholder)
        ->and($placeholder['x'])->toBe(66.0);
});

it('draws both columns of a two-column slide where the template puts them', function () {
    $shapes = templatedDeck(
        themed(templatedTheme(), "layout: two-column\n", "# Both\n\nLeft.\n\n::column\n\nRight side.\n"),
    )->positions();

    expect($shapes[1]['x'])->toBe(66.0)
        ->and($shapes[1]['width'])->toBe(394.0)
        ->and($shapes[2]['x'])->toBe(500.0);
});

it('splits a role the template gives two columns to, whatever the role is called', function () {
    // The boxes are the fact and not the role: `content` is *Two Content* here, so it has two
    // columns even though the library's own `content` has one. This is the case `splits()`
    // used to get wrong too — it asked the constructor's default geometry rather than the
    // merged one, so the second box was laid out and left empty with nothing saying so.
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Two Columns\n",
        'layouts.yml' => "layouts:\n  content: Two Content\n",
        'template.pptx' => file_get_contents(templatedTheme() . TEMPLATED_PPTX),
    ]);

    $path = Scratch::file('templated.pptx', '');

    (new PresentationRenderer())->render(
        (new DeckParser())->parse(themed($theme, "layout: content\n", "# One\n\nLeft.\n\n::column\n\nRight.\n")),
        $path,
    );

    $shapes = Pptx::open($path)->positions();

    expect($shapes[1]['x'])->toBe(66.0)
        ->and($shapes[2]['x'])->toBe(500.0);
});

it('refuses a ::column the template\'s own layout has no second box for', function () {
    // The other half of the same question, and the half that is visible: *Title and Content*
    // gives `content` one column, so the marker is refused rather than moving a block into a
    // box the template never drew.
    expect(fn () => templatedDeck(themed(templatedTheme(), '', "# One\n\nLeft.\n\n::column\n\nRight.\n")))
        ->toThrow(UnsupportedColumn::class, 'Slide 1 has a "::column", and the "content" layout has 1 column.');
});

it('draws a run of paragraphs in the box the template gives the body', function () {
    // The frame is the shape a column's text is drawn in, so a template that moves the column
    // moves the frame with it — the geometry is the template's, whatever is drawn in it.
    $pptx = templatedDeck(themed(templatedTheme(), '', "# One\n\nFirst.\n\nSecond.\n\nThird.\n"));
    $body = $pptx->layoutPlaceholders()['body 1'];
    $frame = $pptx->positionOf('text');

    expect($frame['x'])->toBe($body['x'])
        ->and($frame['y'])->toBe($body['y'])
        ->and($frame['width'])->toBe($body['width'])
        ->and($frame['height'])->toBeLessThanOrEqual($body['height']);
});

it('leaves a role whose layout the template does not have where the library puts it', function () {
    // The precedence: a template fills in the roles it covers and says nothing about the rest.
    // *Section Header* is mapped and absent, which is the same as not mapping it at all.
    $boxes = (new TemplateGeometry(templatedTheme() . TEMPLATED_PPTX))->boxes();

    expect($boxes)->not->toHaveKey('Section Header')
        ->and(templatedDeck(themed(templatedTheme(), "layout: section\n"))
            ->positionOf('title')['y'])
        ->toBe((float) (new SlideGeometry())->title(LayoutRole::Section)['box']->y);
});

it('keeps the type a title is drawn at where the library puts it', function () {
    // A template states its type in a master's `p:txStyles`, which is a whole typographic
    // system rather than a layout, and adopting one would resize every deck that shipped a
    // template. The box moved and the size did not, which is the whole of what a template is
    // allowed to do.
    $pptx = templatedDeck(themed(templatedTheme()));

    expect($pptx->fontSize('title'))->toBe((float) (new SlideGeometry())->title(LayoutRole::Content)['size'])
        ->and($pptx->positionOf('title')['x'])->toBe(66.0);
});

it('clamps against the box the template gives rather than the one the library would', function () {
    // Nine paragraphs fill 330 pixels of column: they fit the library's own 396-pixel content
    // column and do not fit the template's 324-pixel one, so the same deck overflows in one
    // theme and not in the other. Measurement and clamping are the same two numbers either
    // way — only the box they are measured against changed, and that is what this is for.
    $renderer = new PresentationRenderer();
    $nine = str_repeat("A paragraph.\n\n", 9);

    $renderer->render((new DeckParser())->parse($nine), Scratch::file('plain.pptx', ''));
    $plain = $renderer->overflows();

    $renderer->render(
        (new DeckParser())->parse(themed(templatedTheme(), '', $nine)),
        Scratch::file('templated.pptx', ''),
    );

    expect($plain)->toBe([])
        ->and($renderer->overflows())->not->toBe([])
        ->and($renderer->overflows()[0]['available'])->toBeLessThan(
            (new SlideGeometry())->body(LayoutRole::Content)[0]->height,
        );
});

it('refuses a template that is not a presentation', function () {
    // Silently falling back would leave a theme that says its geometry comes from a template
    // rendering as though it had not said so.
    expect(fn () => (new TemplateGeometry(Scratch::file('not-a-template.pptx', 'not a zip')))->boxes())
        ->toThrow(MalformedTemplate::class, 'could not be opened as a presentation');
});

it('refuses a template that does not say how big its slides are', function () {
    // Every box in a template is a fraction of its page, so one that does not state a page
    // cannot place anything. `p:sldSz` is optional in the schema and this is what that costs.
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Sizeless\n",
        'layouts.yml' => CONTENT_LAYOUT,
        // The contents, not the path: this copies a file into a theme directory rather than
        // naming one.
        'template.pptx' => file_get_contents(builtTemplate(page: ['cx' => 0, 'cy' => 0])),
    ]);

    expect(fn () => (new PresentationRenderer())->render(
        (new DeckParser())->parse(themed($theme)),
        Scratch::file('deck.pptx', ''),
    ))->toThrow(MalformedTemplate::class, 'says nothing about how big its slides are');
});

it('refuses a template with a part it cannot read', function () {
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Broken\n",
        'layouts.yml' => CONTENT_LAYOUT,
    ]);

    $zip = new ZipArchive();
    $zip->open($theme . '/template.pptx', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/presentation.xml', 'not xml at all');
    $zip->close();

    expect(fn () => (new PresentationRenderer())->render(
        (new DeckParser())->parse(themed($theme)),
        Scratch::file('deck.pptx', ''),
    ))->toThrow(MalformedTemplate::class, 'no readable part at ppt/presentation.xml');
});

it('refuses a template that is missing the part it needs', function () {
    // A template without `ppt/presentation.xml` says nothing about how big its slides are, and
    // the part it does not have is named rather than left to be a document that failed to parse.
    $path = Scratch::file('empty.pptx', '');

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<p:sldLayout/>');
    $zip->close();

    expect(fn () => (new TemplateGeometry($path))->boxes())
        ->toThrow(MalformedTemplate::class, 'no readable part at ppt/presentation.xml');
});

it('refuses a part of a template that is larger than a part may be', function () {
    // A zip says how its contents are laid out and nothing about how much room they will take up:
    // a part of a few kilobytes can inflate to hundreds of megabytes, and the document built from
    // it costs several times that again in libxml's memory, which PHP's `memory_limit` does not
    // see. The ceiling is the only thing that bounds it.
    $path = templateWithPartOf(TemplateGeometry::MAX_PART_BYTES + 1);

    expect(fn () => (new TemplateGeometry($path))->boxes())
        ->toThrow(
            MalformedTemplate::class,
            sprintf('larger than the %d bytes allowed', TemplateGeometry::MAX_PART_BYTES),
        );
});

it('reads a part that is as large as the ceiling allows', function () {
    // The other side of the bound: the ceiling is a number rather than "everything over a few
    // hundred bytes", and a part of exactly the allowed size is read rather than refused.
    $size = 4096;

    expect((new TemplateGeometry(templateWithPartOf($size), maxPartBytes: $size))->boxes())->toBe([]);
});

it('refuses a part one byte over the ceiling', function () {
    $size = 4096;

    expect(fn () => (new TemplateGeometry(templateWithPartOf($size), maxPartBytes: $size - 1))->boxes())
        ->toThrow(MalformedTemplate::class, 'larger than the 4095 bytes allowed');
});

it('refuses a part that says it is smaller than it is', function () {
    // The size the archive declares is the archive's own claim, and the claim can be false. The
    // ceiling is applied to what the part really holds, which is the only number that can be
    // trusted: this part claims a hundred bytes and holds a megabyte.
    $path = templateThatLiesAboutItsPart();

    expect(fn () => (new TemplateGeometry($path, maxPartBytes: 4096))->boxes())
        ->toThrow(MalformedTemplate::class, 'larger than the 4096 bytes allowed');
});

it('refuses a theme whose template has a part too large to read', function () {
    // The refusal has to reach the caller of the library and not only the reader, or a render ends
    // in a fatal error that names neither the theme nor the part.
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Oversized\n",
        'layouts.yml' => CONTENT_LAYOUT,
        'template.pptx' => (string) file_get_contents(templateWithPartOf(TemplateGeometry::MAX_PART_BYTES + 1)),
    ]);

    expect(fn () => (new PresentationRenderer())->render(
        (new DeckParser())->parse(themed($theme)),
        Scratch::file('deck.pptx', ''),
    ))->toThrow(MalformedTemplate::class, 'larger than the');
});

it('leaves a layout that inherits from nothing where the library puts it', function () {
    // PowerPoint gives every layout exactly one master. One that names none has nothing to
    // inherit, so its placeholders have nowhere to come from and the role keeps the geometry
    // the library puts it at — rather than this reading a master it was never told about.
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Orphaned\n",
        'layouts.yml' => CONTENT_LAYOUT,
        'template.pptx' => file_get_contents(builtTemplate(
            [['name' => TITLE_AND_CONTENT, 'shapes' => [['type' => 'title']]]],
            layoutHasMaster: false,
        )),
    ]);

    $path = Scratch::file('orphaned.pptx', '');

    (new PresentationRenderer())->render(
        (new DeckParser())->parse(themed($theme)),
        $path,
    );

    expect((new TemplateGeometry($theme . '/template.pptx'))->boxes())->toBe([])
        ->and(Pptx::open($path)->positionOf('title')['y'])->toBe(
            (float) (new SlideGeometry())->title(LayoutRole::Content)['box']->y,
        );
});

it('says a layout with only a title and one with only a body', function () {
    // Both are ordinary layouts in a real template, and a reader that could only express
    // "a title and some columns" would leave a deck's geometry half-taken.
    $path = builtTemplate([
        ['name' => 'Just a title', 'shapes' => [
            ['type' => 'title', 'x' => 0, 'y' => 0, 'cx' => Template::PAGE['cx'], 'cy' => Template::PAGE['cy'] / 4],
        ]],
        ['name' => 'Just a body', 'shapes' => [
            ['type' => 'body', 'idx' => 1, 'x' => 0, 'y' => 0, 'cx' => Template::PAGE['cx'], 'cy' => Template::PAGE['cy']],
        ]],
    ]);

    expect(array_keys((new TemplateGeometry($path))->boxes()))->toBe(['Just a title', 'Just a body']);
});

it('ignores a placeholder it cannot place anywhere', function () {
    // States no position and there is no master to inherit one from: nothing to say, rather
    // than a column one pixel wide.
    $path = builtTemplate(
        [['name' => TITLE_AND_CONTENT, 'shapes' => [['type' => 'body', 'idx' => 1]]]],
        layoutHasMaster: false,
    );

    expect((new TemplateGeometry($path))->boxes())->toBe([]);
});

it('ignores a placeholder whose box has been dragged off the page', function () {
    // A template does carry these: a shape dragged away leaves an `a:xfrm` full of zeroes
    // behind, and no deck should be laid out inside one.
    $path = builtTemplate(
        [['name' => TITLE_AND_CONTENT, 'shapes' => [
            ['type' => 'body', 'idx' => 1, 'x' => 0, 'y' => 0, 'cx' => 0, 'cy' => 0],
        ]]],
        layoutHasMaster: false,
    );

    expect((new TemplateGeometry($path))->boxes())->toBe([]);
});
