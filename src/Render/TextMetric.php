<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

/**
 * How wide a piece of text is, and so how many lines of it there are.
 *
 * The widths come from a table bundled here rather than from a font file, and that is the
 * design rather than a compromise. `imagettfbbox()` measures whichever face the build
 * machine happens to have installed, so the same deck would lay out differently on a
 * laptop and on a build server, and the file that comes out would be a property of the
 * machine. Nothing guarantees Calibri, Inter, Roboto, Georgia, Consolas, JetBrains Mono or
 * Menlo is installed anywhere at all, and none of them could be shipped.
 *
 * What it is not: exact. The table is Helvetica's own advance widths, which are within a
 * few percent of Calibri, Inter and Roboto and are the widest of the faces the shipped
 * themes name — a face a theme picks that is much wider or narrower is not detected, and
 * kerning is not modelled. The bias is known and it is deliberate: over-estimating width
 * over-estimates the number of lines, and an over-estimated line count over-allocates
 * height. Too much height leaves a gap; too little puts one block on top of another.
 * {@see ShapeWriter} writes `normAutofit` on every text shape for the same reason: a box
 * the reader finds a line too tall is shrunk, not grown over its neighbour.
 *
 * The monospaced half is exact. Every character in a monospaced face advances by the same
 * amount and 600/1000 is what Consolas, Menlo and JetBrains Mono all use, so a code block —
 * the one kind of block that is always monospaced — is measured rather than guessed.
 *
 * @phpstan-type Piece array{text: string, size: int, mono: bool, bold: bool}
 */
final class TextMetric
{
    /** Advance widths are per thousand of an em, which a size in points divides out of. */
    private const UNITS_PER_EM = 1000;

    /** What every character in a monospaced face advances by, whatever the character. */
    private const MONOSPACED = 600;

    /** A CJK ideograph, a Hangul syllable or an emoji: a full em in every face above. */
    private const FULL_EM = 1000;

    /** Bold is a little wider than the same character upright. */
    private const BOLD_FACTOR = 1.05;

