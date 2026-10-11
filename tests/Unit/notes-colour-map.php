<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\UnreadablePackage;
use MarkdownPresentation\Render\NotesColourMapPass;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\UnreadableArchive;

$root = dirname(__DIR__, 2);

/**
 * Renders a deck to a temporary file and hands back the notes slides in it.
 *
 * @return array<string, string> Part name => XML, for the notes slides only.
 */
function notesSlidesOf(string $markdown): array
{
    // A plain function does not inherit the file scope Pest runs its test bodies in.
    $root = dirname(__DIR__, 2);

    $path = sys_get_temp_dir() . '/mdp-note-' . md5($markdown) . '.pptx';

    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse($markdown),
        $path,
    );

    $zip = new ZipArchive();
    $zip->open($path);
    $notes = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_starts_with($name, 'ppt/notesSlides/') && str_ends_with($name, '.xml')) {
            $notes[$name] = (string) $zip->getFromIndex($index);
        }
    }

    $zip->close();
    @unlink($path);

    return $notes;
}

it('gives every notes slide the colour map Keynote refuses to open a file without', function () {
    // PowerPoint and LibreOffice do not notice the omission, so nothing else in this suite
    // would ever catch it. Upstream fix: PHPOffice/PHPPresentation#1034.
    $notes = notesSlidesOf("# One\n\nThe slide.\n\n<!-- a speaker note -->\n");

    expect($notes)->not->toBe([]);

    foreach ($notes as $xml) {
        expect($xml)->toContain('<p:clrMapOvr>');
    }
});

it('puts the colour map where the schema says it goes', function () {
    // `CT_NotesSlide` orders `cSld`, `clrMapOvr`, `transition`, `timing`, `extLst`. Placing
    // it elsewhere produces a file that is invalid for a stricter reason than the one being
    // fixed, so the position is asserted rather than assumed.
    $notes = notesSlidesOf("# One\n\nThe slide.\n\n<!-- a speaker note -->\n");

    foreach ($notes as $xml) {
        expect(strpos($xml, '<p:clrMapOvr>'))->toBeGreaterThan(strpos($xml, '</p:cSld>'));
    }
});

it('writes the colour map once, whatever the deck asks for', function () {
    expect(NotesColourMapPass::addColourMap('<p:notes><p:cSld/></p:notes>'))
        ->toContain('<p:clrMapOvr>')
        ->and(NotesColourMapPass::addColourMap(
            '<p:notes><p:cSld/><p:clrMapOvr><a:overrideClrMapping/></p:clrMapOvr></p:notes>',
        ))->not->toContain('<a:masterClrMapping/>');
});

it('leaves a deck with no notes exactly as the writer wrote it', function () use ($root) {
    $path = sys_get_temp_dir() . '/mdp-none-' . md5('no notes') . '.pptx';

    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse("# One\n\nNo note on this one.\n"),
        $path,
    );

    $zip = new ZipArchive();
    $zip->open($path);
    $before = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $before[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
    }

    $zip->close();

    expect(NotesColourMapPass::applyTo($path))->toBe(0);

    $zip = new ZipArchive();
    $zip->open($path);
    $after = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $after[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
    }

    $zip->close();
    @unlink($path);

    // Nothing to patch means the package is not rewritten at all, so every part is the
    // bytes the writer produced rather than a re-zipped copy of them.
    expect($after)->toBe($before);
});

it('says so when the presentation cannot be read at all', function () {
    // The one failure the README's promise is about: a bare `RuntimeException` is not a
    // `MarkdownPresentation\Exception\Exception`, so a caller following the page and
    // catching the base class did not catch it.
    $path = sys_get_temp_dir() . '/mdp-not-a-deck.pptx';
    file_put_contents($path, 'not a zip');

    expect(fn () => NotesColourMapPass::applyTo($path))
        ->toThrow(UnreadablePackage::class, 'It is not a package this PHP can read')
        ->and(fn () => NotesColourMapPass::applyTo($path))->toThrow(Exception::class);

    @unlink($path);
});

