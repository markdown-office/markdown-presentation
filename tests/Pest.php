<?php

declare(strict_types=1);

use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Tests\Support\Scratch;

pest()->afterEach(function (): void {
    Scratch::cleanUp();
});

/**
 * Skip a test that cannot say anything about a file's permissions.
 /*** Running as root, a file is readable however its mode is set, so a test about an
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
