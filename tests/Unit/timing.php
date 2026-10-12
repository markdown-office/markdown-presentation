<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\UnreadablePackage;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\TimingPass;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\UnreadableArchive;

/**
 * The click-triggered build, against the markup PowerPoint writes for one.
 *
 * `tests/fixtures/powerpoint/entrance-animation.pptx` is a deck PowerPoint authored and saved,
 * and the first half of this file reads what it wrote rather than asserting anything about
 * this library: those are the findings `TimingPass` is built on, and they are restated here as
 * assertions so that a fixture which stops carrying them says so instead of quietly becoming
 * decoration. The second half asserts that the pass reproduces them.
 *
 * The strongest of these is the one that compares the whole element against PowerPoint's bytes.
 * Everything else about a build can be checked against a description of the format and still
 * be wrong in a way no markup inspection shows, which is why the comparison is here at all.
 */

/** The directory the deck PowerPoint authored lives in. */
function entranceAnimation(): string
{
    return dirname(__DIR__) . '/fixtures/powerpoint/entrance-animation.pptx';
}

/** The part of that deck a slide's build is written in. */
const TIMED_SLIDE = 'ppt/slides/slide3.xml';

/**
 * One part of a deck, read straight out of the file.
 */
function partOf(string $deck, string $part): string
{
    $zip = new ZipArchive();
    $zip->open($deck);
    $xml = (string) $zip->getFromName($part);
    $zip->close();

    return $xml;
}

/**
 * The `<p:timing>` element of a part, as the bytes between the two tags.
 *
 * A substring rather than a query, because the comparison it exists for is byte equality: an
 * XML reader would reorder nothing but would report these two elements equal after dropping
 * whatever it could not parse, which is the one thing a check of this kind must not do.
 */
function timingOf(string $xml): string
{
    $from = strpos($xml, '<p:timing>');
    $to = strpos($xml, '</p:timing>');

    return $from === false || $to === false
        ? ''
        : substr($xml, $from, $to - $from + strlen('</p:timing>'));
}

it('writes a build a click at a time, and nothing else', function () {
    // What a step is, read off PowerPoint rather than described: one branch whose start
    // condition waits for a click, per step. A build of two steps has two of them and not one
    // branch that deepens — which is the shape a reader of the markup would most easily guess
    // wrong, because the nesting within a step is three deep on its own.
    $timing = timingOf(partOf(entranceAnimation(), TIMED_SLIDE));

    expect(substr_count($timing, '<p:cond delay="indefinite"/>'))->toBe(2);
});

it('puts every shape of a step into that step, not one click each', function () {
    // `nodeType` is the whole of the difference. `clickEffect` opens the click and
    // `withEffect` joins the one already open, and PowerPoint writes the two effects
    // identically apart from it — so a build whose second bullet waits for a second click is
    // a build whose markup reads correctly and behaves wrongly.
    $timing = timingOf(partOf(entranceAnimation(), TIMED_SLIDE));

    expect(substr_count($timing, 'nodeType="clickEffect"'))->toBe(2)
        ->and(substr_count($timing, 'nodeType="withEffect"'))->toBe(2);
});

it('names an animated shape by its own id, and lists it in the build', function () {
    // `<p:spTgt spid>` and `<p:bldP spid>` are the same number, which is the shape's id in the
    // slide's shape tree. The title on this slide is id 2 and is in neither, so an effect that
    // named the first shape in the file rather than the first animated one would show here.
    //
    // Asserted on what the pass writes as well as on what PowerPoint wrote: the finding is one
    // this pass has to obey, and a test that only reads the fixture would report it whether or
    // not it ever did.
    $timing = timingOf(partOf(entranceAnimation(), TIMED_SLIDE));
    $written = timingOf(TimingPass::addTiming('<p:sld></p:sld>', [[3, 4], [5, 6]]));

    expect($timing)->toContain('<p:spTgt spid="3"/>')
        ->and(preg_match_all('/<p:bldP spid="(\d+)"/', $timing, $matches))->toBe(4)
        ->and($matches[1])->toBe(['3', '4', '5', '6'])
        ->and(preg_match_all('/<p:spTgt spid="(\d+)"/', $written, $targets))->toBe(4)
        ->and($targets[1])->toBe(['3', '4', '5', '6'])
        ->and(preg_match_all('/<p:bldP spid="(\d+)"/', $written, $listed))->toBe(4)
        ->and($listed[1])->toBe(['3', '4', '5', '6']);
});

