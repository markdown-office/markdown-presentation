<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\UnpatchedDependency;
use MarkdownPresentation\Fixes;

/**
 * The check that the installed PHPPresentation is the one this library was written against.
 *
 * The library needs a few methods that a released PHPPresentation does not have, and gets
 * them from a patch applied to the installed package. When the patch is not there, every
 * one of those calls is a fatal error naming a method rather than the reason, and a reader
 * of that error would not know to look at the installer.
 *
 * So the check is made once, before any work, and says what is wrong.
 */
it('accepts the PHPPresentation that carries the fixes', function () {
    expect(fn () => Fixes::require())->not->toThrow(UnpatchedDependency::class);
});

it('refuses a PHPPresentation that does not carry them', function () {
    // A class with none of the methods is what an unpatched package looks like to the
    // check, and the check is asked about it directly so that the refusal is exercised
    // whatever is installed.
    expect(fn () => Fixes::requireAll([stdClass::class => 'setFirstRow']))
        ->toThrow(UnpatchedDependency::class);
});

it('names the plugin the installer has to allow', function () {
    expect(UnpatchedDependency::withoutTheFixes()->getMessage())
        ->toContain('cweagans/composer-patches');
});
