<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

/**
 * The inline Markdown of one paragraph's text: emphasis, code spans and links.
 *
 * Deliberately not a CommonMark inline parser. An emphasis delimiter that does not close
 * is left as the character it is, which is what a reader expects and what a strict parser
 * would refuse, and a deck that renders is worth more here than one that refuses a
 * paragraph because of an apostrophe.
*/
final class InlineParser
{
    /**
     *
     * Each pattern and the kind of run it becomes, in the order a tie between two of them is
     * broken.
     *
     * Code comes before bold so that the asterisks inside a code span are not read as emphasis;
     * the rest are ordered by nothing in particular, because {@see self::next()} takes the
     * leftmost match whatever order they are tried in.
    */
    private const PATTERNS = [
        'code' => '/`([^`]+)`/',
        'bold' => '/\*\*([^*]+)\*\*/',
        'italic' => '/(?<![*\w])\*([^*\n]+)\*(?![*\w])/',
        'link' => '/\[([^\]\n]*)\]\(\s*([^)\s]*)(?:\s+"[^"]*")?\s*\)/',
    ];

    /**
     *
     * Every run the text is made of, in order. Text with no markup in it is one run.
     *
     * @return list<Run>
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
     * The leftmost markup after `$cursor`, and the run it becomes.
     *
     * Leftmost rather than in the order of {@see self::PATTERNS}, because a paragraph that
     * says `**bold** and `code`` has to come out in the order it was written or the words
     * move.
     *
     * @return ?array{at: int, length: int, run: Run}
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
     * @param 'code'|'bold'|'italic'|'link' $kind
    */
    private static function run(string $kind, string $text, string $target): Run
    {
        return match ($kind) {
            'code' => new Run($text, code: true),
            'bold' => new Run($text, bold: true),
            'italic' => new Run($text, italic: true),
            'link' => new Run($text, link: $target),
        };
    }
}