it('writes appear as a visibility change and nothing more', function () {
    // `presetID="1" presetClass="entr"` and a `<p:set>` of `style.visibility` to `visible`
    // over one millisecond. No movement, no fade, nothing to time. The effect the pass writes
    // is the plainest one there is precisely so that what a reader sees is a shape that was
    // not there and then is.
    $timing = timingOf(partOf(entranceAnimation(), TIMED_SLIDE));

    expect($timing)->toContain('<p:cTn id="5" presetID="1" presetClass="entr" presetSubtype="0" fill="hold" grpId="0" nodeType="clickEffect">')
        ->and($timing)->toContain('<p:attrName>style.visibility</p:attrName>')
        ->and($timing)->toContain('<p:strVal val="visible"/>')
        ->and($timing)->not->toContain('<p:animEffect');
});

it('numbers the branches in the order it writes them', function () {
    // Two ids for the step that waits and two for each shape that appears in it, counted from
    // 3 because 1 is the root and 2 the main sequence. Nothing in a file has to agree with
    // these numbers, so nothing catches them being wrong — which is why they are read off
    // PowerPoint's own rather than derived here and assumed right.
    $timing = timingOf(partOf(entranceAnimation(), TIMED_SLIDE));

    preg_match_all('/<p:cTn id="(\d+)"/', $timing, $matches);

    expect($matches[1])->toBe(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14']);
});

it('puts the timing last in the slide, after the colour map', function () {
    // `CT_Slide` orders `cSld`, `clrMapOvr`, `transition`, `timing`, `extLst`. Anywhere else
    // is a file that is invalid for a stricter reason than the one being fixed, and a repair
    // prompt is the only symptom.
    //
    // The position is asserted on what the pass writes as well as on what PowerPoint wrote,
    // because the second is a fact about the format and the first is a thing this pass could
    // get wrong on its own — at the start of the element, or over the colour map.
    $slide = partOf(entranceAnimation(), TIMED_SLIDE);
    $written = TimingPass::addTiming('<p:sld><p:cSld/><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>', [[3, 4]]);

    expect(strpos($slide, '<p:timing>'))->toBeGreaterThan(strrpos($slide, '</p:clrMapOvr>'))
        ->and(strpos($slide, '</p:timing>'))->toBeLessThan(strrpos($slide, '</p:sld>'))
        ->and(strpos($written, '<p:timing>'))->toBeGreaterThan(strrpos($written, '</p:clrMapOvr>'))
        ->and(strpos($written, '</p:timing>'))->toBeLessThan(strrpos($written, '</p:sld>'));
});

it('writes the build of one shape per step exactly as PowerPoint wrote it', function () {
    // The claim this pass rests on, for the simplest build: two steps, one shape each, against
    // the bytes in PowerPoint's own slide. Byte equality and not "contains the right spids",
    // because a difference anywhere in this element — an attribute in the wrong place, a
    // condition left out — is invisible to an assertion that only looks for the right numbers.
    $expected = timingOf(partOf(entranceAnimation(), 'ppt/slides/slide1.xml'));
    $written = timingOf(TimingPass::addTiming('<p:sld><p:clrMapOvr/></p:sld>', [[2], [3]]));

    expect($written)->toBe($expected);
});

it('writes a step of several shapes exactly as PowerPoint wrote it', function () {
    // The case that is not a simplification of the one above: two steps of two shapes each,
    // where the second shape of each step is a `withEffect` and the ids after the first step
    // have moved on. Both of those are things a writer gets right once and then never checks
    // again, because the markup looks the same either way.
    $expected = timingOf(partOf(entranceAnimation(), TIMED_SLIDE));
    $written = timingOf(TimingPass::addTiming('<p:sld><p:clrMapOvr/></p:sld>', [[3, 4], [5, 6]]));

    expect($written)->toBe($expected);
});

it('leaves a slide with no steps exactly as the writer wrote it', function () {
    // A deck that does not build up is not a deck with an empty build written into every
    // slide: the element is not there at all, which is also what keeps the file identical.
    expect(TimingPass::addTiming('<p:sld><p:cSld/></p:sld>', []))->toBe('<p:sld><p:cSld/></p:sld>')
        ->and(TimingPass::addTiming('<p:sld><p:cSld/></p:sld>', [[]]))->toBe('<p:sld><p:cSld/></p:sld>');
});

it('writes the build once, however many times the pass runs', function () {
    // The second run is over a slide that already carries one, which is what happens to a deck
    // rendered twice into the same file. A pass that doubled the element would leave a slide
    // with two main sequences in it, which no reader of the first one would predict.
    $once = TimingPass::addTiming('<p:sld><p:clrMapOvr/></p:sld>', [[3, 4]]);
    $twice = TimingPass::addTiming($once, [[3, 4]]);

    expect($twice)->toBe($once)
        ->and(substr_count($twice, '<p:timing>'))->toBe(1);
});

it('leaves a part it does not understand alone', function () {
    // A slide part with no `p:sld` to write into is not something the Writer can produce, and
    // guessing where the element goes would be worse than writing nothing.
    expect(TimingPass::addTiming('<p:notes/>', [[3]]))->toBe('<p:notes/>');
});

it('puts the build on the slide it belongs to', function () {
    $path = sys_get_temp_dir() . '/mdp-timing-' . hash('xxh3', 'timing') . '.pptx';
    $root = dirname(__DIR__, 2);

    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse(TWO_SLIDES),
        $path,
    );

    // Slide 2 builds up and slide 1 does not, so a pass that named the wrong part, or applied
    // the build to every slide in the deck, is visible in what each of them ends up carrying.
    expect(TimingPass::applyTo($path, [2 => [[2, 3]]]))->toBe(1);

    $first = timingOf(partOf($path, 'ppt/slides/slide1.xml'));
    $second = timingOf(partOf($path, 'ppt/slides/slide2.xml'));

    @unlink($path);

    expect($first)->toBe('')
        ->and($second)->toContain('<p:spTgt spid="2"/>')
        ->and($second)->toContain('<p:spTgt spid="3"/>');
});

