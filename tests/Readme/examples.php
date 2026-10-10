<?php

declare(strict_types=1);

use MarkdownPresentation\Tests\Support\Doctest;

/*
 * Every example in README.md, run.
/**
 * The mechanism is in tests/Support/Doctest.php and the rules are on the page
 * itself, in "How this page is checked": a fenced block followed by a fenced block
 * of expected output is a claim, and it is executed. The alternative — a file that
 * lists the examples and what each one is for — drifts from the page in both
 * directions and nothing notices, which is what this replaces.
 *
 * Each case here is the same shape whatever the block is written in, so adding an
 * example to the page adds a case without touching this file.
*/

$readme = Doctest::root() . '/README.md';
$markdown = (string) file_get_contents($readme);

it('every claim on the page holds', function (string $language, string $code, string $expected) {
    expect(rtrim(Doctest::run($language, $code)))->toBe(rtrim($expected));
})->with(Doctest::cases($markdown));

it('every file the page shows is the file on disk', function (string $code, string $file) {
    expect($code)->toBe(rtrim((string) file_get_contents($file), "\n"));
})->with(Doctest::fileClaims($markdown));

it('has examples on the page for it to run', function () use ($markdown) {
    // A README with no examples passes the two tests above by having nothing to
    // check, and the page is where this library is read.
    expect(Doctest::cases($markdown))->not->toBe([])
        ->and(Doctest::fileClaims($markdown))->not->toBe([]);
});

it('names a package the page can be installed as', function () {
    // `composer require` cannot be run here: the package is not published yet. What
    // can be checked is that the name on the page is the name in the manifest — the
    // drift that matters, and the one that is invisible until somebody follows the
    // page and gets a 404.
    $composer = json_decode(
        (string) file_get_contents(Doctest::root() . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    preg_match('/composer require ([\w.-]+\/[\w.-]+)/', (string) file_get_contents(Doctest::root() . '/README.md'), $match);

    expect($match[1] ?? null)->toBe($composer['name']);
});