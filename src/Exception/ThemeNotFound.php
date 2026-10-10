<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A theme name or path that resolves to nothing.
 *
 * The message always lists what *is* available. A deck author — human or model —
 * that guessed wrong gets the alternatives in the failure itself rather than by
 * looking for them, which is the whole of the difference between a loud failure
 * and a silent fallback to a theme that was not asked for.
*/
final class ThemeNotFound extends InvalidInput
{
    /**
     * @param list<string> $available
    */
    public static function named(string $name, array $available): self
    {
        return new self(sprintf(
            'There is no theme named "%s". Available: %s.',
            $name,
            $available === [] ? '(none)' : implode(', ', $available),
        ));
    }

    public static function noDirectory(string $path): self
    {
        return new self(sprintf('There is no theme directory at "%s".', $path));
    }

    public static function noManifest(string $path): self
    {
        return new self(sprintf('"%s" is not a theme: it has no theme.yml.', $path));
    }
}