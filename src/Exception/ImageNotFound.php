<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * An image a slide names that this process will not read.
 *
 * Three failures, told apart because the fix for each is a different thing the author can
 * change: a path that resolves to no file, a path that resolves to a file outside the
 * directories the renderer was given, and a path that names a stream wrapper rather than a
 * file at all.
 *
 * The message is where the fix is, so it repeats the path in every one of them. A deck
 * written on one machine and converted on another resolves a relative path against the
 * wrong directory, and the two "not this file" messages differ by exactly one line of that
 * kind: where it looked, and what it looked at instead.
 */
final class ImageNotFound extends InvalidInput
{
    public static function at(int $slide, string $path, string $resolved): self
    {
        return new self(sprintf(
            'Slide %d shows the image "%s", which is not a file. It resolved to "%s". '
            . 'Put the picture beside the deck, or render the deck from the directory the path is relative to.',
            $slide,
            $path,
            $resolved,
        ));
    }

    /**
     * @param list<string> $roots The directories a deck may read a picture from, which the
     *                             message names: the fix is to put the picture in one of
     *                             them, or to have the program that renders the deck allow
     *                             the directory it is in.
     */
    public static function outside(int $slide, string $written, string $resolved, array $roots): self
    {
        return new self(sprintf(
            'Slide %d shows the image "%s", which is a file at "%s" rather than one this render may read. '
            . 'A picture has to be under the directory the renderer was given%s: copy it in, or give the '
            . 'renderer that directory as one of its additional image roots.',
            $slide,
            $written,
            $resolved,
            $roots === [] ? '' : ' (' . implode(', ', $roots) . ')',
        ));
    }

    public static function aUrl(int $slide, string $written): self
    {
        return new self(sprintf(
            'Slide %d shows the image "%s", which is a URL rather than a path: this render embeds a picture '
            . 'by reading it, and PHP would have handed "phar://" or "http://" to the stream wrapper it names. '
            . 'Write the path of a file under the deck instead.',
            $slide,
            $written,
        ));
    }
}