it('builds a stepped deck up on the slide rather than beside it', function () {
    // The wiring this pass was written for. A render used to stop at two passes and leave
    // `TimingPass` called by nothing, so every shape of a build-up was on its slide from the
    // start and the deck said nothing about which of them a click brought in.
    $path = sys_get_temp_dir() . '/mdp-timing-wired-' . hash('xxh3', 'timing wired') . '.pptx';
    $root = dirname(__DIR__, 2);

    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse("# One\n\nFirst.\n\n::click\n\nSecond.\n"),
        $path,
    );

    $pptx = Pptx::open($path);
    $slide = $pptx->part('ppt/slides/slide1.xml');
    $count = $pptx->slideCount();
    $reveals = $pptx->reveals(1);

    // Ids counted off the file rather than assumed: the shape tree's group is 1, the title is
    // written before the body and so takes 2, and the two steps' text frames take 3 and 4. A
    // `$builds` built on anything else points the effect at the title or at the first step,
    // which is a deck that hides its own heading.
    preg_match_all('/<p:cNvPr id="(\d+)" name="([^"]*)"/', $slide, $found, PREG_SET_ORDER);
    $shapes = array_map(
        static fn (array $shape): string => $shape[1] . '=' . $shape[2],
        $found,
    );

    @unlink($path);

    expect($count)->toBe(1)
        ->and($shapes)->toBe(['1=', '2=title', '3=text', '4=text'])
        ->and($slide)->toContain('<p:spTgt spid="4"/>')
        ->and($slide)->toContain('<p:bldP spid="4" grpId="0"/>')
        // The title and the first step are on the slide before any click, so neither is named.
        ->and($slide)->not->toContain('<p:spTgt spid="2"/>')
        ->and($slide)->not->toContain('<p:spTgt spid="3"/>')
        ->and($reveals)->toBe([['text']]);
});

