<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A theme name or path that does not resolve to a theme this render may read.
 *
 * Four of them, kept apart because the fix for each is a different thing: a name that is
 * not a theme, a path that is not a directory, one that resolves outside the directories the
 * caller allowed, and one that names a stream wrapper rather than a path.
 *
 * For a name the message always lists what *is* available. A deck author — human or model —
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

    /**
     * @param list<string> $roots The directories a deck may name a theme in, which the
     *                             message names: the fix is to move the theme into one of
     *                             them or to have the program that renders the deck allow
     *                             where it is.
     */
    public static function outside(string $name, string $found, array $roots): self
    {
        return new self(sprintf(
            'The theme "%s" is a directory at "%s" rather than one this render may read (%s). Copy it under the '
            . 'working directory, or give the ThemeResolver that directory as one of its additional roots.',
            $name,
            $found,
            implode(', ', $roots),
        ));
    }

    public static function aUrl(string $name): self
    {
        return new self(sprintf(
            'The theme "%s" is a URL rather than a path. A theme is a directory holding a theme.yml, and PHP would '
            . 'have handed "phar://" or "http://" to the stream wrapper it names.',
            $name,
        ));
    }

    public static function noManifest(string $path): self
    {
        return new self(sprintf('"%s" is not a theme: it has no theme.yml.', $path));
    }
}