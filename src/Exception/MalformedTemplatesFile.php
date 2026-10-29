<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A templates file that holds something other than template definitions.
 *
 * A slide in a templates file is a slide nobody asked for, drawn on a deck the file was
 * not written for, so it is a failure rather than something to ignore.
 */
final class MalformedTemplatesFile extends InvalidInput
{
    public static function slide(string $file): self
    {
        return new self(sprintf(
            'The templates file "%s" holds a slide that is not a template definition. A templates file holds '
            . 'definitions: slides whose frontmatter names a template and nothing else.',
            $file,
        ));
    }
}
