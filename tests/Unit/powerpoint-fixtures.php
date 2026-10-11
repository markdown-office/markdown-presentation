<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\Box;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Render\TemplateGeometry;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\LayoutRole;

/*
 * Two decks PowerPoint itself wrote, read.
 *
 * Every other template test in this suite is checked against `tests/fixtures/themes/templated/`,
 * which this repository wrote from a description. That file is a good template and a poor
 * PowerPoint file: it has one master, layouts written by the same hand as the reader, and
 * placeholders that spell out every attribute the format lets them spell out. The decks in
 * `tests/fixtures/powerpoint/` were authored in PowerPoint 16.113.4 and saved by it, and they are
 * messier than anything this library has written: a page that is A4 rather than 16:9, a layout
 * whose content placeholder carries no `type`, two whose placeholders carry no position at all.
 *
 * So these tests are about the reader meeting PowerPoint's own conventions rather than ours, and
 * the first of them is a bug that only PowerPoint's output could have shown.
 */

/** The directory the two decks live in. */
function powerpointFixtures(): string
{
    return dirname(__DIR__) . '/fixtures/powerpoint';
}

function harbourLights(): string
{
    return powerpointFixtures() . '/harbour-lights.pptx';
}

function workshopNotes(): string
{
    return powerpointFixtures() . '/workshop.pptx';
}

it('reads the layouts of a deck PowerPoint designed', function () {
    $boxes = (new TemplateGeometry(harbourLights()))->boxes();

    expect($boxes)->toHaveKey('Title Slide')
        ->and($boxes)->toHaveKey('Title and Content')
        ->and($boxes)->toHaveKey('Two Content')
        ->and($boxes)->toHaveKey('Picture with Caption')
        ->and($boxes['Title Slide']['title']->x)->toBe(119)
        ->and($boxes['Title Slide']['title']->y)->toBe(189);
});

it('reads the layouts of a deck that was only worked in', function () {
    $boxes = (new TemplateGeometry(workshopNotes()))->boxes();

    // The awkward deck is on A4 rather than a slide shape, so every box is scaled by the ratio
    // of its page rather than by a fixed number of EMU per pixel.
    expect($boxes)->toHaveKey('Title and Content')
        ->and($boxes)->toHaveKey('Two Content')
        ->and($boxes)->toHaveKey('Picture with Caption')
        ->and($boxes['Title and Content']['title']->x)->toBe(66)
        ->and($boxes['Title and Content']['title']->width)->toBe(828);
});

it('takes a bare ph for the body PowerPoint writes without naming it a body', function () {
    // PowerPoint writes the content placeholder of *Title and Content* as `<p:ph idx="1"/>`: no
    // `type`, because `body` is the default the schema gives it. Matching a layout's placeholder
    // to the master's by the attribute as written asked the master for `/1`, which it does not
    // have — its own says `body/1` — so the layout inherited nothing, lost its body box, and a
    // theme mapped to this layout got a title box in the right place and a body in the wrong one.
    //
    // The box below is the master's, scaled: 8543925 EMU across a 9906000 EMU page is 828 pixels
    // of 960, and 4351338 EMU down a 6858000 EMU one is 343.
    $boxes = (new TemplateGeometry(workshopNotes()))->boxes();

    expect($boxes['Title and Content'])->toHaveKeys(['title', 'body'])
        ->and($boxes['Title and Content']['body'][0]->x)->toBe(66)
        ->and($boxes['Title and Content']['body'][0]->y)->toBe(144)
        ->and($boxes['Title and Content']['body'][0]->width)->toBe(828)
        ->and($boxes['Title and Content']['body'][0]->height)->toBe(343);
});

it('reads a layout whose placeholders are all left where the master put them', function () {
    // PowerPoint writes `<p:ph type="dt">`, `<p:ph type="ftr">` and `<p:ph type="sldNum">` into
    // every one of its layouts with no position of their own, and leaves the title of *Title and
    // Content* unplaced too. A reader that wanted them would inherit three boxes it cannot draw
    // in; a reader that takes them anyway would put a date where nothing asked for one.
    expect((new TemplateGeometry(harbourLights()))->boxes()['Title and Content']['title'])
        ->toEqual(new Box(53, 48, 677, 104));
});

