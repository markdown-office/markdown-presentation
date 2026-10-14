<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\UnreadablePackage;
use MarkdownPresentation\Render\NotesChainPass;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\UnreadableArchive;

/**
 * A speaker note is held up by a notes master and linked back to its slide, which is what
 * PHPPresentation does not write.
 *
 * Every assertion is on a part of a built file, never on bytes.
 */
const A_NOTE = "The slide.\n\n::note\na speaker note\n::\n";

/**
 * A deck becomes on disk, rendered from a scratch directory of its own.
 */
function noted(string $markdown = A_NOTE): Pptx
{
    $path = Scratch::file('noted.pptx', '');

    (new PresentationRenderer(Scratch::directoryPath('noted')))->render(
        (new DeckParser())->parse($markdown),
        $path,
    );

    return Pptx::open($path);
}

/**
 * The relationships a built deck holds for a part, as type => target.
 *
 * @return array<string, string>
 */
function relationshipsOf(Pptx $pptx, string $part): array
{
    $rels = dirname($part) . '/_rels/' . basename($part) . '.rels';

    if (!in_array($rels, $pptx->parts(), true)) {
        return [];
    }

    preg_match_all('~<Relationship Id="[^"]+" Type="[^"]*/(\w+)" Target="([^"]+)"/>~', $pptx->part($rels), $matches);

    return array_combine($matches[1], $matches[2]) ?: [];
}

/**
 * The notes slides in a built deck.
 *
 * @return list<string>
 */
function notedSlides(Pptx $pptx): array
{
    return array_values(array_filter(
        $pptx->parts(),
        static fn (string $part): bool => preg_match('~^ppt/notesSlides/notesSlide\d+\.xml$~', $part) === 1,
    ));
}

it('gives every notes slide a relationship back to its slide and on to a notes master', function () {
    $pptx = noted();
    $notes = notedSlides($pptx);

    expect($notes)->not->toBe([]);

    foreach ($notes as $part) {
        expect(relationshipsOf($pptx, $part))
            ->toHaveKey('slide')
            ->toHaveKey('notesMaster');
    }
});

it('writes the notes master the notes slides hang from, with the slide master colour map', function () {
    $pptx = noted();

    // Read off the file rather than asserted as a literal, so this compares two parts of the
    // same deck: a notes master that maps a colour the slide master does not resolves every
    // note through a mapping nothing else in the deck uses.
    preg_match('~<p:clrMap[^>]*/>~', $pptx->part('ppt/slideMasters/slideMaster1.xml'), $expected);

    expect($pptx->parts())->toContain('ppt/notesMasters/notesMaster1.xml')
        ->and($pptx->part('ppt/notesMasters/notesMaster1.xml'))
        ->toContain($expected[0])
        ->toContain('<p:notesStyle/>');
});

it('declares the notes master content type and points the presentation at it', function () {
    $pptx = noted();
    $presentation = $pptx->part('ppt/presentation.xml');

    expect($pptx->part('[Content_Types].xml'))
        ->toContain('PartName="/ppt/notesMasters/notesMaster1.xml"')
        ->and(relationshipsOf($pptx, 'ppt/presentation.xml'))
        ->toHaveKey('notesMaster', 'notesMasters/notesMaster1.xml');
});

it('lists the notes master between the slide masters and the slides', function () {
    // The order is the claim: `CT_Presentation` is a sequence, so the element is valid after
    // `sldMasterIdLst` and invalid after `sldIdLst`, and a file with it in the wrong place is
    // out of order however correct each list inside it is.
    $presentation = noted()->part('ppt/presentation.xml');

    preg_match('~<p:notesMasterId r:id="(rId\d+)"/>~', $presentation, $listed);
    $id = $listed[1] ?? '';

    expect($id)->not->toBe('')
        ->and(strpos($presentation, '<p:notesMasterIdLst>'))
        ->toBeGreaterThan(strpos($presentation, '<p:sldMasterIdLst>'))
        ->toBeLessThan(strpos($presentation, '<p:sldIdLst>'));
});

it('leaves no notes slide without the relationships that hold it up', function () {
    // The package-graph claim, read over every deck the examples build rather than over the
    // one deck this file renders: a notes slide with no `_rels` beside it is what PowerPoint
    // opens and what a consumer resolving the relationship cannot find.
    $pptx = noted();

    foreach (notedSlides($pptx) as $part) {
        $rels = dirname($part) . '/_rels/' . basename($part) . '.rels';

        expect($pptx->parts())->toContain($rels);
    }
});

it('writes nothing at all into a deck with no speaker note in it', function () {
    // A deck with no note has nothing to hang, so the file comes out the way the writer wrote
    // it rather than carrying a notes master that leads to nothing.
    $path = Scratch::file('no-note.pptx', '');

    (new PresentationRenderer(Scratch::directoryPath('noted')))->render(
        (new DeckParser())->parse("The slide.\n"),
        $path,
    );

    expect(NotesChainPass::applyTo($path))->toBe(0)
        ->and(Pptx::open($path)->parts())->not->toContain('ppt/notesMasters/notesMaster1.xml');
});

it('changes nothing when it runs over a deck it has already linked', function () {
    $path = Scratch::file('noted-twice.pptx', '');

    (new PresentationRenderer(Scratch::directoryPath('noted')))->render(
        (new DeckParser())->parse(A_NOTE),
        $path,
    );

    $before = Pptx::open($path)->parts();

    expect(NotesChainPass::applyTo($path))->toBe(0)
        ->and(Pptx::open($path)->parts())->toBe($before);
});

