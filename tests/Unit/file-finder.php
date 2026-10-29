<?php

declare(strict_types=1);

use MarkdownPresentation\Files\DiskFinder;
use MarkdownPresentation\Tests\Support\Scratch;

/*
 * The finder: where a deck's files come from.
 *
 * The library asks for names and a finder answers, so these are about the answers —
 * a file that is read, a path that is given, a theme that resolves and a theme that
 * does not. The part worth reading twice is the last one: a finder never throws, and
 * that is what keeps the two halves of this suite able to share one finder.
 */

/**
 * A base with a file of the given name in it.
 */
function finderBase(string $name): string
{
    $base = Scratch::directoryPath();

    file_put_contents($base . '/' . $name, 'the bytes');

    return $base;
}

it('reads a file it is given', function () {
    $base = finderBase('logo.png');

    $finder = new DiskFinder(picture: [$base]);

    expect($finder->read('logo.png', 'picture'))->toBe('the bytes');
});

it('reads a theme file through the finder', function () {
    $base = Scratch::directoryPath('themefiles');
    mkdir($base . '/house');
    file_put_contents($base . '/house/theme.yml', "name: House\n");

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->read('house/theme.yml', 'theme'))->toBe("name: House\n")
        // A theme's file is named inside its theme, so a name without a theme in it
        // names no file of a theme.
        ->and($finder->read('theme.yml', 'theme'))->toBeNull();
});

it('gives the path of a file it holds, as it was written', function () {
    // The library resolves what it is given and holds it to its own roots, so a path
    // that arrives already resolved is a path it cannot check. The base is a link
    // here, because a path that resolves to itself cannot tell the two apart.
    $real = Scratch::directoryPath('real');
    file_put_contents($real . '/logo.png', 'the bytes');

    $holder = Scratch::directoryPath('holder');

    if (!@symlink($real, $holder . '/themes')) {
        test()->markTestSkipped('symlinks are not available here');
    }

    $finder = new DiskFinder(picture: [$holder . '/themes']);

    expect($finder->path('logo.png', 'picture'))->toBe($holder . '/themes/logo.png');
});

it('has no file for a name that is not there', function () {
    $base = finderBase('logo.png');

    $finder = new DiskFinder(picture: [$base]);

    expect($finder->read('logo.png', 'picture'))->toBe('the bytes')
        ->and($finder->read('nowhere.png', 'picture'))->toBeNull()
        ->and($finder->path('nowhere.png', 'picture'))->toBeNull();
});

it('takes a name that is already a path as it stands', function () {
    // A path is a path, whatever the bases are: resolving it against one would name a
    // file nobody meant.
    $base = finderBase('report.md');

    $finder = new DiskFinder(document: [$base]);

    expect($finder->read($base . '/report.md', 'document'))->toBe('the bytes')
        ->and($finder->path($base . '/report.md', 'document'))->toBe($base . '/report.md')
        ->and($finder->read($base . '/nowhere.md', 'document'))->toBeNull();
});

it('lists the themes that hold a manifest, sorted', function () {
    $base = Scratch::directoryPath('themes');

    mkdir($base . '/house');
    file_put_contents($base . '/house/theme.yml', "name: House\n");

    mkdir($base . '/zebra');
    file_put_contents($base . '/zebra/theme.yml', "name: Zebra\n");

    // A directory without a manifest is not a theme, and neither is a file that
    // happens to be called like one.
    mkdir($base . '/bare');
    file_put_contents($base . '/theme.yml', "name: Not a theme\n");

    // Nor is a directory the file system put there.
    mkdir($base . '/.hidden');
    file_put_contents($base . '/.hidden/theme.yml', "name: Hidden\n");

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->themes())->toBe(['house', 'zebra']);
});

it('looks past a base that is not there', function () {
    // A directory the caller pointed at may not exist yet, and a base with nothing in
    // it is not a failure here.
    $base = Scratch::directoryPath('present');
    mkdir($base . '/house');
    file_put_contents($base . '/house/theme.yml', "name: House\n");

    $finder = new DiskFinder(theme: [$base . '/not-there', $base]);

    expect($finder->themes())->toBe(['house'])
        ->and($finder->read('house/theme.yml', 'theme'))->toBe("name: House\n");
});

it('lists the files of a theme, sorted, and nothing for a theme that is not there', function () {
    $base = Scratch::directoryPath('files');
    mkdir($base . '/house');
    file_put_contents($base . '/house/theme.yml', "name: House\n");
    file_put_contents($base . '/house/styles.yml', "heading.1:\n");
    file_put_contents($base . '/house/cover.png', 'the bytes');

    // Neither a hidden file nor a directory is one of the theme's files.
    file_put_contents($base . '/house/.hidden', '');
    mkdir($base . '/house/assets');

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->files('house'))->toBe(['cover.png', 'styles.yml', 'theme.yml'])
        ->and($finder->files('missing'))->toBe([]);
});

