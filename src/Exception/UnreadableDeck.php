<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A `.pptx` this process cannot read as a deck.
 *
 * Not {@see InvalidInput}: nothing a deck's author names fixes any of it. A
 * file that is not a package, a package whose parts are too large to be worth
 * reading, and a package the reader refuses are all the file's own doing — a
 * disk that filled, a copy that was interrupted, a file that is not a deck —
 * and somebody reading "slide 3 shows an image that is not a file" for any of
 * them would be sent looking for a typo they did not write.
 *
 * It is still under {@see Exception}, so the one `catch` in the README covers it.
 */
final class UnreadableDeck extends Exception
{
    public static function cannotOpen(string $path): self
    {
        return new self(sprintf(
            'Unable to open the presentation at "%s". It is not a package this PHP can read, or the process cannot write it.',
            $path,
        ));
    }

    public static function cannotRead(string $path, string $reason): self
    {
        return new self(sprintf(
            'Unable to read the presentation at "%s" as a deck: %s',
            $path,
            $reason,
        ));
    }

    public static function tooManyParts(string $path, int $parts, int $allowed): self
    {
        return new self(sprintf(
            'The presentation at "%s" has %d parts in it, which is more entries than the %d allowed.',
            $path,
            $parts,
            $allowed,
        ));
    }

    public static function partTooLarge(string $path, string $part, int $size, int $allowed): self
    {
        return new self(sprintf(
            'The part "%s" of the presentation at "%s" claims to be %d bytes uncompressed, '
            . 'which is larger than the %d allowed.',
            $part,
            $path,
            $size,
            $allowed,
        ));
    }
}
