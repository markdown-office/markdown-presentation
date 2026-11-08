<?php

declare(strict_types=1);

use MarkdownPresentation\Tests\Support\Doctest;
use MarkdownPresentation\Tests\Support\Scratch;

/*
 * The slides example, run the way the skill bundle runs it.
 *
 * The bundle carries this half's gallery as `examples/slides/` and ships no `vendor/`, so
 * the build has to say what it needs rather than die with a fatal error that says nothing.
 * The copy is made into a tree of its own rather than run where it lies, because the
 * library *is* beside it here: a build that found this repository's `vendor/` would carry
 * on and build the decks, and the test would be about nothing.
 */

/**
 * The build of the slides example, run in a tree that holds no library.
 *
 * @return array{0: int, 1: string, 2: string, 3: string} The exit code, what it said on the
 *         standard output, what it said on the error output, and the tree it ran in.
 */
function slidesBuildWithoutLibrary(): array
{
    $directory = Scratch::directoryPath('bundle');

    mkdir($directory . '/examples', 0o777, true);

    foreach (['build.php', 'decks.php'] as $file) {
        copy(Doctest::root() . '/examples/' . $file, $directory . '/examples/' . $file);
    }

    $process = proc_open(
        [PHP_BINARY, $directory . '/examples/build.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('The build could not be started.');
    }

    $out = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out, $errors, $directory];
}

it('says what the slides example needs when the library is not beside it', function () {
    [$status, $out, $errors] = slidesBuildWithoutLibrary();

    expect($status)->toBe(1)
        ->and($errors)->toContain(
            'This build needs the library itself: put a `vendor/autoload.php` in this directory\'s parent,'
            . ' or run `composer install` in the checkout it came from.',
        )
        // On the error output and not on the standard one, and not as a PHP failure: the
        // build is there to replace a warning about a missing file.
        ->and($out)->not->toContain('This build needs the library itself')
        ->and($errors)->not->toContain('Warning')
        ->and($errors)->not->toContain('Fatal error');
});

it('stops the slides example before it builds anything', function () {
    [$status, , , $directory] = slidesBuildWithoutLibrary();

    // The build says what it needs and stops there: nothing is written.
    expect($status)->toBe(1)
        ->and(is_dir($directory . '/examples/out'))->toBeFalse()
        ->and(glob($directory . '/examples/*.pptx') ?: [])->toBe([]);
});