it('looks for a name in the bases in the order they are given', function () {
    $first = Scratch::directoryPath('first');
    $second = Scratch::directoryPath('second');

    file_put_contents($first . '/logo.png', 'the first');
    file_put_contents($second . '/logo.png', 'the second');
    file_put_contents($second . '/only.png', 'the second only');

    $finder = new DiskFinder(picture: [$first, $second]);

    expect($finder->read('logo.png', 'picture'))->toBe('the first')
        // And the second base is looked at when the first holds nothing.
        ->and($finder->read('only.png', 'picture'))->toBe('the second only');
});

it('takes the theme of the first base that has one', function () {
    $first = Scratch::directoryPath('first');
    $second = Scratch::directoryPath('second');

    mkdir($first . '/house');
    file_put_contents($first . '/house/theme.yml', "name: First\n");

    mkdir($second . '/house');
    file_put_contents($second . '/house/theme.yml', "name: Second\n");

    $finder = new DiskFinder(theme: [$first, $second]);

    expect($finder->read('house/theme.yml', 'theme'))->toBe("name: First\n")
        ->and($finder->files('house'))->toBe(['theme.yml']);
});

it('lets a theme directory without a manifest shadow a later one', function () {
    // The theme's directory is found first and the file is looked for inside it, so a
    // directory that exists but holds no manifest is the theme: the manifest decides
    // whether it is one, and the library says so rather than falling through to a
    // theme of another name.
    $first = Scratch::directoryPath('first');
    $second = Scratch::directoryPath('second');

    mkdir($first . '/house');

    mkdir($second . '/house');
    file_put_contents($second . '/house/theme.yml', "name: Second\n");

    $finder = new DiskFinder(theme: [$first, $second]);

    expect($finder->read('house/theme.yml', 'theme'))->toBeNull()
        ->and($finder->path('house/theme.yml', 'theme'))->toBeNull()
        // The listing is of the directory the name resolves to, so it is empty rather
        // than the files of the theme that was shadowed.
        ->and($finder->files('house'))->toBe([]);
});

it('finds nothing for a role it has no bases for', function () {
    // The role says which world the name is resolved in, and a world the finder was
    // not given is not one it can answer for.
    $base = Scratch::directoryPath('somewhere');
    mkdir($base . '/house');
    file_put_contents($base . '/house/theme.yml', "name: House\n");

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->read('logo.png', 'picture'))->toBeNull()
        // The theme is there for the role the finder was given bases for.
        ->and($finder->read('house/theme.yml', 'theme'))->toBe("name: House\n");
});

it('looks in every base for a name with no role', function () {
    $themes = Scratch::directoryPath('worlds');
    $pictures = Scratch::directoryPath('picturesofthings');

    file_put_contents($pictures . '/logo.png', 'the bytes');

    $finder = new DiskFinder(theme: [$themes], picture: [$pictures]);

    expect($finder->read('logo.png'))->toBe('the bytes');
});

it('answers with the path of a file outside every base, rather than refusing it', function () {
    // The scope belongs to the render and not to the storage: the library holds every
    // path a finder answers to its own roots before it reads it. A finder that refused
    // here would throw this half's exception, which the other half would not catch.
    $outside = Scratch::directoryPath('outside');
    file_put_contents($outside . '/secret.txt', 'not yours');

    $finder = new DiskFinder(document: [Scratch::directoryPath('inside')]);

    expect($finder->path($outside . '/secret.txt', 'document'))->toBe($outside . '/secret.txt')
        ->and($finder->read($outside . '/secret.txt', 'document'))->toBe('not yours');
});

it('does not throw for a name it cannot answer', function () {
    // A name that walks out of the base, a name that is a URL, a name holding a null
    // byte: none of them is an error. They are names that resolve to nothing, and the
    // finder answers null rather than throwing.
    $base = finderBase('logo.png');

    $finder = new DiskFinder(theme: [$base], picture: [$base], document: [$base]);

    expect($finder->read('../elsewhere/secret.txt', 'document'))->toBeNull()
        ->and($finder->read('phar://payload.phar/theme.yml', 'theme'))->toBeNull()
        ->and($finder->read("logo.png\0.txt", 'picture'))->toBeNull()
        ->and($finder->path('../elsewhere/secret.txt', 'document'))->toBeNull();
});
