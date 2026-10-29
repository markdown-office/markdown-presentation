<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A theme's master that the source of a render cannot serve.
 *
 * The theme carries a `template.pptx` — the listing of the theme's files says so — and the
 * source cannot hand it over. That is a different thing from a theme that carries no
 * master at all, and the difference is the whole of why this is a failure of its own: a
 * deck drawn without the master's geometry is drawn in the wrong design, with nothing in
 * the file saying so.
 *
 * `Exception` rather than `InvalidInput`, because neither the author of the deck nor the
 * author of the theme can act on it: the file is right and the program that brought the
 * source is the one that has to serve it.
 */
final class UnservedTemplate extends Exception
{
    public static function named(string $theme, string $file): self
    {
        return new self(sprintf(
            'The theme "%s" carries a "%s" that the source of this render cannot serve. A deck drawn without it is '
            . 'drawn in the wrong design with nothing saying so, so it is refused rather than drawn without it.',
            $theme,
            $file,
        ));
    }
}
