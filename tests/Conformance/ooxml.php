<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SvgRasteriser;
use MarkdownPresentation\Tests\Support\Doctest;
use MarkdownPresentation\Tests\Support\Scratch;

require_once dirname(__DIR__, 2) . '/examples/decks.php';

/*
 * Every deck this library writes, checked against the schema Microsoft's own validator
 * reads — the one the Open XML SDK Productivity Tool uses.
 *
 * Nothing in this suite could see that before: the decks were checked against the parts
 * they carry, and a deck whose XML is outside the schema passes every one of those
 * checks and opens in PowerPoint as `[Repaired]`. The validator is the oracle, and it is
 * a program in `tools/ooxml-validator/` rather than a library this package depends on:
 * nothing that ships with the library needs it, and the suite runs without it.
 *
 * A machine with no .NET SDK says what is missing and skips the test. A green tick for a
 * check that never ran is a check nobody made.
 *
 * The deck that carries a vector is a test of its own, because it is the one deck a
 * machine without a rasteriser cannot write: the library refuses the picture rather than
 * writing a diagram that has lost its words, and a machine that cannot draw one has
 * nothing to check. It is a test of its own so that a run says which deck it did not
 * check, rather than checking one deck fewer and saying nothing.
 */

it('writes decks that are valid OOXML', function () {
    skipWithoutDotnetSdk();

    $decks = writeExampleDecks(false);

    [$status, $output] = validateWithOpenXml($decks);

    // Every deck named, because a gate that checked nothing is not a gate. The validator
    // prints one line per file and how many errors it holds, so a deck missing from the
    // output is a deck that was not read — which is a failure, not a pass.
    expect($decks)->not->toBe([]);

    foreach ($decks as $deck) {
        expect($output)->toContain(basename($deck) . ': 0 error(s)');
    }

    // The exit code is the tool's own answer, and it is what a caller that reads nothing
    // else gets: 0 when every deck is clean, 1 when one of them is not, and 2 when the
    // tool could not run at all. What the tool said is the message, because a failure
    // here is a failure of the deck and the deck is what has to be looked at.
    expect($status)->toBe(0, $output);
});

it('writes the deck that carries a vector and it is valid OOXML', function () {
    skipWithoutDotnetSdk();

    // Named before they are written, because a machine whose Imagick cannot draw a
    // letter cannot draw the diagram either, and the library refuses the picture rather
    // than writing a deck with a drawing that has lost its words.
    $carrying = array_keys(array_filter(
        exampleDecks(),
        static fn (array $deck): bool => $deck['vector'],
    ));

    if (!(new SvgRasteriser())->available()) {
        test()->markTestSkipped(sprintf(
            'This build cannot rasterise an SVG, and %s carries one to be drawn.',
            implode(', ', $carrying),
        ));
    }

    $decks = writeExampleDecks(true);

    [$status, $output] = validateWithOpenXml($decks);

    expect($decks)->not->toBe([]);

    foreach ($decks as $deck) {
        expect($output)->toContain(basename($deck) . ': 0 error(s)');
    }

    expect($status)->toBe(0, $output);
});

it('finds an error in a deck that has one', function () {
    skipWithoutDotnetSdk();

    // The gate is worth nothing if it cannot fail. A validator that answered "no
    // errors" about every deck would pass the two tests above, so this one asks
    // it about a deck with an error in it and expects to be told.
    //
    // The error is an element no schema names, and the deck is otherwise the one
    // the library wrote: it still opens, so what refuses it is the schema and
    // not a broken archive.
    $decks = writeExampleDecks(false);

    expect($decks)->not->toBe([]);

    $broken = deckWithAnUnknownElement($decks[0]);

    [$status, $output] = validateWithOpenXml([$broken]);

    expect($status)->toBe(1, 'The validator did not refuse the deck:' . PHP_EOL . $output)
        ->and($output)->toContain('notAnElement');
});

/**
 * A copy of a deck, with an element in it that no schema names.
 *
 * The element goes into the shape tree of the first slide, which is where the
 * schema is strictest about what may sit there.
 */
