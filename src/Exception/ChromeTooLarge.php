<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A chrome file bigger than the library will read.
 *
 * The chrome file is the one file this library reads whole: a picture is bounded by the
 * pixels of its raster and a part of a template by the bytes it inflates to, and the file a
 * deck names in its frontmatter was bounded by nothing at all.
 *
 * The size is in the message because the fix is a number: only the author of the file can
 * say how much of the furniture belongs in it and how much belongs on the slides.
 */
final class ChromeTooLarge extends InvalidInput
{
    public static function of(string $file, int $bytes, int $maximum): self
    {
        return new self(sprintf(
            'The chrome file "%s" is %d bytes, and a chrome file may be at most %d. Write less of the furniture in '
            . 'it, or write the furniture on the slides themselves.',
            $file,
            $bytes,
            $maximum,
        ));
    }
}
