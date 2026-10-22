<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * The PHPPresentation that is installed is not the one this library was written against.
 *
 * Not {@see InvalidInput}: nothing a deck author names fixes this, and nothing in the
 * deck is wrong. The library needs a few methods that a released PHPPresentation does
 * not have, and it gets them by asking the installer to patch the package. When the
 * patch is not there, every one of those calls is a fatal error naming a method
 * rather than the reason, so the reason is given here instead.
 */
final class UnpatchedDependency extends Exception
{
    public static function withoutTheFixes(): self
    {
        return new self(
            'PHPPresentation is installed without the fixes this library needs. The library applies '
            . 'them as a patch when it is installed, which needs the plugin cweagans/composer-patches '
            . 'to be allowed in the project that installs this library.',
        );
    }
}