it('leaves a layout out that carries nothing it can draw in', function () {
    // Office's own *Blank* is four empty groups. A role mapped to it keeps the geometry the
    // library puts it at rather than inheriting an empty column.
    expect((new TemplateGeometry(harbourLights()))->boxes())->not->toHaveKey('Blank');
});

it('takes the picture column first out of a layout that has one', function () {
    // *Picture with Caption* numbers its picture placeholder `idx="1"` and its caption `idx="2"`,
    // and it is the caption that is filled first. The library draws the picture into the first
    // column and the text into the second, which is the only order that puts the text under a
    // full-width picture rather than beside it.
    $boxes = (new TemplateGeometry(harbourLights()))->boxes()['Picture with Caption'];

    expect($boxes['body'][0]->y)->toBe(48)
        ->and($boxes['body'][0]->height)->toBe(303)
        ->and($boxes['body'][1]->y)->toBe(423)
        ->and($boxes['body'][1]->height)->toBe(53);
});

it('orders a comparison layout the way its indices say, not the way the file lists them', function () {
    // *Comparison* writes four content placeholders with indices 1 to 4, of which two are `body`
    // and two are `obj`. All four are body columns to this library, and they come out in index
    // order: heading, text, heading, text.
    $columns = (new TemplateGeometry(harbourLights()))->boxes()['Comparison']['body'];

    expect(count($columns))->toBe(4)
        ->and($columns[0]->y)->toBe(170)
        ->and($columns[1]->y)->toBe(216)
        ->and($columns[2]->x)->toBe(401)
        ->and($columns[3]->y)->toBe(216);
});

it('scales an A4 page onto the one this library writes', function () {
    // The right-hand column of *Two Content* starts 490 points down an A4 page, which is 780
    // points wide, so it is at 490 / 780 of the way across — 603 pixels of 960, of which the
    // margin is 66. Dividing by the 9525 EMU a pixel instead would put it at 613, and the
    // column would end at 1021: off the right-hand edge of the slide entirely.
    $boxes = (new TemplateGeometry(workshopNotes()))->boxes();

    expect($boxes['Two Content']['body'][1]->x)->toBe(486)
        ->and($boxes['Two Content']['body'][1]->x + $boxes['Two Content']['body'][1]->width)->toBe(894)
        ->and($boxes['Two Content']['body'][0]->x)->toBe(66);
});

it('draws a slide in the geometry a real deck states', function () {
    // The claim that makes a template worth shipping, checked against a file this library did
    // not write: the layout part the deck is given and the slide on it agree, so *Reset*
    // restores what the slide already has.
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Harbour\n",
        'layouts.yml' => "layouts:\n  content: Title and Content\n",
        'template.pptx' => file_get_contents(harbourLights()),
    ]);

    $path = Scratch::file('from-harbour.pptx', '');

    (new PresentationRenderer())->render(
        (new DeckParser())->parse("---\ntheme: {$theme}\n---\n# One\n\nText.\n"),
        $path,
    );

    $pptx = Pptx::open($path);

    expect($pptx->positionOf('title'))->toEqual($pptx->layoutPlaceholders((int) $pptx->layoutOf(1))['title'])
        ->and($pptx->positionOf('title')['x'])->toBe(53.0);
});

it('draws a body in the box the deck states rather than the one the library would', function () {
    // *Title and Content* in the awkward deck puts its body at 66, 144 and 828 wide. The
    // library's own content column is 40, 104 and 880 — so a deck whose theme says nothing at
    // all and one whose theme is this deck are visibly different files, which is the point of
    // carrying it.
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Workshop\n",
        'layouts.yml' => "layouts:\n  content: Title and Content\n",
        'template.pptx' => file_get_contents(workshopNotes()),
    ]);

    $path = Scratch::file('from-workshop.pptx', '');

    (new PresentationRenderer())->render(
        (new DeckParser())->parse("---\ntheme: {$theme}\n---\n# One\n\nText.\n"),
        $path,
    );

    $pptx = Pptx::open($path);

    expect($pptx->positions()[1]['x'])->toBe(66.0)
        ->and($pptx->positions()[1]['width'])->toBe(828.0);
});

