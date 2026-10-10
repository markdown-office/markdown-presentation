<?php

declare(strict_types=1);

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

 /**
  * What a deck and a theme become on disk.
  *
  * Nothing here compares bytes. A zip carries two-second timestamps and `docProps/core.xml`
  * carries created and modified dates, so two correct renders of one deck differ; every
  * assertion is on a part, which is what {@see Pptx} is for.
 */

/**
 * Render a deck and open what came out, in one step — every test below needs both and
 * writing them out each time would bury the assertion in the arrangement.
 *
 * `$directory` is where a relative image path resolves, and it defaults to the directory
 * {@see image()} writes its fixture into, so a test that renders an image does not have to
 * say where it put one.
*/
function renderDeck(string $markdown, ?string $directory = null): Pptx
{
    $deck = (new DeckParser())->parse($markdown);
    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer($directory ?? imageDirectory()))->render($deck, $path);

    return Pptx::open($path);
}

/**
 * A directory of its own for image fixtures, so a deck that names a file cannot pick up
 * somebody else's of the same name from a test that ran first.
 *
 * Recreated on every call because the scratch directory is emptied after each test, and a
 * remembered path would then be a directory that no longer exists.
*/
function imageDirectory(): string
{
    $directory = Scratch::directory() . '/images';

    if (!is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    return $directory;
}

/**
 * A real PNG of a given size, on disk.
 *
 * Drawn rather than carried as a base64 blob so the size is stated in the test and a
 * fixture nobody can read is not a fixture: `PHPPresentation` measures an image with
 * `getimagesize()`, so a file that is not really a PNG fails the first assertion that
 * touches it rather than the last.
*/
function png(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 10, 200, 30));

    $path = imageDirectory() . '/' . $width . 'x' . $height . '.png';
    imagepng($image, $path);

    return $path;
}

/**
 * A real image in a format a `.pptx` carries no part for, or null where this GD was
 * compiled without it.
 *
 * Null rather than a skip inside the test body, because a build without the format has
 * no such image to prove anything with and a test that quietly proves nothing is worse
 * than one that says it had nothing to check.
 */
function convertible(string $format, int $width, int $height): ?string
{
    $encoder = 'image' . $format;
    $constant = 'IMG_' . strtoupper($format);

    if (!function_exists($encoder) || !defined($constant) || (imagetypes() & (int) constant($constant)) === 0) {
        return null;
    }

    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 10, 200, 30));

    $path = imageDirectory() . '/dot.' . $format;
    $encoder($image, $path);

    return $path;
}

/**
 * An ICO, which `getimagesize()` reads and no GD build decodes.
 *
 * Built rather than carried as a blob because the two halves of the failure are the point:
 * a file PHP can *measure* but cannot *decode* reaches the refusal, where a file it cannot
 * measure at all is a different failure with a different message. An ICO written by hand
 * says which of the two happened.
 */
function ico(): string
{
    $png = png(8, 8);
    $bytes = (string) file_get_contents($png);

    // The ICO directory: reserved, type 1 for an icon, one image; then its entry — 8×8, a
    // 32-bit PNG payload, and the byte offset the payload starts at.
    $path = imageDirectory() . '/dot.ico';
    file_put_contents(
        $path,
        pack('vvv', 0, 1, 1) . pack('CCCCvvVV', 8, 8, 0, 0, 1, 32, strlen($bytes), 22) . $bytes,
    );

    return $path;
}

it('writes a file that is a readable zip with a presentation part', function () {
    $pptx = renderDeck("# One\n");

    expect($pptx->parts())->toContain('ppt/presentation.xml')
        ->and($pptx->parts())->toContain('ppt/slides/slide1.xml');
});

it('gives a slide every part PowerPoint needs to open it', function () {
    // The parts a presentation is made of, named because a missing one is a file that
    // PowerPoint refuses rather than a test that fails.
    $pptx = renderDeck("# One\n");

    foreach ([
        '[Content_Types].xml',
        '_rels/.rels',
        'ppt/_rels/presentation.xml.rels',
        'ppt/slideMasters/slideMaster1.xml',
        'ppt/slideLayouts/slideLayout1.xml',
        'ppt/theme/theme1.xml',
        'ppt/slides/slide1.xml',
    ] as $part) {
        expect($pptx->parts())->toContain($part);
    }
});

it('declares every slide in the content types', function () {
    // A slide part the package does not declare is invisible to PowerPoint, so a deck can
    // have slides on disk and still open with none of them.
    $pptx = renderDeck("# One\n\n---\n\n# Two\n");
    $types = $pptx->part('[Content_Types].xml');

    expect($types)->toContain('/ppt/slides/slide1.xml')
        ->and($types)->toContain('/ppt/slides/slide2.xml')
        ->and($pptx->slideCount())->toBe(2);
});

it('writes one slide per slide of the deck', function () {
    expect(renderDeck("# One\n\n---\n\n# Two\n\n---\n\n# Three\n")->slideCount())->toBe(3);
});

it('writes the slides in the order the deck reads them', function () {
    $pptx = renderDeck("# One\n\n---\n\n# Two\n");

    expect($pptx->texts(1))->toContain('One')
        ->and($pptx->texts(2))->toContain('Two');
});

it('gives the slide the size the geometry is written against', function () {
    // 16:9. SlideGeometry counts in pixels, so a number here is a number in the renderer,
    // and a change of slide size that the geometry does not know about breaks the mapping.
    $pptx = renderDeck("# One\n");

    expect($pptx->part('ppt/presentation.xml'))->toContain('<p:sldSz cx="9144000" cy="5143500"');
});

it('takes the deck metadata as the document properties', function () {
    $pptx = renderDeck("---\ntitle: The deck\ndescription: About it\n---\n\n# One\n");
    $core = $pptx->part('docProps/core.xml');

    expect($core)->toContain('<dc:title>The deck</dc:title>')
        ->and($core)->toContain('<dc:description>About it</dc:description>');
});

it('credits the deck that names an author, rather than the theme it was written in', function () {
    // `author:` is in the directive list and was read by nobody: `dc:creator` was the theme's
    // author whatever the deck said, so a deck written by one person and rendered in another
    // person's theme was credited to the theme's author.
    $pptx = renderDeck("---\ntheme: corporate-dark\nauthor: Fabi\n---\n\n# One\n");
    $core = $pptx->part('docProps/core.xml');

    expect($core)->toContain('<dc:creator>Fabi</dc:creator>')
        ->and($core)->not->toContain('markdown-office');
});

it('credits the theme author for a deck that names none', function () {
    $pptx = renderDeck("---\ntheme: corporate-dark\n---\n\n# One\n");

    expect($pptx->part('docProps/core.xml'))->toContain('<dc:creator>markdown-office</dc:creator>');
});

it('refuses an author that is not a string, rather than rendering a number as a name', function () {
    expect(fn () => renderDeck("---\nauthor: 42\n---\n\n# One\n"))
        ->toThrow(MalformedFrontmatter::class, 'has a "author" that is not a string');
});

it('writes a slide name that says which slide of the deck it came from', function () {
    // The name is what a presenter sees in the slide sorter, and "slide 2" is the one thing
    // that is true of both steps of a stepped slide.
    $pptx = renderDeck("# One\n\n---\n\n# Two\n");

    expect($pptx->part('ppt/slides/slide2.xml'))->toContain('<p:cSld name="slide 2"');
});

