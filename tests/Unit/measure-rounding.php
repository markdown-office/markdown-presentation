<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\UnreadablePackage;
use MarkdownPresentation\Render\MeasureRoundingPass;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\UnreadableArchive;

/**
 * The spacing measures in a built file are whole numbers, because `ST_TextSpacingPoint` is an
 * integer type and PowerPoint repairs a deck rather than opening one that carries a fraction.
 *
 * Every assertion is on a part of a built file or on the rounding itself, never on bytes.
 */

/**
 * A deck whose measures come out fractional without the pass.
 *
 * Every heading depth is here rather than one, because which of them is fractional depends on
 * the theme's sizes and on `HEADING_BELOW`: `0.35 * 22` is `7.699999999999999` while
 * `0.35 * 20` is exactly `7`. A test naming a single depth would one day stop failing against
 * the writer it is about the moment those sizes change.
 */
const FRACTIONAL_DECK = "The slide.\n\n# one\n\n## two\n\n### three\n\n#### four\n\n##### five\n";

/**
 * What a deck becomes on disk, rendered from a scratch directory of its own.
 */
function rounded(string $markdown): Pptx
{
    $path = Scratch::file('measured.pptx', '');
    $deck = (new DeckParser())->parse($markdown);

    (new PresentationRenderer(Scratch::directoryPath('measured')))->render($deck, $path);

    return Pptx::open($path);
}

/**
 * Every spacing measure in every part of a package, as `part => measure`.
 *
 * @return array<string, string>
 */
function measuresIn(Pptx $pptx): array
{
    $found = [];

    foreach ($pptx->parts() as $part) {
        preg_match_all('~<a:spc(?:Pts|Pct) val="([^"]+)"~', $pptx->part($part), $matches);

        foreach ($matches[1] as $measure) {
            $found[$part . ' ' . $measure] = $measure;
        }
    }

    return $found;
}

it('rounds a fractional point measure to the nearest whole number', function () {
    // The shape of the defect, taken from the file rather than described: a heading's trailing
    // gap is `0.35` of the heading's own size, and `0.35 * 22` is `7.699999999999999` in
    // binary floating point, which the writer multiplies by a hundred and prints whole.
    expect(MeasureRoundingPass::round('<a:spcAft><a:spcPts val="769.9999999999999"/></a:spcAft>'))
        ->toBe('<a:spcAft><a:spcPts val="770"/></a:spcAft>')
        ->and(MeasureRoundingPass::round('<a:spcPts val="1609.9999999999998"/>'))
        ->toBe('<a:spcPts val="1610"/>')
        ->and(MeasureRoundingPass::round('<a:spcPts val="804.9999999999999"/>'))
        ->toBe('<a:spcPts val="805"/>');
});

it('rounds the percent measure the same way', function () {
    // A sibling element in the same vocabulary, and the writer multiplies it the same way, so
    // a pass that only reached `spcPts` would leave the other one to make PowerPoint repair.
    expect(MeasureRoundingPass::round('<a:lnSpc><a:spcPct val="109999.99999999999"/></a:lnSpc>'))
        ->toBe('<a:lnSpc><a:spcPct val="110000"/></a:lnSpc>');
});

it('leaves a whole number as the characters the writer wrote', function () {
    // Rep printing a float that is already whole would make this pass report work it did not
    // do, and would rewrite a part nothing was wrong with on every build.
    expect(MeasureRoundingPass::round('<a:spcPts val="770"/>'))->toBe('<a:spcPts val="770"/>')
        ->and(MeasureRoundingPass::round('<a:spcPts val="0"/>'))->toBe('<a:spcPts val="0"/>')
        ->and(MeasureRoundingPass::round('<a:spcPct val="100000"/>'))->toBe('<a:spcPct val="100000"/>');
});

it('leaves text that only looks like a measure alone', function () {
    expect(MeasureRoundingPass::round('<a:blip r:embed="769.9999999999999"/>'))
        ->toBe('<a:blip r:embed="769.9999999999999"/>')
        ->and(MeasureRoundingPass::round('<p:cSld name="769.9999999999999"/>'))
        ->toBe('<p:cSld name="769.9999999999999"/>');
});

