<?php

declare(strict_types=1);

use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Tests\Support\Doctest;
use MarkdownPresentation\Tests\Support\Scratch;

pest()->afterEach(function (): void {
    Scratch::cleanUp();
});

/**
 * Skip a test that cannot say anything about a file's permissions.
 * Running as root, a file is readable however its mode is set, so a test about an
 * unreadable one has nothing left to check. It is reported as skipped rather than
 * passed: in a root container a green tick is a check that never ran.
*/
function skipWithoutPermissions(string $what): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        PHPUnit\Framework\Assert::markTestSkipped($what . ' — running as root, which can read it anyway');
    }
}

/**
 * The scope a deck's own directory gives an image, for a test that parses or renders one.
 *
 * What the renderer builds for itself out of its directory and whatever extra roots the
 * calling program allowed.
 *
 * @param list<string> $roots
 */
function deckScope(string $directory, array $roots = []): PathScope
{
    return new PathScope([$directory, ...$roots]);
}

/**
 * The part every test about the first slide's contents reads, the deck two of them
 * convert, and the layout a `layouts.yml` maps a content slide to.
 *
 * `TWO_SLIDES` is the smallest deck there is two slides of. Every test about where a split
 * falls needs one where falling it somewhere else is visible, and the two titles are the
 * halves most of those assertions are compared against.
 *
 * Here rather than in either file that needs them, because a Pest test file is included
 * into one global namespace: a constant declared in two of them is the *same* constant
 * declared twice — PHP warns about it on every run and says it will be an error in PHP 9.
 */
const SLIDE_ONE = 'ppt/slides/slide1.xml';

const TWO_SLIDES = "# One\n\n---\n\n# Two\n";

const TITLE_AND_CONTENT = 'Title and Content';

/**
 * Skips the test where the machine cannot run the validator.
 *
 * The validator is a .NET program, so the SDK is what runs it. A machine without it runs
 * the rest of the suite and says what is missing.
 */
function skipWithoutDotnetSdk(): void
{
    if (dotnetSdkIsThere()) {
        return;
    }

    test()->markTestSkipped(
        'The .NET SDK is not installed, so the Open XML validator cannot be run. '
        . 'The job that runs this test in continuous integration installs it.',
    );
}

/**
 * Whether this machine has the .NET SDK the validator is built with.
 *
 * The version rather than the path, because a machine with the runtime and not the SDK
 * can run a program but not build one, and a path lookup would find the runtime and
 * answer yes.
 */
function dotnetSdkIsThere(): bool
{
    exec('dotnet --version 2>&1', $output, $status);

    return $status === 0;
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
