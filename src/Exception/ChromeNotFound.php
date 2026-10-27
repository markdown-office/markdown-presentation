<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A chrome file a deck names, and there is no file there to read.
 *
 * The three failures are kept apart because the fix for each is a different thing: a path
 * that is not a file, a path that resolves outside the directories this render may read,
 * and a name that is a stream wrapper rather than a path.
 */
final class ChromeNotFound extends InvalidInput
{
    public static function at(string $written, string $found): self
    {
        return new self(sprintf('There is no chrome file at "%s".', $found));
    }

    /**
     * @param list<string> $roots The directories this render may read a chrome file in,
     *                             which the message names: the fix is to move the file into
     *                             one of them or to have the program rendering the deck allow
     *                             where it is.
     */
    public static function outside(string $written, string $found, array $roots): self
    {
        return new self(sprintf(
            'The chrome file "%s" is at "%s" rather than one this render may read (%s). Copy it under one of those '
            . 'directories, or give the renderer that directory as one of its additional roots.',
            $written,
            $found,
            implode(', ', $roots),
        ));
    }

    public static function aUrl(string $written): self
    {
        return new self(sprintf(
            'The chrome file "%s" is a URL rather than a path. A chrome file is a Markdown file beside the deck, and '
            . 'PHP would have handed "phar://" or "http://" to the stream wrapper it names.',
            $written,
        ));
    }
}
