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
 *
 * The rest of the section says what the plugin is for, and a consumer who has to
 * find that out from the failure is a consumer who has been told too late: the
 * requirement names the plugin, the consequence names it again, and the methods the
 * patch adds are read out of the class that checks for them — so a patch that grows a
 * method makes the page a list of the methods there are.
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

/**
 * The installation section, as it is on the page.
 */
function installationSection(): string
{
    $readme = installationReadme();

    return preg_match('~^## Installation$(.*?)^## ~ms', $readme, $section) === 1 ? $section[1] : '';
}

/**
 * The paragraphs of the installation section, as they are on the page.
 *
 * @return list<string>
 */
function installationParagraphs(): array
{
    $paragraphs = [];

    foreach (preg_split('#\n\s*\n#', installationSection()) ?: [] as $paragraph) {
        $paragraph = trim($paragraph);

        if ($paragraph !== '') {
            $paragraphs[] = $paragraph;
        }
    }

    return $paragraphs;
}

/**
 * The sentence that states the requirement.
 *
 * The sentence rather than the paragraph, because the paragraph says more than the
 * requirement does: a plugin named two sentences later is not named where the
 * requirement is stated.
 */
function installationRequirement(): string
{
    $section = installationSection();
    $at = strpos($section, 'has to allow');

    if ($at === false) {
        return '';
    }

    $end = strpos($section, '.', $at);

    return $end === false ? substr($section, $at) : substr($section, $at, $end - $at);
}

/**
 * The methods the library requires from the installed PHPPresentation.
 *
 * Read out of the class that checks for them rather than written down here, so that
 * a patch which grows a method makes the page a list of the methods there are.
 *
 * @return array<class-string, string> The class each method belongs to, and the method.
 */
function requiredFixes(): array
{
    $required = (new ReflectionClass(\MarkdownPresentation\Fixes::class))->getConstant('REQUIRED');

    if (! is_array($required)) {
        return [];
    }

    $fixes = [];

    foreach ($required as $class => $method) {
        if (is_string($class) && is_string($method) && class_exists($class)) {
            $fixes[$class] = $method;
        }
    }

    return $fixes;
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

it('names the plugin where the requirement is stated', function () {
    // The sentence that says the project has to allow the plugin is the sentence a
    // consumer stops at, so the plugin is named in it rather than a sentence later.
    $requirement = installationRequirement();

    expect($requirement)->not->toBe('');
    expect($requirement)->toContain('cweagans/composer-patches');
});

it('states what a consumer gets without the plugin', function () {
    $readme = installationReadme();

    // Two failures, and a consumer meets the first at the install and the second at
    // the render: the install stops with Composer's own message, and a conversion
    // against an unpatched package raises the library's own.
    expect($readme)->toContain('the install stops');

    $consequences = array_values(array_filter(
        installationParagraphs(),
        static fn (string $paragraph): bool => str_contains($paragraph, 'UnpatchedDependency'),
    ));

    expect($consequences)->toHaveCount(1);
    expect($consequences[0])->toContain('cweagans/composer-patches');
});

it('names every method the library requires', function () {
    $fixes = requiredFixes();

    expect($fixes)->not->toBe([]);

    $readme = installationReadme();
    $missing = [];

    foreach ($fixes as $class => $method) {
        $named = (new ReflectionClass($class))->getShortName() . '::' . $method;

        if (! str_contains($readme, $named)) {
            $missing[] = $named;
        }
    }

    expect($missing)->toBe([]);
});
