<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A theme's `template.pptx` that cannot be read as one.
 *
 * `InvalidInput` rather than a failure of this library's own side, because the author of the
 * theme is the one who can act on it: the file has to be the PowerPoint template it claims
 * to be.
 *
 * Every failure names the file. What it does *not* do is fail over a template that is a
 * perfectly good presentation but does not cover a role — that is a layout missing, not a
 * template missing, and the roles it leaves alone fall back to the library's own geometry.
 */
final class MalformedTemplate extends InvalidInput
{
    public static function notAPackage(string $file): self
    {
        return new self(sprintf(
            'The template "%s" could not be opened as a presentation. A theme\'s template.pptx has to be '
            . 'a PowerPoint file, and this one is not one.',
            $file,
        ));
    }

    public static function unreadable(string $file, string $part): self
    {
        return new self(sprintf(
            'The template "%s" has no readable part at %s, or that part is not XML. A template PowerPoint '
            . 'can open is one this library can read too.',
            $file,
            $part,
        ));
    }

    public static function noPageSize(string $file): self
    {
        return new self(sprintf(
            'The template "%s" says nothing about how big its slides are. Every box in it is a fraction of '
            . 'that page, so a template that does not say cannot place anything.',
            $file,
        ));
    }
}
