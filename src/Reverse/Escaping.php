<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

/**
 * Escapes text read out of a deck so it survives a Markdown parser.
 *
 * One rule is behind all of it: escaping must never change what the reader
 * sees, but leaving a character unescaped can. It is applied conservatively —
 * where a character is only ambiguous in some position, it is escaped only
 * there, which is what keeps the output readable. `a < b` stays as it is while
 * `<div>` becomes `\<div>`, and `snake_case` is left alone because CommonMark
 * does not let an underscore inside a word delimit anything.
 *
 * These are the rules `markdown-word` uses when it reads a Word document back
 * out, brought across rather than written again: two packages, one set of
 * rules, and a deck and a document are both text that has to survive the same
 * parser.
 */
final class Escaping
{
    /** Characters that are Markdown syntax wherever they appear in a paragraph. */
    private const INLINE = [
        '\\' => '\\\\',
        '`' => '\\`',
        '*' => '\\*',
        '[' => '\\[',
        ']' => '\\]',
    ];

    /** Characters that only start a block when they begin a line. */
    private const LINE_START = '#>+=~|-';

    /**
     * @param string $text     The characters as they appear in the deck.
     * @param bool   $lineStart Whether the run begins a line, where a `#`, a `-`
     *        or a `1.` would otherwise become a heading, a rule or a list.
     * @param bool   $inTable  Whether the run sits inside a table cell, where a
     *        pipe would end the cell.
     */
    public static function text(string $text, bool $lineStart = false, bool $inTable = false): string
    {
        if ($text === '') {
            return '';
        }

        // The backslash goes first, or it would escape the escapes added after it.
        $text = strtr($text, self::INLINE);
        $text = self::escapeUnderscores($text);

        // `~~` is GFM strikethrough; a single tilde is ordinary prose. The
        // replacement is a plain one: it is done in a single pass, so the
        // tildes it puts there are not looked at again.
        $text = str_replace('~~', '\\~\\~', $text);

        // `&` is only a problem where it could start a character reference, and
        // `&amp;` in the output would decode back to a bare ampersand. A number
        // and a name are looked for apart, because one pattern for both is
        // harder to read than two easy ones.
        $text = (string) preg_replace('/&(?=#\d+;?|#x[\dA-Fa-f]+;?)/', '\\\\&', $text);
        $text = (string) preg_replace('/&(?=[A-Za-z][A-Za-z\d]*;)/', '\\\\&', $text);

        // A `<` only matters where it could open a tag or an autolink, which is
        // why `a < b` is left alone and `<div>` is not. The delimiter is `~`
        // because the character class itself contains a slash.
        $text = (string) preg_replace('~<(?=[A-Za-z!/?])~', '\\<', $text);

        if ($inTable) {
            $text = str_replace('|', '\\|', $text);
        }

        return $lineStart ? self::escapeLineStart($text) : $text;
    }

    /**
     * The text of an image reference, or of a link.
     *
     * The text is the author's, so it is escaped: a bracket in it would end the
     * reference early, and a newline in it would end the line the reference is
     * on and leave the rest of it as prose.
     */
    public static function label(string $text): string
    {
        return str_replace(['[', ']', "\n", "\r"], ['\\[', '\\]', ' ', ' '], $text);
    }

    /**
     * The address of a link, or of an image.
     *
     * A space in it would end the address early, and a bracket in it would be
     * read as the end of the reference, so the address is written between
     * angle brackets — which is how Markdown says "this is an address and not
     * the rest of the line".
     */
    public static function address(string $url): string
    {
        if (preg_match('/[\s<>()]/', $url) === 1) {
            return '<' . str_replace(['<', '>'], ['\\<', '\\>'], $url) . '>';
        }

        return $url;
    }

