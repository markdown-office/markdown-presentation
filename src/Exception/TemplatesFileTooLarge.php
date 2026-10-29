<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A templates file bigger than the library will read.
 *
 * The ceiling is {@see \MarkdownPresentation\Template\TemplatesFile::MAX_BYTES}, and the size
 * is in the message because the fix is a number: only the author of the file can say how
 * much of the furniture belongs in it and how much belongs on the slides.
 */
final class TemplatesFileTooLarge extends InvalidInput
{
    public static function of(string $file, int $bytes, int $maximum): self
    {
        return new self(sprintf(
            'The templates file "%s" is %d bytes, and a templates file may be at most %d. Write less of the '
            . 'furniture in it, or write the furniture on the slides themselves.',
            $file,
            $bytes,
            $maximum,
        ));
    }
}
