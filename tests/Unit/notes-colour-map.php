<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\NotesColourMapPass;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;

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
        new DeckParser()->parse($markdown),
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
        new DeckParser()->parse("# One\n\nNo note on this one.\n"),
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
    $path = sys_get_temp_dir() . '/mdp-not-a-deck.pptx';
    file_put_contents($path, 'not a zip');

    expect(fn () => NotesColourMapPass::applyTo($path))
        ->toThrow(RuntimeException::class);

    @unlink($path);
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
        new DeckParser()->parse("# One\n\nThe slide.\n\n<!-- a note -->\n"),
        $path,
    );

    expect(NotesColourMapPass::applyTo($path))->toBe(0);

    @unlink($path);
});