function deckWithAnUnknownElement(string $deck): string
{
    $archive = new ZipArchive();
    $opened = $archive->open($deck);

    if (true !== $opened) {
        throw new RuntimeException(sprintf('The deck %s could not be opened.', $deck));
    }

    $parts = [];
    $slide = null;

    for ($i = 0; $i < $archive->numFiles; $i++) {
        $name = (string) $archive->getNameIndex($i);
        $parts[$name] = (string) $archive->getFromIndex($i);

        if ($slide === null
            && str_starts_with($name, 'ppt/slides/slide')
            && str_ends_with($name, '.xml')
        ) {
            $slide = $name;
        }
    }

    $archive->close();

    if ($slide === null) {
        throw new RuntimeException(sprintf('The deck %s has no slide in it.', $deck));
    }

    $broken = str_replace('<p:spTree>', '<p:spTree><p:notAnElement/>', $parts[$slide]);

    if ($broken === $parts[$slide]) {
        throw new RuntimeException(sprintf('The slide %s has no shape tree in it.', $slide));
    }

    $parts[$slide] = $broken;

    // The extension matters: the tool decides how to open a file from it, and a
    // scratch file whose name ends in a random suffix is not a deck to it.
    $path = Scratch::directoryPath('deck') . '/broken.pptx';

    file_put_contents($path, '');

    $copy = new ZipArchive();
    $copy->open($path, ZipArchive::OVERWRITE);
    foreach ($parts as $name => $contents) {
        $copy->addFromString($name, $contents);
    }
    $copy->close();

    return $path;
}



/**
 * The decks of the gallery that are written in this run, and where they are written to.
 *
 * The plan of the decks is the one `examples/decks.php` holds, because that is the file
 * the script that writes them holds it in: a list of its own here would be a second
 * answer to a question that already has one, and the two would drift. A deck the
 * gallery no longer builds and the gate still checks is the worse of the two — a gate
 * that says less than it says.
 *
 * @param bool $withVector Whether the decks that carry a vector are among them.
 *
 * @return list<string> The decks written, as paths.
 */
function writeExampleDecks(bool $withVector): array
{
    $sources = Doctest::root() . '/examples/markdown';
    $directory = Scratch::directoryPath('decks');
    $parser = new DeckParser();
    $written = [];

    foreach (exampleDecks() as $name => $deck) {
        if ($deck['vector'] !== $withVector) {
            continue;
        }

        $renderer = new PresentationRenderer($sources);

        // A deck written more than once is written once per theme, which the renderer is
        // told rather than the source: the theme in the source's own frontmatter is the
        // one the deck asked for, and the copy in the gallery is the same deck in another
        // theme.
        if ($deck['theme'] !== null) {
            $renderer->withTheme($deck['theme']);
        }

        $target = sprintf('%s/%s.pptx', $directory, $name);

        $renderer->render($parser->parse((string) file_get_contents($sources . '/' . $deck['source'] . '.md')), $target);

        $written[] = $target;
    }

    return $written;
}

/**
 * Every deck the gallery is built from: its name, the source it is built from, the
 * theme it is drawn in, and whether it carries a vector.
 *
 * @return array<string, array{source: string, theme: string|null, vector: bool}>
 */
function exampleDecks(): array
{
    $sources = Doctest::root() . '/examples/markdown';
    $decks = [];

    foreach (glob($sources . '/*.md') ?: [] as $path) {
        $stem = basename($path, '.md');

        if (isset(NOT_BUILT[$stem])) {
            continue;
        }

        $source = (string) file_get_contents($path);
        $vector = carriesVector($source);

        foreach (VARIANTS[$stem]['theme'] ?? [null] as $theme) {
            $name = $theme === null ? $stem : $stem . '-' . $theme;

            $decks[$name] = ['source' => $stem, 'theme' => $theme, 'vector' => $vector];
        }
    }

    ksort($decks);

    return $decks;
}

/**
 * Whether a deck's source carries a vector, and so needs a rasteriser to be written.
 *
 * A `.pptx` holds a vector only beside a raster of it, so the vector is drawn before it
 * goes in — and Imagick is the only thing here that draws one.
 */
function carriesVector(string $source): bool
{
    return str_contains($source, '.svg)');
}