it('writes no build on a slide that has no steps', function () {
    // A deck that does not build up is a deck with an empty `<p:timing>` written onto every
    // slide: a part that is there and does nothing, and a repair prompt waiting for a reader
    // of an older file to hit it.
    $path = sys_get_temp_dir() . '/mdp-timing-none-' . hash('xxh3', 'timing none') . '.pptx';
    $root = dirname(__DIR__, 2);

    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse(TWO_SLIDES),
        $path,
    );

    $pptx = Pptx::open($path);
    $first = $pptx->part('ppt/slides/slide1.xml');
    $reveals = [$pptx->reveals(1), $pptx->reveals(2)];

    @unlink($path);

    expect($first)->not->toContain('<p:timing')
        ->and($reveals)->toBe([[], []]);
});

it('changes each slide part once, however many steps it has', function () {
    // A pass that deleted and re-added as it walked the archive would leave the entry marked
    // for deletion rather than removed and the replacement appended, so the walk visits the
    // original again: the count grows, and the file ends up with a duplicate entry. A shape
    // assertion rather than a check that timing is present, because a pass that wrote it once
    // and then walked forever passes that.
    $path = sys_get_temp_dir() . '/mdp-timing-once-' . hash('xxh3', 'timing once') . '.pptx';
    $root = dirname(__DIR__, 2);

    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse(TWO_SLIDES),
        $path,
    );

    TimingPass::applyTo($path, [1 => [[2], [3]], 2 => [[2], [3]]]);

    $zip = new ZipArchive();
    $zip->open($path);
    $seen = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);
        $seen[$name] = ($seen[$name] ?? 0) + 1;
    }

    $zip->close();
    @unlink($path);

    expect(array_filter($seen, static fn (int $count): bool => $count > 1))->toBe([])
        ->and(array_values(array_filter(
            array_keys($seen),
            // `ppt/slides/_rels/` is under the same directory, and its parts are not slides.
            static fn (string $name): bool => preg_match('~^ppt/slides/slide\d+\.xml$~', $name) === 1,
        )))->toHaveCount(2);
});

it('says so when the presentation cannot be read at all', function () {
    // The same refusal as the other two passes, so a caller catches one type for all three.
    $path = sys_get_temp_dir() . '/mdp-timing-not-a-deck.pptx';
    file_put_contents($path, 'not a zip');

    expect(fn () => TimingPass::applyTo($path, [1 => [[2]]]))
        ->toThrow(UnreadablePackage::class, 'It is not a package this PHP can read')
        ->and(fn () => TimingPass::applyTo($path, [1 => [[2]]]))->toThrow(Exception::class);

    @unlink($path);
});

it('writes no part back as zero bytes, whatever the archive could not read', function () {
    // The harm the guard prevents, checked on the file rather than on the failure — a pass
    // that deleted the entry before finding out it could not read it would leave a slide
    // missing rather than a slide intact.
    $path = sys_get_temp_dir() . '/mdp-timing-whole-' . hash('xxh3', 'timing whole') . '.pptx';
    $root = dirname(__DIR__, 2);

    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse(TWO_SLIDES),
        $path,
    );

    $archive = new UnreadableArchive('ppt/slides/slide2.xml');
    $archive->open($path);

    expect(fn () => TimingPass::applyTo($path, [1 => [[2]], 2 => [[2]]], $archive))
        ->toThrow(UnreadablePackage::class);

    $after = new ZipArchive();
    $after->open($path);
    $sizes = [];

    for ($index = 0; $index < $after->numFiles; $index++) {
        $sizes[(string) $after->getNameIndex($index)] = (int) $after->statIndex($index)['size'];
    }

    $slide = (string) $after->getFromName('ppt/slides/slide2.xml');
    $after->close();
    @unlink($path);

    expect(array_keys(array_filter($sizes, static fn (int $size): bool => $size === 0)))->toBe([])
        ->and($slide)->toBeString()->toContain('<p:cSld');
});