it('draws both columns of a two-content slide where a real deck puts them', function () {
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Harbour\n",
        'layouts.yml' => "layouts:\n  two-column: Two Content\n",
        'template.pptx' => file_get_contents(harbourLights()),
    ]);

    $path = Scratch::file('two-columns.pptx', '');

    (new PresentationRenderer())->render(
        (new DeckParser())->parse("---\ntheme: {$theme}\nlayout: two-column\n---\n# Both\n\nLeft.\n\n## Right\n\nRight side.\n"),
        $path,
    );

    $shapes = Pptx::open($path)->positions();

    expect($shapes[1]['x'])->toBe(53.0)
        ->and($shapes[1]['width'])->toBe(329.0)
        ->and($shapes[3]['x'])->toBe(401.0);
});

it('leaves a role whose layout the deck does not have where the library puts it', function () {
    // The precedence is the same as it is for a written template: a template fills in the roles
    // it covers and says nothing about the rest. A `layouts.yml` naming a layout the deck does
    // not have is therefore not an error — it is a role kept where the library puts it, which is
    // the only thing a half-matched theme can mean.
    $theme = ThemeDirectory::create([
        'theme.yml' => "name: Harbour\n",
        // A section mapped to a layout neither deck has, which is the ordinary way a theme is
        // written and then used against a deck nobody checked it against.
        'layouts.yml' => "layouts:\n  content: Title and Content\n  section: Section Divider\n",
        'template.pptx' => file_get_contents(harbourLights()),
    ]);

    $path = Scratch::file('unmapped.pptx', '');

    (new PresentationRenderer())->render(
        (new DeckParser())->parse("---\ntheme: {$theme}\nlayout: section\n---\n# One\n\nText.\n"),
        $path,
    );

    expect(Pptx::open($path)->positionOf('title')['y'])->toBe(
        (float) (new SlideGeometry())->title(LayoutRole::Section)['box']->y,
    );
});

it('names nothing in either deck that PowerPoint did not write', function () {
    // A `layouts.yml` matches a template by the name its layout part carries, so the names are
    // the whole interface. They are checked here as a list because a rename in PowerPoint would
    // otherwise show up as a theme that silently stops matching: no error, and a deck drawn in
    // the library's geometry rather than the deck's.
    $harbour = array_keys((new TemplateGeometry(harbourLights()))->boxes());
    $workshop = array_keys((new TemplateGeometry(workshopNotes()))->boxes());

    expect($harbour)->toContain('Title and Content')
        ->and($harbour)->toContain('Picture with Caption')
        ->and($workshop)->toContain('Title and Content')
        ->and($workshop)->toContain('Comparison')
        ->and($harbour)->not->toContain('')
        ->and($workshop)->not->toContain('')
        ->and(array_values(array_intersect($harbour, ['Blank', 'Title Only'])))->toBe(['Title Only']);
});

it('carries no name of the machine it was authored on', function () {
    // PowerPoint writes the account's name into `docProps/core.xml` on every deck it saves. It is
    // checked rather than assumed, because a fixture nobody can publish is a fixture nobody can
    // read, and the check is what makes the one beside it — `build/scrub.php` — a rule rather
    // than a one-off.
    foreach ([harbourLights(), workshopNotes()] as $deck) {
        $pptx = Pptx::open($deck);
        $core = $pptx->part('docProps/core.xml');

        preg_match('#<cp:lastModifiedBy>([^<]*)</cp:lastModifiedBy>#', $core, $by);
        preg_match('#<dc:creator>([^<]*)</dc:creator>#', $core, $creator);

        expect($by[1] ?? '')->toBe('')
            ->and($creator[1] ?? '')->toBe('');

        // A path is the other thing a machine leaves behind, and it is not always in a docProps
        // part: a relationship or a hyperlink can carry one. The staging directory is named here
        // because that is where these decks were saved before they were copied here, and it is
        // the one path that would be in the file if the scrubber had missed a part.
        foreach ($pptx->parts() as $part) {
            if (!str_ends_with($part, '.xml')) {
                continue;
            }

            expect($pptx->part($part))
                ->not->toMatch('#(/Users/|/home/|[A-Z]:\\\\Users\\\\)#')
                ->and($pptx->part($part))->not->toContain('markdown-presentation-fixtures');
        }
    }
});