it('falls back to the format colour map when the deck has no slide master to copy one from', function () {
    // The fallback is the mapping the format defines rather than one chosen here, so the test
    // is that it is the documented mapping and not an empty or half-written element.
    $path = Scratch::file('no-master.pptx', '');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/slides/slide1.xml', '<p:sld/>');
    $zip->addFromString(
        'ppt/slides/_rels/slide1.xml.rels',
        '<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide"'
            . ' Target="../notesSlides/notesSlide1.xml"/></Relationships>',
    );
    $zip->addFromString('ppt/notesSlides/notesSlide1.xml', '<p:notes/>');
    $zip->close();

    expect(NotesChainPass::applyTo($path))->toBe(1);

    $master = Pptx::open($path)->part('ppt/notesMasters/notesMaster1.xml');

    expect($master)->toContain('bg1="lt1"')->toContain('tx1="dk1"')->toContain('folHlink="folHlink"')
        ->toContain('<p:notesStyle/>');
});

it('gives no part two relationships with the same id', function () {
    // A `.rels` part this pass rebuilds must not gain a second copy of what it already held:
    // the relationship ids in `presentation.xml.rels` are not consecutive, so a pass numbering
    // its own from one would collide with a relationship already in the part.
    $rels = noted()->part('ppt/_rels/presentation.xml.rels');

    preg_match_all('~Id="(rId\d+)"~', $rels, $ids);

    expect($ids[1])->toBe(array_values(array_unique($ids[1])));
});

it('does not list the notes master twice in a package that already lists it', function () {
    // The state the two guards in the pass exist for: a package where the presentation already
    // names a notes master but the notes slide is not linked to one — a half-linked package,
    // and the only one where adding what is missing can duplicate what is there.
    $path = Scratch::file('half-linked.pptx', '');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString(
        'ppt/presentation.xml',
        '<p:presentation><p:notesMasterIdLst><p:notesMasterId r:id="rId2"/></p:notesMasterIdLst><p:sldIdLst/></p:presentation>',
    );
    $zip->addFromString('ppt/_rels/presentation.xml.rels', '<Relationships Id="rId2"/>');
    $zip->addFromString(
        '[Content_Types].xml',
        '<Types><Override PartName="/ppt/notesMasters/notesMaster1.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml"/></Types>',
    );
    $zip->addFromString('ppt/slides/slide1.xml', '<p:sld/>');
    $zip->addFromString(
        'ppt/slides/_rels/slide1.xml.rels',
        '<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide"'
            . ' Target="../notesSlides/notesSlide1.xml"/></Relationships>',
    );
    $zip->addFromString('ppt/notesSlides/notesSlide1.xml', '<p:notes/>');
    $zip->close();

    expect(NotesChainPass::applyTo($path))->toBe(1);

    $pptx = Pptx::open($path);

    expect(substr_count($pptx->part('ppt/presentation.xml'), '<p:notesMasterIdLst>'))->toBe(1)
        ->and(substr_count($pptx->part('[Content_Types].xml'), 'notesMaster1.xml'))->toBe(1)
        ->and(relationshipsOf($pptx, 'ppt/notesSlides/notesSlide1.xml'))->toHaveKey('notesMaster');
});

it('says so when the presentation cannot be read at all', function () {
    $path = sys_get_temp_dir() . '/mdp-note-chain-not-a-deck.pptx';
    file_put_contents($path, 'not a zip');

    expect(fn () => NotesChainPass::applyTo($path))->toThrow(UnreadablePackage::class);

    @unlink($path);
});

it('says so when a part inside it cannot be read, and writes no part back as zero bytes', function () {
    // The reader is the one `tests/Unit/notes-colour-map.php` asserts, because whether a given
    // archive refuses to inflate a payload is the build's inflater's business rather than one
    // thing on every build.
    //
    // Hand-built rather than rendered, because the renderer has already run this pass and a
    // package it has linked is one the pass leaves alone — which never reaches the read.
    // `presentation.xml` is the part made unreadable rather than the notes slide: this pass
    // never opens the notes slide, it reads the slide that names it, so an unreadable notes
    // slide is a part it never asks for and the test would prove nothing.
    $path = Scratch::file('noted-unreadable.pptx', '');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/presentation.xml', '<p:presentation><p:sldIdLst/></p:presentation>');
    $zip->addFromString('ppt/_rels/presentation.xml.rels', '<Relationships Id="rId1"/>');
    $zip->addFromString('ppt/slides/slide1.xml', '<p:sld/>');
    $zip->addFromString(
        'ppt/slides/_rels/slide1.xml.rels',
        '<Relationships><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide"'
            . ' Target="../notesSlides/notesSlide1.xml"/></Relationships>',
    );
    $zip->addFromString('ppt/notesSlides/notesSlide1.xml', '<p:notes/>');
    $zip->close();

    $archive = new UnreadableArchive('ppt/presentation.xml');
    $archive->open($path);

    expect(fn () => NotesChainPass::applyTo($path, $archive))->toThrow(UnreadablePackage::class);

    $after = new ZipArchive();
    $after->open($path);

    for ($index = 0; $index < $after->numFiles; $index++) {
        expect((int) $after->statIndex($index)['size'])->toBeGreaterThan(0);
    }

    $after->close();
});