it('leaves no fractional spacing measure in a built deck', function () {
    // The end-to-end claim, and the one that fails without the pass. Every measure in every
    // part has to be a whole number, because PowerPoint refuses the presentation rather than
    // reading the gap a hundredth of a point out of round.
    $pptx = rounded(FRACTIONAL_DECK);

    expect($pptx->parts())->not->toBe([]);

    $fractional = array_filter(
        measuresIn($pptx),
        static fn (string $measure): bool => preg_match('~^[+-]?[0-9]+$~', $measure) !== 1,
    );

    expect($fractional)->toBe([]);
});

it('reports how many parts it changed, and nothing for a deck it has already rounded', function () {
    $path = Scratch::file('measured-count.pptx', '');

    (new PresentationRenderer(Scratch::directoryPath('measured')))->render(
        (new DeckParser())->parse(FRACTIONAL_DECK),
        $path,
    );

    // The renderer has already run this pass, so this is a second run over a file whose
    // measures are whole numbers rather than one that found nothing to do for another reason.
    expect(MeasureRoundingPass::applyTo($path))->toBe(0);

    $before = array_map('md5', Pptx::open($path)->parts());
    $zip = new ZipArchive();
    $zip->open($path);

    for ($index = 0, $count = $zip->numFiles; $index < $count; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_starts_with($name, 'ppt/slides/slide')) {
            $zip->deleteName($name);
            $zip->addFromString($name, (string) $zip->getFromIndex($index));
        }
    }

    $zip->close();

    expect(MeasureRoundingPass::applyTo($path))->toBe(0)
        ->and(array_map('md5', Pptx::open($path)->parts()))->toBe($before);
});

it('rewrites a part that carries a fraction, and says how many', function () {
    $path = Scratch::file('measured-round.pptx', '');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/slides/slide1.xml', '<a:spcPts val="769.9999999999999"/>');
    $zip->addFromString('ppt/slides/slide2.xml', '<a:spcPts val="804.9999999999999"/>');
    $zip->addFromString('ppt/theme/theme1.xml', '<a:spcPts val="769.9999999999999"/>');
    $zip->close();

    // Three parts hold the fraction and only two of them are parts this pass walks: a theme
    // holds no paragraph spacing, so leaving it alone is the point rather than an oversight.
    expect(MeasureRoundingPass::applyTo($path))->toBe(2)
        ->and(Pptx::open($path)->part('ppt/slides/slide1.xml'))->toBe('<a:spcPts val="770"/>')
        ->and(Pptx::open($path)->part('ppt/slides/slide2.xml'))->toBe('<a:spcPts val="805"/>')
        ->and(Pptx::open($path)->part('ppt/theme/theme1.xml'))->toBe('<a:spcPts val="769.9999999999999"/>');
});

it('says so when the presentation cannot be read at all', function () {
    $path = sys_get_temp_dir() . '/mdp-measure-not-a-deck.pptx';
    file_put_contents($path, 'not a zip');

    expect(fn () => MeasureRoundingPass::applyTo($path))->toThrow(UnreadablePackage::class);

    @unlink($path);
});

it('says so when a part inside it cannot be read, and writes no part back as zero bytes', function () {
    // The harm the guard prevents, checked on the file rather than on the failure. The reader
    // is the one `tests/Unit/notes-colour-map.php` asserts, because whether an archive refuses
    // to inflate a given payload is the build's inflater's business and not one thing on every
    // build.
    $path = Scratch::file('measured-unreadable.pptx', '');

    (new PresentationRenderer(Scratch::directoryPath('measured')))->render(
        (new DeckParser())->parse(FRACTIONAL_DECK),
        $path,
    );

    $archive = new UnreadableArchive('ppt/slides/slide1.xml');
    $archive->open($path);

    expect(fn () => MeasureRoundingPass::applyTo($path, $archive))->toThrow(UnreadablePackage::class);

    $after = new ZipArchive();
    $after->open($path);

    for ($index = 0; $index < $after->numFiles; $index++) {
        expect((int) $after->statIndex($index)['size'])->toBeGreaterThan(0);
    }

    $after->close();
});