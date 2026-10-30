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
 */

it('writes decks that are valid OOXML', function () {
    if (!hasDotnetSdk()) {
        test()->markTestSkipped(
            'The .NET SDK is not installed, so the Open XML validator cannot be run. '
            . 'The job that runs this test in continuous integration installs it.',
        );
    }

    $decks = writeExampleDecks();

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

/**
 * Whether this machine has the .NET SDK the validator is built with.
 *
 * The version rather than the path, because a machine with the runtime and not the SDK
 * can run a program but not build one, and a path lookup would find the runtime and
 * answer yes.
 */
function hasDotnetSdk(): bool
{
    exec('dotnet --version 2>&1', $output, $status);

    return $status === 0;
}

/**
 * Every deck the examples are written as, written into the scratch directory.
 *
 * The plan of the decks is the one `examples/decks.php` holds, because that is the file
 * the script that writes them holds it in: a list of its own here would be a second
 * answer to a question that already has one, and the two would drift. A deck the
 * gallery no longer builds and the gate still checks is the worse of the two — a gate
 * that says less than it says.
 *
 * @return list<string> The decks written, as paths.
 */
function writeExampleDecks(): array
{
    $sources = Doctest::root() . '/examples/markdown';
    $directory = Scratch::directoryPath('decks');
    $parser = new DeckParser();
    $written = [];

    foreach (glob($sources . '/*.md') ?: [] as $path) {
        $stem = basename($path, '.md');

        if (isset(NOT_BUILT[$stem])) {
            continue;
        }

        $source = (string) file_get_contents($path);

        // A deck carrying an SVG cannot be written by a build that cannot draw one, and
        // the reason names the capability rather than the deck: the library refuses that
        // picture in such a build, so there is no deck to be asked about.
        if (str_contains($source, '.svg)') && !(new SvgRasteriser())->available()) {
            test()->markTestSkipped(sprintf(
                'This build cannot rasterise an SVG, and %s carries one to be drawn.',
                $stem,
            ));
        }

        // A deck written more than once is written once per theme, which the renderer is
        // told rather than the source: the theme in the source's own frontmatter is the
        // one the deck asked for, and the copy in the gallery is the same deck in another
        // theme.
        $themes = VARIANTS[$stem]['theme'] ?? [null];

        foreach ($themes as $theme) {
            $renderer = new PresentationRenderer($sources);

            if ($theme !== null) {
                $renderer->withTheme($theme);
            }

            $name = $theme === null ? $stem : $stem . '-' . $theme;
            $target = sprintf('%s/%s.pptx', $directory, $name);

            $renderer->render($parser->parse($source), $target);

            $written[] = $target;
        }
    }

    return $written;
}

/**
 * What Microsoft's validator makes of the given decks: the exit code and what it said.
 *
 * @param list<string> $decks
 *
 * @return array{0: int, 1: string}
 */
function validateWithOpenXml(array $decks): array
{
    $command = sprintf(
        'dotnet run --project %s -- %s 2>&1',
        escapeshellarg(Doctest::root() . '/tools/ooxml-validator'),
        implode(' ', array_map('escapeshellarg', $decks)),
    );

    exec($command, $output, $status);

    return [$status, implode("\n", $output)];
}
