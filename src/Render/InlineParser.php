<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnsupportedLink;

/**
 * The inline Markdown of one paragraph's text: emphasis, code spans, strikethrough and
 * links.
 *
 * A link's target is checked, and it is the one piece of markup here that decides what a
 * reader's machine does rather than what a slide looks like: a target in a `.pptx` is
 * followed, so `\\host\share` is an NTLM relay to whoever answers and `javascript:` is a
 * script in every reader that renders the deck. A target this renderer cannot vouch for is
 * {@see UnsupportedLink} at parse time rather than a live link in a file somebody opens.
 *
 * A relative target is not one of them, and cannot be: `[alt](logo.png)` inside a sentence
 * is a picture the renderer draws as text, and refusing every relative target would refuse
 * every deck that writes one. What is refused is the two shapes a reader's machine acts on
 * — a scheme, and a Windows or UNC share — and {@see UnsupportedLink} says so.
 *
 * Deliberately not a CommonMark inline parser. An emphasis delimiter that does not close
 * is left as the character it is, which is what a reader expects and what a strict parser
 * would refuse, and a deck that renders is worth more here than one that refuses a
 * paragraph because of an apostrophe. What it does take from CommonMark is the rule that
 * an underscore inside a word is not an emphasis delimiter: `snake_case_name` is a name and
 * `snake_case_name_` is not the end of one.
*/
final class InlineParser
{
    /**
     *
     * Each pattern and the kind of run it becomes, in the order a tie between two of them is
     * broken.
     *
     * Code comes before bold so that the asterisks inside a code span are not read as emphasis,
     * and each three-character delimiter comes before the two-character one it starts with, so
     * `***x***` is one run rather than an italic run wrapped in two stray asterisks. The rest
     * are ordered by nothing in particular, because {@see self::next()} takes the leftmost
     * match whatever order they are tried in.
    */
    private const PATTERNS = [
        'code' => '/`([^`]+)`/',
        'boldItalic' => '/(?<![*\w])\*\*\*([^*\n]+)\*\*\*(?![*\w])/',
        'bold' => '/\*\*([^*]+)\*\*/',
        'italic' => '/(?<![*\w])\*([^*\n]+)\*(?![*\w])/',
        'strong' => '/(?<![\w_])__([^_\n]+)__(?![\w_])/',
        'emphasis' => '/(?<![\w_])_([^_\n]+)_(?![\w_])/',
        'strike' => '/~~([^~\n]+)~~/',
        'link' => '/\[([^\]\n]*)\]\(\s*([^)\s]*)(?:\s+"[^"]*")?\s*\)/',
    ];

    /**
     * The targets a deck may point at, in the order the message names them.
     *
     * `stripos()` rather than `str_starts_with()` because a scheme is case-insensitive and
     * `HTTP://` means the same thing as `http://`.
     */
    private const LINK_SCHEMES = ['https://', 'http://', 'mailto:'];

    /**
     * Any scheme at all: what a reader will act on rather than open, which is every scheme
     * this list does not name.
     *
     * The one or more character before the colon is what keeps a Windows drive letter out of
     * it — `C:\logo.png` is a path a deck writes, and RFC 3986's one-letter scheme is a
     * reader's problem rather than a deck author's.
     */
    private const SCHEME = '#\A[a-zA-Z][a-zA-Z0-9+.-]+:#';

    /**
     * A Windows or UNC share, in either spelling: `\\host\share\payload.exe` and
     * `//host/share`. An SMB connection is made when a reader clicks it and the credentials
     * go with it, which is the one hyperlink that costs a reader something to click.
     */
    private const SHARE = '#\A[/\\\\]{2}#';

    /**
     *
     * Every run the text is made of, in order. Text with no markup in it is one run.
     *
     * @return list<Run>
     *
     * @throws UnsupportedLink
     */
    public function parse(string $text): array
    {
        $runs = [];
        $cursor = 0;

        while (($match = self::next($text, $cursor)) !== null) {
            if ($match['at'] > $cursor) {
                $runs[] = new Run(substr($text, $cursor, $match['at'] - $cursor));
            }

            $runs[] = $match['run'];
            $cursor = $match['at'] + $match['length'];
        }

        if ($cursor < strlen($text)) {
            $runs[] = new Run(substr($text, $cursor));
        }

        return $runs;
    }

    /**
     *
     * What a reader sees of these runs, which is the text with its markup taken out.
     *
     * The comparison a title is made against goes through here and through
     * {@see self::parse()} and nothing else, so the two sides of it cannot be
     * normalised differently and then found unequal.
     *
     * @param list<Run> $runs
    */
    public static function text(array $runs): string
    {
        return implode('', array_map(static fn (Run $run): string => $run->text, $runs));
    }

    /**
     * The text as a reader reads it: parsed and laid back out, so the markup is gone.
     *
     * An instance method rather than a static one because the strip is this parser's:
     * a title and a heading normalised by two parsers are two strings that agree only
     * while the two parsers do.
    */
    public function plain(string $text): string
    {
        return self::text($this->parse($text));
    }

    /**
     *
     * The leftmost markup after `$cursor`, and the run it becomes.
     *
     * Leftmost rather than in the order of {@see self::PATTERNS}, because a paragraph that
     * says `**bold** and `code`` has to come out in the order it was written or the words
     * move.
     *
     * @return ?array{at: int, length: int, run: Run}
     *
     * @throws UnsupportedLink
     */
    private static function next(string $text, int $cursor): ?array
    {
        $found = null;

        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $cursor) !== 1) {
                continue;
            }

            $at = $match[0][1];

            // Leftmost wins, so a pattern tried later in the list cannot take a match that
            // starts after one already found.
            if ($found !== null && $at >= $found['at']) {
                continue;
            }

            $found = [
                'at' => $at,
                'length' => strlen($match[0][0]),
                'run' => self::run($kind, $match[1][0], $match[2][0] ?? ''),
            ];
        }

        return $found;
    }

    /**
     * @param 'code'|'bold'|'boldItalic'|'emphasis'|'italic'|'link'|'strike'|'strong' $kind
     *
     * @throws UnsupportedLink
     */
    private static function run(string $kind, string $text, string $target): Run
    {
        return match ($kind) {
            'code' => new Run($text, code: true),
            'bold' => new Run($text, bold: true),
            'italic' => new Run($text, italic: true),
            'boldItalic' => new Run($text, bold: true, italic: true),
            'strong' => new Run($text, bold: true),
            'emphasis' => new Run($text, italic: true),
            'strike' => new Run($text, strike: true),
            'link' => self::link($text, $target),
        };
    }

    /**
     * A run that is a hyperlink, for a target this library is willing to hand a reader.
     *
     * @throws UnsupportedLink
     */
    private static function link(string $text, string $target): Run
    {
        foreach (self::LINK_SCHEMES as $scheme) {
            if (stripos($target, $scheme) === 0) {
                return new Run($text, link: $target);
            }
        }

        if (preg_match(self::SCHEME, $target) === 1 || preg_match(self::SHARE, $target) === 1) {
            throw UnsupportedLink::target($target);
        }

        return new Run($text, link: $target);
    }
}