    /**
     * Helvetica's advance widths, in units per em, for the printable ASCII.
     *
     * The reference rather than any of the shipped themes' own faces because it is the one
     * of the three that can be written down: it is metrically compatible with Arial,
     * Liberation Sans and Nimbus Sans, and its widths are published.
     */
    private const SANS = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191,
        '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
        '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
        '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
        '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
        'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
        'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
        '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
        'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
        'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722,
        'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
    ];

    /**
     * A stretch of text measured at its own size, face and weight.
     *
     * A paragraph is a list of these rather than one string because a run of inline code
     * inside it is set at a different size in a different face, and measuring the lot at the
     * paragraph's own size is what made a code-heavy paragraph come out a line short.
     *
     * @return Piece
     */
    public static function piece(string $text, int $size, bool $mono = false, bool $bold = false): array
    {
        return ['text' => $text, 'size' => $size, 'mono' => $mono, 'bold' => $bold];
    }

    /**
     * How wide these are laid out on one unbroken line, in pixels.
     *
     * @param list<Piece> $pieces
     */
    public function width(array $pieces): int
    {
        $pixels = 0;

        foreach ($pieces as $piece) {
            $pixels += $this->advance($piece['text'], $piece['size'], $piece['mono'], $piece['bold']);
        }

        return $pixels;
    }

    /**
     * How many lines these take in a box `$width` pixels wide.
     *
     * Wrapping is greedy at spaces, which is what a reader does, and a word too long for a
     * line of its own is broken mid-word rather than being allowed to overflow sideways —
     * a URL or an identifier in prose is ordinary and has to take the lines it needs.
     *
     * @param list<Piece> $pieces
     */
    public function lines(array $pieces, int $width): int
    {
        // Below a pixel the loop that breaks an over-long word would never advance it.
        $width = max(1, $width);

        // The state is the whole stream's rather than one run's, and carrying it across the
        // runs is the point of the method: a run boundary is a change of face, not a line
        // break. Measured one run at a time, a paragraph cost a line per `**bold**` in it —
        // twelve runs of one line of text measured as twelve lines, which over-allocated
        // every paragraph carrying markup and clamped whatever was drawn below it.
        $lines = 1;
        $used = 0;
        $gap = 0;

        foreach ($pieces as $piece) {
            foreach (explode("\n", $piece['text']) as $number => $segment) {
                // A newline inside a run is a break the deck asked for, not a space, so it
                // starts a line whatever the width.
                if ($number > 0) {
                    ++$lines;
                    $used = 0;
                    $gap = 0;
                }

                [$lines, $used, $gap] = $this->place($segment, $piece, $width, $lines, $used, $gap);
            }
        }

        return $lines;
    }

    /**
     * One run of text with no newline in it, laid out onto the line being filled.
     *
     * The line count and the width used on the last line go in and come back out because
     * the run before this one may have left the line part full, and a word too long for a
     * line of its own is broken mid-word rather than being allowed to overflow sideways —
     * a URL or an identifier in prose is ordinary and has to take the lines it needs.
     *
     * @param Piece $piece The run, for its size, face and weight. Its text has been split.
     *
     * @return array{0: int, 1: int, 2: int} The lines, the width used on the last of them,
     *                                           and the whitespace waiting on that line.
     */
    private function place(string $text, array $piece, int $width, int $lines, int $used, int $gap): array
    {
        // The delimiters are captured rather than discarded so that indentation counts: a
        // code block indented four spaces starts four spaces in.
        foreach (preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            $advance = $this->advance($chunk, $piece['size'], $piece['mono'], $piece['bold']);

            if (trim($chunk) === '') {
                $gap += $advance;
                continue;
            }

            if ($used > 0 && $used + $gap + $advance > $width) {
                ++$lines;
                $used = 0;
                $gap = 0;
            }

            $used += $gap + $advance;
            $gap = 0;

            while ($used > $width) {
                ++$lines;
                $used -= $width;
            }
        }

        return [$lines, $used, $gap];
    }

    private function advance(string $text, int $size, bool $mono, bool $bold): int
    {
        $units = 0;
        $offset = 0;
        $length = strlen($text);

        // A `while` rather than a `for` because how far the cursor moves is the answer
        // rather than a step: a character outside ASCII is more than one byte, and the
        // header of a `for` has nowhere to say so without reading as an afterthought.
        while ($offset < $length) {
            $character = self::character($text, $offset);
            $offset += strlen($character);
            $units += self::units($character, $mono);
        }

        return (int) round($units * ($bold ? self::BOLD_FACTOR : 1.0) * $size / self::UNITS_PER_EM);
    }

    /**
     * The character at `$offset`, which is more than one byte for anything outside ASCII.
     *
     * Read as bytes rather than with `mb_*` because a deck can carry text that is not valid
     * UTF-8 — a Latin-1 file read as UTF-8 — and measuring nothing for it would be a
     * paragraph measured at zero width.
     */
    private static function character(string $text, int $offset): string
    {
        return substr($text, $offset, match (true) {
            ord($text[$offset]) >= 0xF0 => 4,
            ord($text[$offset]) >= 0xE0 => 3,
            ord($text[$offset]) >= 0xC0 => 2,
            default => 1,
        });
    }

    private static function units(string $character, bool $mono): int
    {
        if ($mono) {
            return self::MONOSPACED;
        }

        if (isset(self::SANS[$character])) {
            return self::SANS[$character];
        }

        // Three bytes or more is a CJK ideograph, a Hangul syllable or an emoji, and each is
        // a full em wide in every face this model covers. Two bytes is an accented Latin
        // letter, which is about as wide as the letter it accents, so it takes the mean.
        return strlen($character) >= 3 ? self::FULL_EM : intdiv(array_sum(self::SANS), count(self::SANS));
    }
}