    /**
     * Escape the beginning of a line so it is not read as a block.
     *
     * Only the first significant character is escaped, which is enough: once it
     * is literal, the block construct it could have started no longer matches.
     */
    private static function escapeLineStart(string $text): string
    {
        preg_match('/^(\s*)(.*)$/s', $text, $matches);

        $space = $matches[1] ?? '';
        $rest = $matches[2] ?? '';

        if ($rest === '') {
            return $text;
        }

        // A line that opens with a character that starts a block, or with the
        // marker of an ordered list, would be read as one rather than as prose.
        return match (true) {
            str_contains(self::LINE_START, $rest[0]) => $space . '\\' . $rest,
            (bool) preg_match('/^\d{1,9}([.)])(?=\s|$)/', $rest, $marker) => $space
                . substr($rest, 0, strlen($marker[0]) - 1)
                . '\\' . $marker[1]
                . substr($rest, strlen($marker[0])),
            default => $text,
        };
    }

    /**
     * The decision follows the specification's own flanking rules rather than a
     * guess, and that is what keeps ordinary text clean. `snake_case` needs
     * nothing, because an underscore between two word characters cannot delimit
     * anything. `__ foo bar__` needs nothing either: the opening run is followed
     * by a space so it cannot open, and the closing run has nothing to close.
     * When no run can open, none of them can pair up.
     */
    private static function escapeUnderscores(string $text): string
    {
        if (!str_contains($text, '_')) {
            return $text;
        }

        $length = strlen($text);

        preg_match_all('/_+/', $text, $matches, PREG_OFFSET_CAPTURE);

        $runs = [];
        $opens = false;

        foreach ($matches[0] as [$run, $position]) {
            $end = $position + strlen($run);
            $before = $position > 0 ? $text[$position - 1] : '';
            $after = $end < $length ? $text[$end] : '';

            $canOpen = self::canOpen($before, $after);
            $opens = $opens || $canOpen;

            $runs[] = [$position, $end, $run, $canOpen, self::canClose($before, $after)];
        }

        $out = '';
        $offset = 0;

        foreach ($runs as [$position, $end, $run, $canOpen, $canClose]) {
            $out .= substr($text, $offset, $position - $offset);

            $out .= $opens && ($canOpen || $canClose)
                // Every underscore is escaped, not just the run: `\_` is one
                // literal underscore, so `\__` reads as an underscore followed by
                // a delimiter rather than as two underscores.
                ? str_replace('_', '\\_', $run)
                : $run;

            $offset = $end;
        }

        return $out . substr($text, $offset);
    }

    /**
     * A run opens when it is left-flanking and, unless preceded by punctuation, is
     * not also right-flanking. That last clause is what makes `snake_case` inert.
     */
    private static function canOpen(string $before, string $after): bool
    {
        if (self::isWhitespace($after)) {
            return false;
        }

        $leftFlanking = !self::isPunctuation($after) || self::isWhitespace($before) || self::isPunctuation($before);
        $rightFlanking = !self::isWhitespace($before);

        return $leftFlanking && (! $rightFlanking || self::isPunctuation($before));
    }

    /**
     * Whether a delimiter run could close emphasis, the mirror of
     * {@see self::canOpen()}.
     */
    private static function canClose(string $before, string $after): bool
    {
        if (self::isWhitespace($before)) {
            return false;
        }

        $rightFlanking = !self::isPunctuation($before) || self::isWhitespace($after) || self::isPunctuation($after);
        $leftFlanking = !self::isWhitespace($after);

        return $rightFlanking && (! $leftFlanking || self::isPunctuation($after));
    }

    /**
     * Whether a character is whitespace, which the specification treats the same
     * way as the end of a line.
     */
    private static function isWhitespace(string $character): bool
    {
        return $character === '' || ($character < "\x80" && ctype_space($character));
    }

    /**
     * Whether a character is ASCII punctuation, which is what the flanking rules
     * are written against.
     */
    private static function isPunctuation(string $character): bool
    {
        return $character !== ''
            && $character < "\x80"
            && str_contains('!"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~', $character);
    }
}
