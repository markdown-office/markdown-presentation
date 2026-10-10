<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\ThemeResolver;

function resolverIn(string $workingDirectory): ThemeResolver
{
    return new ThemeResolver($workingDirectory, ThemeDirectory::create([
        'builtin/theme.yml' => "name: Builtin\n",
    ], 'builtin'));
}

it('finds a theme beside the deck first', function () {
    $resolver = resolverIn(ThemeDirectory::create([
        'themes/acme/theme.yml' => "name: Acme\n",
    ], 'working'));

    expect($resolver->resolve('acme')->theme()->name())->toBe('Acme');
});

it('finds a theme that ships with the library when there is none beside the deck', function () {
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    expect($resolver->resolve('builtin')->theme()->name())->toBe('Builtin');
});

it('resolves a path, so a theme can live anywhere', function () {
    $directory = ThemeDirectory::minimal('Elsewhere');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    expect($resolver->resolve($directory)->theme()->name())->toBe('Elsewhere');
});

it('resolves a relative path against the deck, not against the process', function () {
    $resolver = resolverIn(ThemeDirectory::create([
        'themes/acme/theme.yml' => "name: Acme\n",
    ], 'working'));

    expect($resolver->resolve('./themes/acme')->theme()->name())->toBe('Acme');
});

it('resolves an absolute path as it stands', function () {
    $directory = ThemeDirectory::minimal('Absolute');
    $resolver = resolverIn(ThemeDirectory::create([], 'working'));

    expect($resolver->resolve($directory)->theme()->name())->toBe('Absolute');
});

it('lists every theme that resolves, so an unknown name can name them', function () {
    $resolver = resolverIn(ThemeDirectory::create([
        'themes/acme/theme.yml' => "name: Acme\n",
        'themes/zeta/theme.yml' => "name: Zeta\n",
        'themes/not-a-theme/readme.md' => 'no manifest here',
    ], 'working'));

    expect($resolver->available())->toBe(['acme', 'builtin', 'zeta']);
});

it('fails with every theme that does resolve, rather than falling back to one', function () {
    $resolver = resolverIn(ThemeDirectory::create([
        'themes/acme/theme.yml' => "name: Acme\n",
    ], 'working'));

    $resolver->resolve('corprate-dark');
})->throws(ThemeNotFound::class, 'There is no theme named "corprate-dark". Available: acme, builtin');

it('says so when there is no theme at all to name', function () {
    $resolver = new ThemeResolver(ThemeDirectory::create([], 'working'), ThemeDirectory::create([], 'builtin'));

    $resolver->resolve('default');
})->throws(ThemeNotFound::class, 'Available: (none)');

it('says a path that is not there is not there, without listing the names', function () {
    $working = ThemeDirectory::create([], 'working');
    $resolver = resolverIn($working);

    $resolver->resolve('./themes/nope');
})->throws(ThemeNotFound::class, 'There is no theme directory at "');

it('finds the themes that ship with the library by default', function () {
    $resolver = new ThemeResolver(ThemeDirectory::create([], 'working'));

    expect($resolver->available())->toBe(['corporate-dark', 'default', 'minimal'])
        ->and($resolver->resolve('default')->theme()->name())->toBe('Default')
        ->and($resolver->resolve('corporate-dark')->theme()->colour('accent1'))->toBe('#89B4FA');
});

it('gives a local theme the same weight as a built-in one', function () {
    $resolver = new ThemeResolver(ThemeDirectory::create([
        'themes/default/theme.yml' => "name: Local\n",
    ], 'working'));

    expect($resolver->resolve('default')->theme()->name())->toBe('Local');
});