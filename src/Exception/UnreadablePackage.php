<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A `.pptx` this process cannot open, or a part of it that cannot be read.
 *
 * Not {@see InvalidInput}: nothing a deck author names fixes either one. A file that is not
 * a package, a path the process cannot write, and an entry whose payload does not decompress
 * are all the caller's — a disk that filled, a copy that was interrupted, a file that is not
 * a deck — and a deck author reading "slide 3 shows an image that is not a file" for any of
 * them would be sent looking for a typo they did not write.
 *
 * It is still under {@see Exception}, so the one `catch` in the README covers it.
 */
final class UnreadablePackage extends Exception
{
    public static function cannotOpen(string $path): self
    {
        return new self(sprintf(
            'Unable to open the presentation at "%s". It is not a package this PHP can read, or the process '
            . 'cannot write it.',
            $path,
        ));
    }

    public static function cannotRead(string $path, string $part): self
    {
        return new self(sprintf(
            'Unable to read "%s" out of "%s". The part is in the package and cannot be decompressed, so the '
            . 'package was left as it was found rather than rewritten with the part lost.',
            $part,
            $path,
        ));
    }
}
