<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A link a deck writes that this renderer will not put in the file.
 *
 * The target of a `[text](…)` becomes a live hyperlink in the `.pptx`: a reader who
 * follows it is not reading a document, they are opening whatever it names. That is fine for
 * a URL and not fine for the rest, and the difference is what the reader's machine does
 * with it rather than what the deck meant:
 *
 * - `\\host\share\payload.exe` is an SMB connection to whoever answers. Following it from
 *   inside a corporate network sends the reader's credentials to that host as NTLM, with no
 *   prompt: an NTLM relay primitive that costs the reader nothing.
 * - `javascript:` is a script in every reader that renders the deck as a document, and a
 *   stored payload in every pipeline that converts a deck to HTML.
 * - `file://` reads a path on the reader's own disk, and `phar://` a PHP stream wrapper.
 *
 * So a target is refused when it names a scheme that is not `https`, `http` or `mailto`, or
 * when it is a share in either spelling (`\\host\share`, `//host/share`). A relative target
 * is not refused, because `[alt](logo.png)` inside a sentence is an image this renderer
 * draws as text rather than a link somebody will follow: refusing every relative target
 * would refuse every deck that writes one.
 *
 * No slide number in the message: {@see \MarkdownPresentation\Render\CommonMark\InlineTranslator} is
 * handed the text of one paragraph and is reached from the deck's title, its description and
 * its frontmatter as well as from a slide's body, so there is no one number to name. What
 * the message quotes is the target itself, which is what an author greps for.
 */
final class UnsupportedLink extends InvalidInput
{
    public static function target(string $target): self
    {
        return new self(sprintf(
            'The link "%s" is not a URL a deck may carry. A .pptx hyperlink is followed by the person who '
            . 'opens it: a Windows share is a credential relay, and a javascript: target is a script in any '
            . 'reader that renders the deck. Write it as "https://…", "http://…" or "mailto:…".',
            $target,
        ));
    }
}
