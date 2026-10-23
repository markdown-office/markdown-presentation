<?php

declare(strict_types=1);

/*
 * The install instruction, and what keeps it true.
 *
 * The library applies a patch to the PHPPresentation it installs, because the
 * release carries neither the writer's table headers nor the reader's picture paths.
 * The patch is applied by a plugin, and Composer runs a plugin only when the project
 * that installs this library allows it — so a consumer needs one line in their
 * composer.json, and without it the install stops.
 *
 * The line is the whole of what is asked of a consumer, so the guard checks that the
 * README carries it as the consumer would copy it, and that it says nothing about
 * stability: every dependency is a stable release, and a consumer who relaxes their
 * stability for nothing has been asked to give up a guarantee.
 *
 * What is asked for is read from the manifest, so the instruction follows the library
 * rather than the other way round: when the patch goes, and the pin becomes a release
 * that carries the fixes on its own, the line stops being asked for and the guard says
 * so.
 */

/**
 * The README, as it is on disk.
 */
function installationReadme(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
}

/**
 * The manifest of this library, as a consumer is given it.
 *
 * @return array<string, mixed>
 */
function installationManifest(): array
{
    return json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

it('asks a consumer for the plugin that applies the patch', function () {
    $manifest = installationManifest();
    $patches = $manifest['extra']['patches'] ?? [];

    expect($patches)->not->toBe([]);

    expect(installationReadme())
        ->toContain('"cweagans/composer-patches": true');
});

it('asks a consumer for no stability the install does not need', function () {
    $manifest = installationManifest();

    // A pin at a commit of a development branch is a development version, and the
    // default stability of `stable` will not install one.
    $needsIt = str_contains(
        (string) ($manifest['require']['phpoffice/phppresentation'] ?? ''),
        'dev-',
    );

    expect(installationReadme())->not->toContain('minimum-stability');
    expect($needsIt)->toBeFalse();
});
