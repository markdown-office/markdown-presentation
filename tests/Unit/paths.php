<?php

declare(strict_types=1);

use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Tests\Support\Scratch;

/**
 * The containment a deck-written path has to pass, on its own rather than through a render.
 *
 * Every other test reaches this through an image or a theme, where a mistake shows up as a
 * message rather than as the question being asked here: whether `..` and a symlink are
 * outside, whether a sibling whose name begins with the root's is outside, and whether a
 * stream wrapper is a URL.
 */

/**
 * A file of a known contents, in a directory of its own.
 */
function secretFile(string $prefix = 'outside'): string
{
    $directory = Scratch::directoryPath($prefix);

    file_put_contents($directory . '/creds.svg', '<svg xmlns="http://www.w3.org/2000/svg"><desc>secret</desc></svg>');

    return $directory . '/creds.svg';
}

it('resolves a path to the file it really is', function () {
    $path = secretFile();

    expect(PathScope::canonical($path))->toBe(realpath($path))
        ->and(PathScope::canonical(dirname($path) . '/./creds.svg'))->toBe(realpath($path))
        ->and(PathScope::canonical(dirname($path) . '/../' . basename(dirname($path)) . '/creds.svg'))
        ->toBe(realpath($path));
});

it('says a path that is not there is not there', function () {
    expect(PathScope::canonical(Scratch::directory() . '/nope.svg'))->toBeNull();
});

it('answers false rather than raising for a path holding a null byte', function () {
    // `realpath()` answers a `ValueError` for one rather than a false, which is an `Error`
    // rather than an `Exception` and would escape every `catch` this library documents.
    // A deck can write a null byte in a path, so it is answered here.
    expect(PathScope::canonical(Scratch::directory() . "/dot\0.png"))->toBeNull();
});

it('holds what is inside a root', function () {
    $path = secretFile();
    $scope = deckScope(dirname($path));

    expect($scope->holds($path))->toBeTrue()
        ->and($scope->holds(dirname($path) . '/creds.svg'))->toBeTrue();
});

it('holds a root itself, so a caller may allow one file rather than a directory', function () {
    $path = secretFile();

    expect(deckScope($path)->holds($path))->toBeTrue();
});

it('does not hold a sibling whose name begins with the root', function () {
    // The whole of what a string prefix test is worth: `/srv/deck-elsewhere` begins with
    // `/srv/deck` and is a different directory on the same disk.
    $project = Scratch::directoryPath('project');
    mkdir($project . '/deck');
    mkdir($project . '/deck-elsewhere');
    file_put_contents($project . '/deck-elsewhere/creds.svg', '<svg/>');

    expect(deckScope($project . '/deck')->holds($project . '/deck-elsewhere/creds.svg'))->toBeFalse();
});

it('does not hold a path that walks out of the root', function () {
    $path = secretFile();
    $scope = deckScope(Scratch::directoryPath('deck'));

    expect($scope->holds(dirname(dirname($path)) . '/outside/creds.svg'))->toBeFalse();
});

it('does not hold a symlink whose target is outside', function () {
    // The path the deck wrote is inside the root and the file it reaches is not, which is
    // the pair a prefix test on the written path cannot tell. The scope takes the resolved
    // path, so the link itself is what is refused.
    $path = secretFile();
    $link = Scratch::directoryPath('deck') . '/creds.svg';
    symlink($path, $link);
    $scope = deckScope(dirname($link));

    expect($scope->holds($link))->toBeTrue()
        ->and($scope->holds(PathScope::canonical($link) ?? ''))->toBeFalse();
});

it('holds a path under any of the roots it was given', function () {
    $first = secretFile('first');
    $second = secretFile('second');

    expect(deckScope(dirname($first), [dirname($second)])->holds($first))->toBeTrue()
        ->and(deckScope(dirname($first), [dirname($second)])->holds($second))->toBeTrue();
});

it('names the roots it holds in their own spelling', function () {
    // For a failure to say what would have worked, and a root that does not exist cannot be
    // one: it would be refused whatever it resolved to.
    $path = secretFile();
    $scope = deckScope(dirname($path), [Scratch::directory() . '/never-created']);

    expect($scope->roots())->toBe([realpath(dirname($path))]);
});

it('names the same root once however often it was given', function () {
    $path = secretFile();
    $root = dirname($path);

    expect(deckScope($root, [$root, $root . '/'])->roots())->toBe([realpath($root)]);
});

it('names a stream wrapper for what it is', function () {
    // `is_url()` as the check reads here would accept a Windows drive letter, which is a path
    // and not a URL, so the rule is `scheme://` and nothing shorter.
    expect(PathScope::isUrl('phar://payload.phar/dot.png'))->toBeTrue()
        ->and(PathScope::isUrl('PHAR://payload.phar/dot.png'))->toBeTrue()
        ->and(PathScope::isUrl('https://example.com/dot.png'))->toBeTrue()
        ->and(PathScope::isUrl('compress.zlib://dot.png'))->toBeTrue()
        ->and(PathScope::isUrl('/etc/hosts'))->toBeFalse()
        ->and(PathScope::isUrl('../creds.svg'))->toBeFalse()
        ->and(PathScope::isUrl('creds.svg'))->toBeFalse()
        ->and(PathScope::isUrl('C:\\creds.svg'))->toBeFalse();
});