it('writes no part back as zero bytes, whatever the archive could not read', function () {
    // The harm the guard prevents, checked on the file rather than on the failure. Making a
    // part unreadable needs an archive the build's inflater will give up on, and that is the
    // build's own business: a reserved deflate block type is refused by one zlib and decoded
    // into different bytes by another, and neither a wrong CRC nor a compressed size past
    // the end of the file is refused on all of them. So the reader here is one this test
    // writes — the refusal `tests/Unit/exceptions.php` asserts — and what is asserted is
    // the part of it that holds on every build either way.
    $path = sys_get_temp_dir() . '/mdp-whole-' . md5('whole') . '.pptx';

    (new PresentationRenderer(dirname(__DIR__, 2) . '/examples/markdown'))->render(
        (new DeckParser())->parse("# One\n\nThe slide.\n\n<!-- a note -->\n"),
        $path,
    );

    // The renderer runs this pass over the package it has just written, so this is a second
    // run over one that already patched a notes slide rather than one that found nothing.
    expect(NotesColourMapPass::applyTo($path))->toBe(0);

    $archive = new UnreadableArchive('ppt/notesSlides/notesSlide1.xml');
    $archive->open($path);

    expect(fn () => NotesColourMapPass::applyTo($path, $archive))->toThrow(UnreadablePackage::class);

    $after = new ZipArchive();
    $after->open($path);
    $sizes = [];

    for ($index = 0; $index < $after->numFiles; $index++) {
        $sizes[(string) $after->getNameIndex($index)] = (int) $after->statIndex($index)['size'];
    }

    $notes = $after->getFromName('ppt/notesSlides/notesSlide1.xml');
    $after->close();
    @unlink($path);

    expect(array_keys(array_filter($sizes, static fn (int $size): bool => $size === 0)))->toBe([])
        ->and($notes)->toBeString()->toContain('<p:clrMapOvr>');
});

it('carries a note onto the right slide', function () {
    // PHPPresentation numbers a notes slide by the slide's index rather than by the order
    // the notes appear, which is why this deck has one note and one notes slide — a sparse
    // numbering that is legal and reads oddly in a zip listing. Asserted so the pass moving
    // a part cannot go unnoticed.
    $notes = notesSlidesOf("# One\n\nThe first.\n\n<!-- first note -->\n\n---\n\n# Two\n\nThe second.\n");

    expect($notes)->toHaveCount(1);
});
it('leaves a part it does not understand alone', function () {
    // A notes slide with no `cSld` is not something the Writer can produce, and patching
    // the wrong place would be worse than doing nothing to it.
    expect(NotesColourMapPass::addColourMap('<p:notes/>'))->toBe('<p:notes/>');
});

it('runs twice without adding the element a second time', function () {
    $path = sys_get_temp_dir() . '/mdp-twice-' . md5('twice') . '.pptx';

    (new PresentationRenderer(dirname(__DIR__, 2) . '/examples/markdown'))->render(
        (new DeckParser())->parse("# One\n\nThe slide.\n\n<!-- a note -->\n"),
        $path,
    );

    expect(NotesColourMapPass::applyTo($path))->toBe(0);

    @unlink($path);
});

it('writes each notes slide once, however many slides carry one', function () {
    // A pass that deletes and re-adds as it walks the archive duplicates what it
    // rewrites: the entry is marked for deletion rather than removed, the replacement
    // is appended, and the walk visits the original again. Three notes are enough to
    // show it; a deck with none passes whether the walk is sound or not, which is why
    // this is a shape assertion rather than a check that the element is present.
    $deck = "# One\n\nThe first.\n\n<!-- one -->\n\n---\n\n# Two\n\nThe second.\n\n"
        . "<!-- two -->\n\n---\n\n# Three\n\nThe third.\n\n<!-- three -->\n";

    $root = dirname(__DIR__, 2);
    $path = sys_get_temp_dir() . '/mdp-once-' . md5('once') . '.pptx';

    (new PresentationRenderer($root . '/examples/markdown'))->render(
        (new DeckParser())->parse($deck),
        $path,
    );

    $zip = new ZipArchive();
    $zip->open($path);
    $seen = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $seen[(string) $zip->getNameIndex($index)] = ($seen[(string) $zip->getNameIndex($index)] ?? 0) + 1;
    }

    $zip->close();
    @unlink($path);

    $twice = array_filter($seen, static fn (int $count): bool => $count > 1);
    $notes = array_filter(
        array_keys($seen),
        static fn (string $name): bool => str_starts_with($name, 'ppt/notesSlides/'),
    );

    expect($twice)->toBe([])->and($notes)->toHaveCount(3);
});