it('leaves a slide without a title unnamed rather than calling it something', function () {
    $pptx = renderDeck("Just text, no heading.\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->not->toContain('<p:cSld name=');
});

it('writes a speaker note as its own part', function () {
    $pptx = renderDeck("# One\n\n<!--\nRead this out loud.\n-->\n");

    expect($pptx->parts())->toContain('ppt/notesSlides/notesSlide1.xml')
        ->and($pptx->part('ppt/notesSlides/notesSlide1.xml'))->toContain('Read this out loud.');
});

it('writes no note for a slide that has none', function () {
    $pptx = renderDeck("# One\n");

    expect($pptx->parts())->not->toContain('ppt/notesSlides/notesSlide1.xml');
});

it('keeps a deck with no slides as one empty slide', function () {
    // PowerPoint will not open a presentation with no slides at all, so the empty slide
    // PHPPresentation's constructor leaves behind is what a deck with no slides is.
    $pptx = renderDeck('');

    expect($pptx->slideCount())->toBe(1);
});

it('reports a path there is no directory for', function () {
    $deck = (new DeckParser())->parse("# One\n");
    $path = Scratch::directory() . '/no/such/directory/deck.pptx';

    expect(fn () => (new PresentationRenderer())->render($deck, $path))
        ->toThrow(Exception::class, 'There is no directory at');
});

it('says where it wrote the file', function () {
    $deck = (new DeckParser())->parse("# One\n");
    $path = Scratch::file('deck.pptx', '');

    expect((new PresentationRenderer())->render($deck, $path))->toBe($path);
});

it('renders a heading as a title shape, not as a paragraph', function () {
    expect(renderDeck("# One\n")->shapeNames())->toBe(['title']);
});

it('writes the heading once, in the title box', function () {
    // A slide's title *is* its first heading, and that heading is still the first block of
    // the content. Drawing both would put the same line on the slide twice.
    $pptx = renderDeck("# One\n\nSome text.\n");

    expect($pptx->texts(1))->toBe(['One', 'Some text.']);
});

it('writes a heading carrying inline markup once, and as markup rather than as characters', function () {
    // The one spelling a model writes in an H1. The title box was handed the heading as it
    // was written and the heading was compared against that after its own markup had been
    // taken out, so the two could never match: the title read "Release **v2** notes" and
    // the same line was drawn again underneath it as three runs.
    $pptx = renderDeck("# Release **v2** notes\n\nBody.\n");

    expect($pptx->shapeNames())->toBe(['title', 'paragraph'])
        ->and($pptx->texts(1))->toBe(['Release ', 'v2', ' notes', 'Body.'])
        ->and($pptx->part('ppt/slides/slide1.xml'))->toContain('b="1"');
});

it('writes the title heading once even where a paragraph comes before it', function () {
    // The title is the first *heading*, not the first block: a slide that opens with a line
    // of prose still draws its heading in the title box, and the body copy of it was the
    // same line on the slide twice.
    $pptx = renderDeck("Lead.\n\n# The title\n");

    expect($pptx->shapeNames())->toBe(['title', 'paragraph'])
        ->and($pptx->texts(1))->toBe(['The title', 'Lead.']);
});

it('keeps a heading that only repeats the title, because it is not the one that became it', function () {
    // Only the slide's first block is compared, so a heading further down is content the
    // author wrote and dropping every heading that reads like the title would take it out.
    $pptx = renderDeck("---\ntitle: One\n---\n\n# One\n\n## One\n");

    expect($pptx->shapeNames())->toBe(['title', 'heading'])
        ->and($pptx->texts(1))->toBe(['One', 'One']);
});

it('writes a title named in the frontmatter with its markup, like any other text', function () {
    // A title is a string in a frontmatter block and a run of markup in a title box, and
    // the box is the one that can show what was written. Drawn as characters, a title of
    // "Ops **runbook**" is a deck about asterisks.
    $pptx = renderDeck("---\ntitle: Ops **runbook**\n---\n\nBody.\n");

    expect($pptx->texts(1))->toBe(['Ops ', 'runbook', 'Body.']);
});

it('writes the deck title into the document properties as text, without its markup', function () {
    // The properties part is metadata: nothing on the other end of dc:title interprets a
    // pair of asterisks, and it is read in file browsers and search results rather than on
    // a slide.
    $core = renderDeck("---\ntitle: Ops **runbook**\n---\n\n# One\n")->part('docProps/core.xml');

    expect($core)->toContain('<dc:title>Ops runbook</dc:title>')
        ->and($core)->not->toContain('**');
});

it('draws a heading that is not the title in the body', function () {
    $pptx = renderDeck("# Title\n\n## A section\n");

    expect($pptx->shapeNames())->toBe(['title', 'heading']);
});

it('leaves a heading that became the title out of the body', function () {
    expect(renderDeck("# One\n\nSome text.\n")->shapeNames())->toBe(['title', 'paragraph']);
});

it('draws a heading that is not the first heading of the slide', function () {
    $pptx = renderDeck("# One\n\n## Two\n\n## Three\n");

    expect($pptx->shapeNames())->toBe(['title', 'heading', 'heading']);
});

it('keeps a title written in the frontmatter even when the content has no heading', function () {
    $pptx = renderDeck("---\ntitle: Named\n---\n\nSome text.\n");

    expect($pptx->shapeNames())->toBe(['title', 'paragraph'])
        ->and($pptx->texts(1)[0])->toBe('Named');
});

it('renders a paragraph as a paragraph', function () {
    expect(renderDeck("Just words.\n")->shapeNames())->toBe(['paragraph']);
});

it('keeps the line breaks of a paragraph the writer wrote', function () {
    // A paragraph spanning lines is one block with as many lines as it was written with, so
    // the block's height — and so where the next one starts — depends on them.
    $pptx = renderDeck("One\nTwo\nThree\n");

    expect($pptx->texts(1))->toBe(["One\nTwo\nThree"]);
});

it('renders a bullet list as one list shape', function () {
    expect(renderDeck("- one\n- two\n")->shapeNames())->toBe(['list']);
});

it('renders an ordered list as one list shape', function () {
    expect(renderDeck("1. one\n2. two\n")->shapeNames())->toBe(['list']);
});

it('numbers an ordered list rather than bulleting it', function () {
    // A list written `1. 2. 3.` that comes out as three bullets reads as a mistake even
    // though nothing is missing.
    $pptx = renderDeck("1. one\n2. two\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<a:buAutoNum type="arabicPeriod"/>')
        ->and($pptx->part('ppt/slides/slide1.xml'))->not->toContain('<a:buChar');
});

it('starts an ordered list at the number the deck names', function () {
    // `3. three` drawn as `1.` is a list that does not say what the deck said.
    //
    // The number is `startAt`, and PHPPresentation writes the start of a numbering on every
    // paragraph of it the way PowerPoint does — both paragraphs say three, and the second
    // counts on to four rather than beginning a numbering of its own.
    $xml = renderDeck("3. three\n4. four\n")->part('ppt/slides/slide1.xml');

    expect($xml)->toContain('<a:buAutoNum type="arabicPeriod" startAt="3"/>')
        ->and(substr_count($xml, 'startAt="3"'))->toBe(2);
});

it('leaves a list that starts at one with no start of its own', function () {
    // The other half of the claim above: `startAt` on a list that begins at one would say
    // the deck renumbered from one, which is what a reader takes `startAt="1"` to mean.
    expect(renderDeck("1. one\n2. two\n")->part('ppt/slides/slide1.xml'))->not->toContain('startAt');
});

it('numbers a nested ordered list as a list of its own', function () {
    // Without a level of its own the sub-list is the same numbering as the one above it, so
    // `1.` under `1.` comes out as `2.`
    $xml = renderDeck("1. one\n  1. sub\n2. two\n")->part('ppt/slides/slide1.xml');

    expect(substr_count($xml, '<a:buAutoNum'))->toBe(3)
        ->and(substr_count($xml, 'lvl="1"'))->toBe(1)
        ->and(substr_count($xml, 'startAt'))->toBe(1);
});


it('draws a list that mixes bullets and numbers with the marker each item was written with', function () {
    // A deck that switches marker mid-list meant it: the numbers are not an accident of the
    // first item's.
    $xml = renderDeck("- a\n1. b\n- c\n")->part('ppt/slides/slide1.xml');

    expect(substr_count($xml, '<a:buChar char="•"/>'))->toBe(2)
        ->and(substr_count($xml, '<a:buAutoNum type="arabicPeriod"/>'))->toBe(1);
});

it('bullets a list that is not numbered', function () {
    expect(renderDeck("- one\n- two\n")->part('ppt/slides/slide1.xml'))->toContain('<a:buChar char="•"/>');
});

it('nests a list item by its indent', function () {
    $pptx = renderDeck("- one\n  - nested\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('marL="190500"');
});

it('nests a sub-list written four spaces in one level, not two', function () {
    // `intdiv(indent, 2)` made four spaces two levels, so a sub-list written at a tab's
    // width was drawn with a gutter nothing was drawn into above it. The item is one gutter
    // in, so its margin is two gutters and not three.
    $pptx = renderDeck("- one\n    - deep\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('marL="381000"')
        ->not->toContain('marL="571500"');
});

it('keeps a paragraph under a list item with it', function () {
    // A line indented under an item belongs to that item, which is CommonMark's rule and the
    // reason a wrapped list item does not become two items.
    $pptx = renderDeck("- one\n  still one\n- two\n");

    expect($pptx->shapeNames())->toBe(['list'])
        ->and($pptx->texts(1))->toBe(['one still one', 'two']);
});

it('renders a fenced code block as a code shape', function () {
    expect(renderDeck("```\nplain\n```\n")->shapeNames())->toBe(['code']);
});

it('writes the code in the mono font the theme names', function () {
    // `mono` is a theme slot, and a code block that ignored it would look wrong in every
    // deck written in a theme that cares.
    $pptx = renderDeck("---\ntheme: minimal\n---\n\n```\nplain\n```\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<a:latin typeface="Menlo"/>');
});

it('fills the code shape with the colour the theme paints its panels in', function () {
    // `dk2` on a dark theme and `lt2` on a light one. The theme's palette is not what
    // decides it: the same twelve colours give a light panel on one theme and a dark
    // panel on the other, which is the swap the colour map makes.
    $dark = renderDeck("---\ntheme: corporate-dark\n---\n\n```\nplain\n```\n");
    $light = renderDeck("---\ntheme: minimal\n---\n\n```\nplain\n```\n");

    expect($dark->fillOf('code'))->toBe('#181825')
        ->and($light->fillOf('code'))->toBe('#f5f5f5');
});

it('keeps the language a fence names where a reader can find it', function () {
    // Nothing here colours code by language, so the language would otherwise vanish into
    // nothing the file records.
    $pptx = renderDeck("```php\necho 1;\n```\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('descr="php"');
});

it('keeps an empty language out of the description', function () {
    expect(renderDeck("```\nplain\n```\n")->part('ppt/slides/slide1.xml'))->toContain('descr=""');
});

it('takes only the first word of a fence info string as the language', function () {
    expect(renderDeck("```php showLineNumbers\necho 1;\n```\n")->part('ppt/slides/slide1.xml'))
        ->toContain('descr="php"');
});

it('renders a tilde fence as code', function () {
    expect(renderDeck("~~~\nplain\n~~~\n")->shapeNames())->toBe(['code']);
});

it('renders an unclosed fence as code to the end of the slide', function () {
    // CommonMark's rule. Reading the rest of the slide as text instead is what lets one
    // stray backtick turn a slide's worth of content into one block of code.
    expect(renderDeck("```\nplain\nmore\n")->shapeNames())->toBe(['code']);
});

it('renders a pipe table as a table shape', function () {
    expect(renderDeck("| A | B |\n| --- | --- |\n| 1 | 2 |\n")->shapeNames())->toBe(['table']);
});

it('writes every cell of a table', function () {
    $pptx = renderDeck("| A | B |\n| --- | --- |\n| 1 | 2 |\n");

    expect($pptx->texts(1))->toBe(['A', 'B', '1', '2']);
});

it('writes a table cell carrying markup as runs', function () {
    // The same inline pipeline a paragraph goes through: bold, code and links are runs in a
    // cell, and a cell written `**Name**` used to reach the slide as those six characters.
    // The run and its font rather than the text alone: `Name` is one run out of four, and it
    // is the bold one.
    $pptx = renderDeck("| A |\n| --- |\n| **Name** and `id` |\n");

    expect($pptx->texts(1))->toBe(['A', 'Name', ' and ', 'id'])
        ->and($pptx->part('ppt/slides/slide1.xml'))
        ->toMatch('#<a:r>\s*<a:rPr[^>]*\bb="1"[^>]*>.*?</a:rPr>\s*<a:t><!\[CDATA\[Name\]\]></a:t>#s')
        ->toMatch('#<a:r>\s*<a:rPr[^>]*>(?:(?!</a:rPr>).)*<a:latin typeface="[^"]*"/>(?:(?!</a:rPr>).)*</a:rPr>\s*<a:t><!\[CDATA\[id\]\]></a:t>#s');
});

it('writes a link in a table cell as a link', function () {
    // `[lk](https://example.com)` drawn as characters is a sentence about Markdown sitting
    // in the middle of a table of data.
    $pptx = renderDeck("| A |\n| --- |\n| [lk](https://example.com) |\n");

    expect($pptx->texts(1))->toBe(['A', 'lk'])
        ->and($pptx->part('ppt/slides/slide1.xml'))
        ->toMatch('#<a:hlinkClick[^>]*/>(?:(?!</a:r>).)*<a:t><!\[CDATA\[lk\]\]></a:t>#s');
});

it('aligns a column the way its alignment row says', function () {
    // The alignment row is the only place a deck states alignment, so dropping it would drop
    // the one thing the writer wrote about it.
    $right = renderDeck("| A |\n| ---: |\n| 1 |\n");
    $centre = renderDeck("| A |\n| :---: |\n| 1 |\n");

    expect($right->part('ppt/slides/slide1.xml'))->toContain('algn="r"')
        ->and($centre->part('ppt/slides/slide1.xml'))->toContain('algn="ctr"');
});

it('leaves a column left when its alignment row says nothing', function () {
    expect(renderDeck("| A |\n| --- |\n| 1 |\n")->part('ppt/slides/slide1.xml'))->toContain('algn="l"');
});

it('reads a table with no outer pipes', function () {
    expect(renderDeck("| A | B |\n| --- | --- |\n| 1 | 2 |\n")->texts(1))->toBe(['A', 'B', '1', '2']);
});

it('reads a table whose cells are written without padding', function () {
    expect(renderDeck("|A|B|\n|---|---|\n|1|2|\n")->texts(1))->toBe(['A', 'B', '1', '2']);
});

it('bolds a table\'s header row and fills it, because nothing else marks one out', function () {
    // Every row was `sz="1200"` in `dk1`, so a header row and a body row were the same
    // line of type. `a:tblPr firstRow` is the flag PowerPoint's own table styles hang a
    // header treatment on, and the weight and fill are that treatment spelled out.
    $xml = renderDeck("| A | B |\n| --- | --- |\n| 1 | 2 |\n")->part('ppt/slides/slide1.xml');

    expect($xml)->toContain('<a:tblPr firstRow="1"/>')
        ->and(substr_count($xml, 'b="1"'))->toBe(2)
        ->and(substr_count($xml, '<a:srgbClr val="E7E6E6">'))->toBe(2);
});

it('leaves a table\'s body rows at the body weight', function () {
    // A header that is bold because every row is bold is not a header, so the assertion is
    // the ratio: two bold runs out of the four the table has.
    $xml = renderDeck("| A | B |\n| --- | --- |\n| 1 | 2 |\n")->part('ppt/slides/slide1.xml');

    expect(substr_count($xml, '<a:rPr '))->toBe(4)
        ->and(substr_count($xml, 'b="1"'))->toBe(2);
});

it('writes a table as tall as the rows it writes', function () {
    // `a:tr h` is emitted per row and PowerPoint honours it, so a shape declaring fewer
    // pixels than its rows carry is drawn over the block below it. Three rows here: the
    // header and the two the alignment row is not.
    $pptx = renderDeck("| A | B |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |\n");

    expect($pptx->positionOf('table')['height'])->toBe(3.0 * 38.0)
        ->and($pptx->part('ppt/slides/slide1.xml'))->toContain('<a:tr h="361950">');
});

it('starts the block after a table below the rows the table writes', function () {
    // The defect this is about was a shape measured at `rows × 26` while every row was
    // written at 38, so four rows declared 104px and the next block landed on the last one.
    $pptx = renderDeck("| A | B |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |\n| 5 | 6 |\n| 7 | 8 |\n\nAfter.\n");

    expect($pptx->positionOf('table')['height'])->toBe(5.0 * 38.0)
        ->and($pptx->positionOf('paragraph')['y'])->toBe(104.0 + 5 * 38.0 + 12.0);
});

it('renders a block quote as a quote shape', function () {
    expect(renderDeck("> quoted\n")->shapeNames())->toBe(['quote']);
});

it('draws a quote written across two lines as one shape', function () {
    // The line break is kept, so the shape is two lines tall — which is what a reader of the
    // Markdown meant by writing it over two lines.
    $pptx = renderDeck("> line one\n> line two\n");

    expect($pptx->shapeNames())->toBe(['quote'])
        ->and($pptx->texts(1))->toBe(["line one\nline two"]);
});

it('indents a block quote from the margin', function () {
    // The number rather than "greater than 40": the shape is 860 of the 880 the body box is,
    // which is what "indented by a gutter" has to mean if the block below it is to keep its
    // full width.
    expect(renderDeck("> quoted\n")->positionOf('quote')['x'])->toBe(60.0);
});

it('renders a thematic break as a rule rather than dropping it', function () {
    // `***` is a thematic break in this dialect — `---` is a slide delimiter and never
    // reaches here — so a line the author wrote is drawn rather than ignored.
    expect(renderDeck("***\n")->shapeNames())->toBe(['rule']);
});

it('writes a rule as a bar rather than as text', function () {
    // Two pixels at the top of a 16px slot, and the body's full width. A `< 5.0` would have
    // passed for a rule written at a negative height, which is the shape of the bug this
    // whole branch is about.
    $position = renderDeck("***\n")->positionOf('rule');

    expect($position['height'])->toBe(2.0)
        ->and($position['width'])->toBe(880.0)
        ->and($position['y'])->toBe(112.0);
});

it('writes a rule as a rectangle rather than as whatever an auto shape defaults to', function () {
    // PHPPresentation's AutoShape is a heart until told otherwise, so a rule drawn without
    // saying so is a heart on the slide. Asserting the negative is what makes it bite: every
    // text box on the slide is already a `rect`, so asserting that would pass either way.
    $xml = renderDeck("***\n")->part('ppt/slides/slide1.xml');

    expect($xml)->toContain('<a:prstGeom prst="rect">')
        ->and($xml)->not->toContain('prst="heart"');
});

it('writes a rule with no outline, because a line around a line is a second line', function () {
    expect(renderDeck("***\n")->part('ppt/slides/slide1.xml'))->toContain('<a:ln w="9525">');
});

it('fills a rule with the theme accent colour', function () {
    $pptx = renderDeck("---\ntheme: corporate-dark\n---\n\n***\n");

    expect($pptx->fillOf('rule'))->toBe('#89b4fa');
});

it('stacks the blocks of a slide down the body box', function () {
    // Heights decide where each block starts, so a block drawn on top of the one before it
    // is the failure that shows the two have drifted apart.
    $pptx = renderDeck("First.\n\nSecond.\n\nThird.\n");

    $paragraphs = array_values(array_filter(
        $pptx->shapeNames(),
        static fn (string $name): bool => $name === 'paragraph',
    ));

    expect($paragraphs)->toBe(['paragraph', 'paragraph', 'paragraph'])
        ->and($pptx->positionOf('paragraph', 1)['y'])->toBe(104.0);
});

it('starts a body block below the title box', function () {
    $pptx = renderDeck("# Title\n\nSome text.\n");

    expect($pptx->positionOf('paragraph')['y'])->toBeGreaterThan($pptx->positionOf('title')['y']);
});

it('leaves a gap between two blocks of a column', function () {
    // Every paragraph is written with `setSpacingBefore(0)` and `setSpacingAfter(0)`, so
    // without a gap in the layout two consecutive paragraphs abut with nothing between
    // them: one line of text against the next.
    $tops = array_column(renderDeck("First.\n\nSecond.\n")->positions(), 'y');

    expect($tops[1] - $tops[0])->toBe(38.0);
});

it('keeps two blocks from being written at the same height', function () {
    // Each block's top is the one above it plus that block's height and the gap, so two
    // blocks sharing a top is the arithmetic drifting — which shows up as one drawn over
    // the other rather than as a failure.
    $tops = array_column(renderDeck("# T\n\nA.\n\n```\nx\n```\n\nB.\n")->positions(), 'y');

    expect(array_values(array_unique($tops)))->toHaveCount(count($tops));
});

it('renders inline emphasis as a run rather than as the characters', function () {
    // `**bold**` drawn literally would be a slide full of asterisks.
    $pptx = renderDeck("Some **bold** text.\n");

    expect($pptx->texts(1))->toBe(['Some ', 'bold', ' text.']);
});

it('bolds the run a deck bolds', function () {
    expect(renderDeck("**bold**\n")->part('ppt/slides/slide1.xml'))->toContain('b="1"');
});

it('shrinks a text box that overflows rather than growing it over the block below', function () {
    // PHPPresentation defaults to `spAutoFit`, which hands the box's height to the reader:
    // a paragraph measured one line short then grows onto whatever is under it. The
    // measurement is an estimate, so a mis-estimate has to be recoverable, and shrinking the
    // type is the direction that does not draw over another shape.
    $xml = renderDeck("Text.\n\nMore text.\n")->part('ppt/slides/slide1.xml');

    expect(substr_count($xml, '<a:normAutofit/>'))->toBe(2)
        ->and($xml)->not->toContain('<a:spAutoFit/>');
});

it('shrinks a title that does not fit its box as well', function () {
    expect(renderDeck("# One\n\nText.\n")->part('ppt/slides/slide1.xml'))->toContain('<a:normAutofit/>');
});

it('italics the run a deck italics', function () {
    expect(renderDeck("*italic*\n")->part('ppt/slides/slide1.xml'))->toContain('i="1"');
});

it('draws bold italic as bold and italic, with no asterisks on the slide', function () {
    // The inner `**` used to match first, so the run came out as `"*"`, `"bolditalic"`
    // and `"* here"`: the words run together and the delimiters are printed.
    $pptx = renderDeck("***bold italic*** here\n");

    expect($pptx->texts(1))->toBe(['bold italic', ' here'])
        ->and($pptx->part('ppt/slides/slide1.xml'))->toMatch('#<a:rPr[^>]*\bb="1"[^>]*[^>]*\bi="1"#');
});

it('draws __bold__ and _italic_ as bold and italic', function () {
    // Two runs with two weights between them, rather than five runs of underscores.
    $pptx = renderDeck("__bold__ and _italic_\n");
    $xml = $pptx->part('ppt/slides/slide1.xml');

    expect($pptx->texts(1))->toBe(['bold', ' and ', 'italic'])
        ->and(substr_count($xml, ' b="1"'))->toBe(1)
        ->and(substr_count($xml, ' i="1"'))->toBe(1);
});

it('draws ~~struck~~ with a line through it', function () {
    // PowerPoint carries strikethrough on the run's own font, and the writer always
    // writes the attribute — `noStrike` on every other run — so the claim is the one run
    // that says `sngStrike`.
    $pptx = renderDeck("a ~~gone~~ b\n");

    expect($pptx->texts(1))->toBe(['a ', 'gone', ' b'])
        ->and(substr_count($pptx->part('ppt/slides/slide1.xml'), 'strike="sngStrike"'))->toBe(1);
});

it('leaves an unclosed emphasis marker as the character it is', function () {
    // A strict parser would refuse the whole paragraph over an apostrophe. A deck that
    // renders is worth more here, and the character the writer typed is on the slide.
    $pptx = renderDeck("2 * 3 and 1 *emphasis* here\n");

    expect($pptx->texts(1))->toBe(['2 * 3 and 1 ', 'emphasis', ' here']);
});

it('writes a link as its text with a target', function () {
    $pptx = renderDeck("[the docs](https://example.com)\n");

    expect($pptx->texts(1))->toBe(['the docs'])
        ->and($pptx->part('ppt/slides/slide1.xml'))->toContain('r:id=');
});

it('colours a link with the theme hyperlink colour', function () {
    // `minimal`, and the run that carries the hyperlink rather than "the colour appears
    // somewhere": under `corporate-dark` the hyperlink colour *is* the accent colour, so a
    // deck with one bullet and no link at all satisfies `usesColour()` — as does a deck
    // with nothing on it but a rule.
    $xml = renderDeck("---\ntheme: minimal\n---\n\n[docs](https://example.com)\n")
        ->part('ppt/slides/slide1.xml');

    expect($xml)->toMatch('#<a:srgbClr val="3F6FB5">(?:(?!</a:rPr>).)*<a:hlinkClick#s');
});

it('colours a link inside a heading with the hyperlink colour and not the heading\'s', function () {
    // `minimal`, because its `hlink` is a colour nothing else in a deck reaches: under
    // `corporate-dark` the hyperlink colour *is* the accent colour, so any assertion about
    // "the hyperlink colour" is satisfied by a bullet. The heading passes `dk1` for every
    // run and `$font ?? …` short-circuited the link branch, so a link in a heading was
    // `dk1`: a clickable word in the same colour as the sentence around it.
        $xml = renderDeck("---\ntheme: minimal\n---\n\n# Lead\n\n## The [docs](https://example.com)\n")
            ->part('ppt/slides/slide1.xml');

    expect($xml)->toMatch('#<a:srgbClr val="3F6FB5">(?:(?!</a:rPr>).)*<a:hlinkClick#s')
        ->and(substr_count($xml, '<a:srgbClr val="3F6FB5">'))->toBe(1);
});

it('colours a link inside a quote with the hyperlink colour and not the quote\'s', function () {
    // The same short-circuit on the quote's `dk2`: a clickable word that is the same colour
    // as the sentence around it is a word nobody clicks.
    $xml = renderDeck("---\ntheme: minimal\n---\n\n> A [quote](https://example.com)\n")
        ->part('ppt/slides/slide1.xml');

    expect($xml)->toMatch('#<a:srgbClr val="3F6FB5">(?:(?!</a:rPr>).)*<a:hlinkClick#s')
        ->and(substr_count($xml, '<a:srgbClr val="4A4A4A">'))->toBe(1);
});

it('writes an inline code span in the mono font at its own size', function () {
    $pptx = renderDeck("a `b` c\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('sz="1200"');
});

it('renders a line that is nothing but an image as an image', function () {
    $png = png(2, 2);

    expect(renderDeck(sprintf("![a dot](%s)\n", basename($png)))->shapeNames())->toBe(['image']);
});

it('embeds the image the deck names', function () {
    $png = png(2, 2);
    $pptx = renderDeck(sprintf("![a dot](%s)\n", basename($png)));

    expect(preg_grep('#^ppt/media/#', $pptx->parts()))->not->toBe([]);
});

it('scales an image to fit its box and keeps its shape', function () {
    // The numbers, not the ratio. `round($w / $h) === 4.0` passes just as happily on
    // `w = -1536, h = -384` as it does on positive ones — and it passed that way for a
    // while. The claim is that the picture took the box's width and came back the height
    // its own 4:1 gives it: 880 out of 880 × 396, so 220, centred in the 176 left over.
    $png = png(40, 10);
    $position = renderDeck(sprintf("![wide](%s)\n", basename($png)))->positionOf('image');

    expect($position['x'])->toBe(40.0)
        ->and($position['y'])->toBe(192.0)
        ->and($position['width'])->toBe(880.0)
        ->and($position['height'])->toBe(220.0);
});

it('centres an image that does not fill its box', function () {
    // A 4:1 image fills the box's width and leaves 176px above and below. Centring only the
    // horizontal would leave it hard against the top of the region, which is what the slack
    // being vertical means: 104 + 176 / 2 is 192.
    $png = png(40, 10);
    $position = renderDeck(sprintf("![wide](%s)\n", basename($png)))->positionOf('image');

    expect($position['x'])->toBe(40.0)
        ->and($position['y'])->toBe(192.0);
});

it('keeps the alt text a deck wrote for a picture', function () {
    // The alt text was parsed, stored on the block and never read: `descr` carried the
    // file path instead, which is what a screen reader reads out and what PowerPoint's
    // alt-text pane shows.
    $png = png(2, 2);

    expect(renderDeck(sprintf("![A dot](%s)\n", basename($png)))->part('ppt/slides/slide1.xml'))
        ->toContain('descr="A dot"')
        ->not->toContain(sprintf('descr="%s"', basename($png)));
});

it('keeps the path a deck wrote where a picture has no alt text to say what it is', function () {
    // An empty description says nothing at all, and the written path is the one thing about
    // the picture the deck's own author recognises.
    $png = png(2, 2);

    expect(renderDeck(sprintf("![](%s)\n", basename($png)))->part('ppt/slides/slide1.xml'))
        ->toContain(sprintf('descr="%s"', basename($png)));
});

it('writes no shape at a size the schema forbids', function () {
    // `a:ext` is an `ST_PositiveCoordinate`. Eighty paragraphs fill the body box and then
    // some, and the image below them was written as cx="-381000" cy="-95250", which
    // PowerPoint either offers to repair or drops without saying so.
    $png = png(40, 10);
    $xml = renderDeck(str_repeat("Text.\n\n", 80) . sprintf("![wide](%s)\n", basename($png)))
        ->part('ppt/slides/slide1.xml');

    preg_match_all('#<a:ext cx="(-?\d+)" cy="(-?\d+)"#', $xml, $found, PREG_SET_ORDER);

    expect($found)->not->toBe([]);

    foreach ($found as [, $cx, $cy]) {
        expect((int) $cx)->toBeGreaterThan(0)
            ->and((int) $cy)->toBeGreaterThan(0);
    }
});

it('keeps every shape of an overflowing slide inside the slide', function () {
    // A column taller than its box used to run off the bottom of the slide entirely, which
    // no amount of clamping inside the writer would have caught.
    $png = png(40, 10);
    $positions = renderDeck(str_repeat("Text.\n\n", 80) . sprintf("![wide](%s)\n", basename($png)))
        ->positions();

    expect($positions)->not->toBe([]);

    foreach ($positions as $position) {
        expect($position['x'])->toBeGreaterThanOrEqual(0.0)
            ->and($position['y'])->toBeGreaterThanOrEqual(0.0)
            ->and($position['x'] + $position['width'])->toBeLessThanOrEqual(960.0)
            ->and($position['y'] + $position['height'])->toBeLessThanOrEqual(540.0);
    }
});

it('resolves a relative image path against the directory it was given', function () {
    // A deck written on one machine and rendered on another resolves a relative path
    // against the wrong directory, so the directory is the renderer's and not a global.
    png(2, 2);
    $deck = (new DeckParser())->parse("![a dot](2x2.png)\n");
    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer(imageDirectory()))->render($deck, $path);

    expect(Pptx::open($path)->shapeNames())->toBe(['image']);
});

it('refuses to resolve an image against a directory it was not given', function () {
    // The assertion is the pair: the same deck resolves in its own directory and not in
    // another, which is what makes the directory the renderer's and not a global.
    $wrong = Scratch::directoryPath('elsewhere');
    $deck = (new DeckParser())->parse("![a dot](2x2.png)\n");
    $path = Scratch::file('deck.pptx', '');

    expect(fn () => (new PresentationRenderer($wrong))->render($deck, $path))
        ->toThrow(ImageNotFound::class, 'It resolved to "' . $wrong . '/2x2.png"');
});

it('takes an absolute image path as it is', function () {
    $png = png(2, 2);
    $deck = (new DeckParser())->parse(sprintf("![a dot](%s)\n", $png));
    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer('/nowhere'))->render($deck, $path);

    expect(Pptx::open($path)->shapeNames())->toBe(['image']);
});

it('refuses an image that is not a file, and says where it looked', function () {
    // PowerPoint embeds what is on disk, so a missing image is a shape with no picture in
    // it and no complaint from anywhere.
    expect(fn () => renderDeck("![missing](nope.png)\n"))
        ->toThrow(ImageNotFound::class, 'resolved to "' . imageDirectory() . '/nope.png"');
});

it('embeds a picture in a format a .pptx cannot carry as a PNG', function () {
    // The failure this guards: PHPPresentation's `Drawing\File` declares whatever media type
    // it is handed, so a WebP went into the package as `image/webp` — a content type the
    // format has no room for — and the deck rendered without complaint and opened with no
    // picture on it.
    $webp = convertible('webp', 40, 20);

    if ($webp === null) {
        $this->markTestSkipped('This GD build cannot write a WebP.');
    }

    $pptx = renderDeck("![a dot](dot.webp)\n");

    expect(array_values(preg_grep('#^ppt/media/#', $pptx->parts())))->not->toBe([])
        ->and(array_values(preg_grep('#^ppt/media/#', $pptx->parts()))[0])->toEndWith('.png')
        ->and($pptx->shapeNames())->toBe(['image']);
});

it('declares no media type a .pptx has no part for', function () {
    // Asserted on the content types rather than on the file name, because the file name is
    // PHPPresentation's own naming and the content type is what PowerPoint reads.
    $webp = convertible('webp', 40, 20);

    if ($webp === null) {
        $this->markTestSkipped('This GD build cannot write a WebP.');
    }

    expect(renderDeck("![a dot](dot.webp)\n")->part('[Content_Types].xml'))
        ->not->toContain('image/webp')
        ->toContain('ContentType="image/png"');
});

it('converts a BMP rather than writing a content type for one', function () {
    // A second format, so the conversion is not something that happens to WebP.
    $bmp = convertible('bmp', 40, 20);

    if ($bmp === null) {
        $this->markTestSkipped('This GD build cannot write a BMP.');
    }

    expect(renderDeck("![a dot](dot.bmp)\n")->part('[Content_Types].xml'))
        ->not->toContain('image/bmp')
        ->and(renderDeck("![a dot](dot.bmp)\n")->shapeNames())->toBe(['image']);
});

it('keeps a converted image its own shape rather than stretching it to the box', function () {
    // `Drawing\Base64` measures nothing where `Drawing\File` measures the file it was
    // given, so a converted picture that was not given its size came out stretched. The
    // numbers rather than the ratio, for the reason the PNG test above gives.
    $webp = convertible('webp', 40, 10);

    if ($webp === null) {
        $this->markTestSkipped('This GD build cannot write a WebP.');
    }

    $position = renderDeck("![wide](dot.webp)\n")->positionOf('image');

    expect($position['width'])->toBe(880.0)
        ->and($position['height'])->toBe(220.0);
});

it('leaves an image the package already carries exactly as it was', function () {
    // The negative of the conversion, and the one that says the check is a check: the part
    // in the package is the file the deck named, byte for byte.
    $png = png(40, 20);
    $pptx = renderDeck(sprintf("![a dot](%s)\n", basename($png)));

    expect($pptx->shapeNames())->toBe(['image'])
        ->and($pptx->part(array_values(preg_grep('#^ppt/media/#', $pptx->parts()))[0]))
        ->toBe((string) file_get_contents($png));
});

it('still embeds an SVG as an SVG', function () {
    // A viewBox and no `width`/`height`, which is an ordinary SVG and one that no
    // `getimagesize()` can measure on any build — so the extension is all there is to go on.
    // Treating "would not measure" as "not an image" would have refused it, and with it
    // every SVG that sizes itself by viewBox.
    file_put_contents(
        imageDirectory() . '/mark.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 20">'
        . '<rect width="40" height="20" fill="#0ac81e"/></svg>',
    );

    $pptx = renderDeck("![a mark](mark.svg)\n");

    expect(array_values(preg_grep('#^ppt/media/#', $pptx->parts()))[0])->toEndWith('.svg')
        ->and($pptx->part('[Content_Types].xml'))->toContain('ContentType="image/svg+xml"');
});

it('refuses a format nothing here can decode, and names what would work', function () {
    // An ICO is the case that is neither of the other two: the file is there, PHP measures
    // it, and no GD build decodes it — so there is no conversion to make and no deck to
    // write. It was a picture-shaped hole in the file before this was checked.
    ico();

    expect(fn () => renderDeck("![an icon](dot.ico)\n"))
        ->toThrow(
            UnsupportedImageFormat::class,
            'Slide 1 shows "dot.ico", which is ICO — a format a .pptx carries no part for, '
            . 'and this PHP has no decoder for it either. Convert it to GIF, JPEG, PNG or SVG first.',
        );
});

it('refuses a file that is not an image at all', function () {
    // A truncated download or a PDF named as a picture. `getimagesize()` answers false for
    // it, and every carried format but one is a raster, so a name like this saying nothing
    // is a file that is not the image it claims to be.
    file_put_contents(imageDirectory() . '/diagram.png', 'not an image, whatever it is called');

    expect(fn () => renderDeck("![a diagram](diagram.png)\n"))
        ->toThrow(
            UnsupportedImageFormat::class,
            'Slide 1 shows "diagram.png", which this PHP cannot read as an image at all',
        );
});

it('refuses a file that measures but cannot be decoded, which is a half-finished download', function () {
    // `getimagesize()` reads a header and stops, so a picture whose payload was cut short
    // measures perfectly well. Embedding it would put a picture-sized hole in the deck that
    // nothing at render time could explain.
    $webp = convertible('webp', 120, 90);

    if ($webp === null) {
        $this->markTestSkipped('This GD build cannot write a WebP.');
    }

    file_put_contents(
        imageDirectory() . '/truncated.webp',
        substr((string) file_get_contents($webp), 0, (int) (filesize($webp) * 0.55)),
    );

    expect(fn () => renderDeck("![a dot](truncated.webp)\n"))
        ->toThrow(UnsupportedImageFormat::class, 'has no decoder for it either');
});

it('names the slide an unusable image is on', function () {
    ico();

    expect(fn () => renderDeck("# One\n\n---\n\n# Two\n\n![an icon](dot.ico)\n"))
        ->toThrow(UnsupportedImageFormat::class, 'Slide 2');
});

it('refuses an unusable image before it writes anything', function () {
    // The same promise the frontmatter check makes: a deck that cannot be finished does not
    // leave half a file where a whole one was expected.
    ico();
    $path = Scratch::file('deck.pptx', '');
    $deck = (new DeckParser())->parse("# One\n\n---\n\n# Two\n\n![an icon](dot.ico)\n");

    try {
        (new PresentationRenderer(imageDirectory()))->render($deck, $path);
    } catch (UnsupportedImageFormat) {
        // The failure is the assertion; what is checked is what was left behind.
    }

    expect(filesize($path))->toBe(0);
});

it('names the slide an image is missing from', function () {
    expect(fn () => renderDeck("# One\n\n---\n\n# Two\n\n![missing](nope.png)\n"))
        ->toThrow(ImageNotFound::class, 'Slide 2');
});

it('reads a title attribute off an image and leaves it out of the path', function () {
    $png = png(2, 2);

    expect(renderDeck(sprintf("![a dot](%s \"A dot\")\n", basename($png)))->shapeNames())->toBe(['image']);
});

it('gives the colour scheme the theme twelve slots', function () {
    // The colour scheme is the whole reason a theme's colours belong in a `.pptx`: PowerPoint
    // reads it to decide what `accent1` means everywhere in the file.
    $scheme = renderDeck("# One\n")->colourScheme();

    expect(array_keys($scheme))->toBe([
        'dk1', 'lt1', 'dk2', 'lt2',
        'accent1', 'accent2', 'accent3', 'accent4', 'accent5', 'accent6',
        'hlink', 'folHlink',
    ]);
});

it('writes the theme colours into the colour scheme', function () {
    $scheme = renderDeck("---\ntheme: corporate-dark\n---\n\n# One\n")->colourScheme();

    expect($scheme['dk1'])->toBe('#1e1e2e')
        ->and($scheme['accent1'])->toBe('#89b4fa')
        ->and($scheme['hlink'])->toBe('#89b4fa');
});

it('paints the master background with the colour the theme paints its page in', function () {
    // Asserted inside `<p:bg>` rather than by "the colour appears somewhere": the colour
    // scheme carries every slot, so `usesColour()` is satisfied by the scheme alone and
    // says nothing about the page behind every slide.
    //
    // `lt1` on a light theme and `dk1` on a dark one. `corporate-dark` was `lt1` here —
    // a near-white page under a name that says dark — and `dark: true` is what moved it.
    $dark = renderDeck("---\ntheme: corporate-dark\n---\n\n# One\n")->part('ppt/slideMasters/slideMaster1.xml');
    $light = renderDeck("---\ntheme: minimal\n---\n\n# One\n")->part('ppt/slideMasters/slideMaster1.xml');

    expect($dark)->toMatch('#<p:bg>.*?<a:srgbClr val="1E1E2E".*?</p:bg>#s')
        ->and($light)->toMatch('#<p:bg>.*?<a:srgbClr val="FFFFFF".*?</p:bg>#s');
});

it('maps a dark theme\'s page and text the other way round', function () {
    // `bg1="lt1"` says the page is painted with the *light* token, which is the whole
    // defect: a theme whose `dk1` is near-black came out a near-white page. PowerPoint's
    // own dark themes keep the palette and swap the map, and this is that swap.
    $dark = renderDeck("---\ntheme: corporate-dark\n---\n\n# One\n")->part('ppt/slideMasters/slideMaster1.xml');

    expect($dark)->toContain('<p:clrMap bg1="dk1" tx1="lt1" bg2="dk2" tx2="lt2"');
});

it('leaves a light theme\'s colour map where it was', function () {
    $light = renderDeck("---\ntheme: minimal\n---\n\n# One\n")->part('ppt/slideMasters/slideMaster1.xml');

    expect($light)->toContain('<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2"');
});

it('writes a dark theme\'s body text in the light token', function () {
    // The map alone does not do this. A colour reaching a shape is written as a literal
    // `a:srgbClr` and the map is never consulted for it, so a dark theme whose map was
    // swapped and whose shapes were not is a dark page with near-black text on it.
    $slide = renderDeck("---\ntheme: corporate-dark\n---\n\nSome text.\n")->part('ppt/slides/slide1.xml');

    expect($slide)->toMatch('#<a:r>\s*<a:rPr[^>]*>\s*<a:solidFill>\s*<a:srgbClr val="CDD6F4">#')
        ->and($slide)->not->toContain('val="1E1E2E"');
});

it('writes a light theme\'s body text in the dark token', function () {
    $slide = renderDeck("---\ntheme: minimal\n---\n\nSome text.\n")->part('ppt/slides/slide1.xml');

    expect($slide)->toMatch('#<a:r>\s*<a:rPr[^>]*>\s*<a:solidFill>\s*<a:srgbClr val="1A1A1A">#');
});

it('writes a dark theme\'s title and headings in the light token', function () {
    $slide = renderDeck("---\ntheme: corporate-dark\n---\n\n# One\n\n## Two\n")->part('ppt/slides/slide1.xml');

    expect($slide)->toMatch('#<a:r>\s*<a:rPr[^>]*>\s*<a:solidFill>\s*<a:srgbClr val="CDD6F4">#');
});

it('writes a dark theme\'s quote in the second light token rather than the second dark one', function () {
    // `dk2` is #181825, which on the #1E1E2E page it is quoted against is the page
    // again. The quote is legible or it is not, so the slot follows the theme.
    $dark = renderDeck("---\ntheme: corporate-dark\n---\n\n> quoted\n")->part('ppt/slides/slide1.xml');
    $light = renderDeck("---\ntheme: minimal\n---\n\n> quoted\n")->part('ppt/slides/slide1.xml');

    expect($dark)->toContain('<a:srgbClr val="BAC2DE">')
        ->and($dark)->not->toContain('val="181825"')
        ->and($light)->toContain('<a:srgbClr val="4A4A4A">');
});

it('fills a dark theme\'s code block with a colour its text can be read on', function () {
    $dark = renderDeck("---\ntheme: corporate-dark\n---\n\n```\nplain\n```\n");
    $slide = $dark->part('ppt/slides/slide1.xml');

    // The fill is `dk2` and the code is `lt2`: the same two slots a light theme uses the
    // other way round. Read off the run rather than off the part, because the colour
    // scheme carries every slot the slide never draws with.
    expect($dark->fillOf('code'))->toBe('#181825')
        ->and($slide)->toMatch('#<a:r>\s*<a:rPr[^>]*>\s*<a:solidFill>\s*<a:srgbClr val="BAC2DE">#');
});

it('fills a dark theme\'s table header with a colour its text can be read on', function () {
    $xml = renderDeck("---\ntheme: corporate-dark\n---\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n")
        ->part('ppt/slides/slide1.xml');

    // The header row carries `dk2` and its type `lt1`. The count is per cell, because
    // that is where the fill is written, and a fill that reached one cell of two would
    // still put the colour in the file.
    expect(substr_count($xml, '<a:srgbClr val="181825">'))->toBe(2)
        ->and($xml)->toMatch('#<a:r>\s*<a:rPr[^>]*\bb="1"[^>]*>\s*<a:solidFill>\s*<a:srgbClr val="CDD6F4">#');
});

it('paints a dark theme\'s bullets and rule in the accent, which the swap leaves alone', function () {
    // `accent1` maps to `accent1` whichever way round the two pairs go, so these are the
    // one kind of colour that follows the theme without being changed by `dark:`.
    $dark = renderDeck("---\ntheme: corporate-dark\n---\n\n- one\n\n***\n");

    expect($dark->fillOf('rule'))->toBe('#89b4fa')
        ->and($dark->part('ppt/slides/slide1.xml'))->toMatch('#<a:buClr>\s*<a:srgbClr val="89B4FA">#');
});

it('leaves a light theme\'s colours where the map says they are', function () {
    // The other half of the claim above, and the one that catches a swap written into
    // the shape colours rather than into the theme: `minimal` says nothing about
    // darkness and has to come out exactly as it did.
    $light = renderDeck("---\ntheme: minimal\n---\n\n| A |\n| --- |\n| 1 |\n\n> quoted\n\n```\nx\n```\n");
    $slide = $light->part('ppt/slides/slide1.xml');

    expect($light->fillOf('code'))->toBe('#f5f5f5')
        ->and($slide)->toContain('<a:srgbClr val="F5F5F5">')
        ->and($slide)->toContain('<a:srgbClr val="1A1A1A">')
        ->and($slide)->toContain('<a:srgbClr val="4A4A4A">');
});

it('writes body text in the theme body font', function () {
    $pptx = renderDeck("---\ntheme: minimal\n---\n\nSome text.\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<a:latin typeface="Georgia"/>');
});

it('writes the title in the theme heading font', function () {
    // A theme whose heading and body fonts differ is a theme that is not doing its job if the
    // title is drawn in the body font.
    $pptx = renderDeck("---\ntheme: minimal\n---\n\n# One\n");

    expect($pptx->part('ppt/slides/slide1.xml'))->toContain('<a:latin typeface="Georgia"/>');
});

it('takes the default theme for a deck that names none', function () {
    // The absence of a directive, not a fallback from a directive that failed — a named theme
    // that does not resolve is still refused.
    expect(renderDeck("# One\n")->colourScheme()['accent1'])->toBe('#4472c4');
});

it('refuses a theme that does not resolve, and names the ones that do', function () {
    expect(fn () => renderDeck("---\ntheme: corprate-dark\n---\n\n# One\n"))
        ->toThrow(ThemeNotFound::class, 'There is no theme named "corprate-dark"');
});

it('refuses a theme name that is not a string', function () {
    expect(fn () => renderDeck("---\ntheme:\n  name: nested\n---\n\n# One\n"))
        ->toThrow(MalformedFrontmatter::class, 'has a "theme" that is not a theme name');
});

it('refuses a theme name that is only whitespace', function () {
    expect(fn () => renderDeck("---\ntheme: \"  \"\n---\n\n# One\n"))
        ->toThrow(MalformedFrontmatter::class, 'has a "theme" that is not a theme name');
});

it('refuses a frontmatter key nobody acts on, and lists the ones there are', function () {
    // The cost of this is that a Slidev deck full of directives this library does not read
    // will not render. The message is what makes that affordable: it names every directive
    // that does something, so the author edits the front matter rather than hunting.
    expect(fn () => renderDeck("---\ntransition: fade\n---\n\n# One\n"))
        ->toThrow(
            UnknownDirective::class,
            'The frontmatter of slide 1 names "transition", which is not a directive. '
            . 'The directives are: title, name, level, layout, theme, author, description.',
        );
});

it('refuses an unknown directive on a later slide and names that slide', function () {
    expect(fn () => renderDeck("# One\n\n---\nclicks: 3\n---\n# Two\n"))
        ->toThrow(UnknownDirective::class, 'The frontmatter of slide 2 names "clicks"');
});

it('refuses a deck directive on a later slide, and says where it belongs', function () {
    // Two slides that both name a theme is a deck that renders one of them and says nothing
    // about the other, which is the outcome this library refuses everywhere else.
    expect(fn () => renderDeck("# One\n\n---\ntheme: minimal\n---\n# Two\n"))
        ->toThrow(
            UnknownDirective::class,
            'The frontmatter of slide 2 names "theme", which is a deck directive and belongs in '
            . 'the frontmatter of slide 1.',
        );
});

it('names the slide and the directive for a misplaced deck directive', function () {
    expect(fn () => renderDeck("# One\n\n---\nauthor: someone\n---\n# Two\n"))
        ->toThrow(UnknownDirective::class, 'slide 2 names "author"');
});

it('refuses everything before it writes anything', function () {
    // A deck whose fourth slide names a directive nobody reads has to fail before it has
    // put a file where a whole one was expected, not half-way through.
    $path = Scratch::file('deck.pptx', '');

    try {
        (new PresentationRenderer())->render(
            (new DeckParser())->parse("# One\n\n---\n\n# Two\n\n---\n\n# Three\n\n---\nclicks: 2\n---\n# Four\n"),
            $path,
        );
    } catch (UnknownDirective) {
        // The failure is the assertion; what is checked is what was left behind.
    }

    expect(filesize($path))->toBe(0);
});

it('refuses a directive in the body of a later slide before it writes anything', function () {
    // The same promise, one slide further on: a directive nobody acts on is a failure
    // wherever on the deck it stands, and a deck that has written two slides of its three
    // is the half-finished file the promise is about.
    //
    // The comment is mid-slide on purpose. A comment after a slide's last line is its
    // speaker note, which the dialect has always said it is, and `<!-- v-if false -->` in
    // the notes pane is a presenter reading out what the deck wrote.
    $path = Scratch::file('deck.pptx', '');

    try {
        (new PresentationRenderer())->render(
            (new DeckParser())->parse("# One\n\n---\n\n# Two\n\n---\n\n<!-- v-if false -->\n\nThree.\n"),
            $path,
        );
    } catch (UnknownDirective) {
        // The failure is the assertion; what is checked is what was left behind.
    }

    expect(filesize($path))->toBe(0);
});