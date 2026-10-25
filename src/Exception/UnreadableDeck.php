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

    public static function notFound(string $path): self
    {
        return new self(sprintf('There is no deck at "%s".', $path));
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

    /**
     * A picture in the deck is larger than a picture is allowed to be.
     *
     * The size is the one the picture's header declares, which is the canvas
     * the reader would allocate: the picture is refused before it is opened.
     */
    public static function pictureTooLarge(string $path, string $part, int $width, int $height, int $allowed): self
    {
        return new self(sprintf(
            'The picture "%s" in the presentation at "%s" measures %d x %d pixels, '
            . 'which is more than the %d allowed.',
            $part,
            $path,
            $width,
            $height,
            $allowed,
        ));
    }

    /**
     * A list in the deck nests deeper than a list may nest.
     *
     * The walk down a list recurses once per level, so a list that nests
     * without end does not come back with an error — it runs out of stack and
     * the process dies on a signal.
     */
    public static function nestedTooDeep(int $allowed): self
    {
        return new self(sprintf(
            'The presentation nests a list more than %d levels deep, which is more than the %d allowed.',
            $allowed,
            $allowed,
        ));
    }
}
