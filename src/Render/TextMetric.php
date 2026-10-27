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
 * The tables are the faces' own: {@see FontMetrics} carries one for every face the shipped
 * themes name, and a piece of text is measured with the face its theme draws it in. Each
 * table has a regular and a bold cut, so a bold run is measured with the widths of the
 * bold face rather than with the upright ones made a little wider.
 *
 * What it is not: exact. Kerning is not modelled, so two letters that would sit closer
 * together in the real face are measured as two letters set side by side, and a character
 * outside the printable ASCII is measured at the mean of the face's own table rather than at
 * its own width. The error is per face rather than a known constant: the tables say how
 * wide each character is in the face that will draw it, and nothing says how far apart two
 * characters will sit.
 *
 * Which way an estimate comes out is now a question about the face rather than about the
 * model, and both ways have their own failure: too much height leaves a gap and too little
 * puts one block on top of another. {@see ShapeWriter} writes `normAutofit` on every text
 * shape for that reason — a box the reader finds a line too tall is shrunk, not grown over
 * its neighbour.
 *
 * A face a theme names that has no table is measured with the reference face's widths —
 * Arial's, which are the widths every face was measured with before there were tables of
 * their own — and the renderer says which face it could not measure: see
 * {@see \MarkdownPresentation\Render\PresentationRenderer::unknownFonts()}. Measuring it as
 * some other face without saying so would be the failure the tables of faces exist to end.
 *
 * A monospaced face is measured by its advance rather than by a table of characters:
 * every character in one advances by the same amount, so the face's advance is the width
 * of all of them. That amount is the face's own, and the faces the shipped themes name do
 * not agree on it — see {@see FontMetrics::MONOSPACED} — so a code block, the one kind of
 * block that is always monospaced, is measured in the face that will draw it. A character
 * outside the printable ASCII is measured at that advance as well, which is one cell of the
 * face rather than the two a full-width character takes in a real one.
 *
 * @phpstan-type Piece array{text: string, size: int, bold: bool, face: string}
 */
final class TextMetric
{
    /** Advance widths are per thousand of an em, which a size in points divides out of. */
    private const UNITS_PER_EM = 1000;

    /** A CJK ideograph, a Hangul syllable or an emoji: a full em in every face, so no table carries them. */
    private const FULL_EM = 1000;

    /**
     * The widths of the faces this renderer knows, by the name a theme writes them under.
     *
     * @param array<string, array{regular: array<int|string, int>, bold: array<int|string, int>}> $tables
     * @param array<string, int> $monospaced The advance of each monospaced face.
     */
    public function __construct(
        private readonly array $tables = FontMetrics::TABLES,
        private readonly array $monospaced = FontMetrics::MONOSPACED,
    ) {
    }

    /**
     * A stretch of text measured at its own size, face and weight.
     *
     * A paragraph is a list of these rather than one string because a run of inline code
     * inside it is set at a different size in a different face, and measuring the lot at the
     * paragraph's own size is what made a code-heavy paragraph come out a line short.
     *
     * The face is the one the theme draws the text in, and a piece with no face of its own
     * is measured with the reference face's widths.
     *
     * @return Piece
     */
    public static function piece(
        string $text,
        int $size,
        bool $bold = false,
        string $face = '',
    ): array {
        return ['text' => $text, 'size' => $size, 'bold' => $bold, 'face' => $face];
    }

    /**
     * Whether a face is measured with its own widths.
     *
     * A face that is not is measured with the reference face's widths, and the renderer
     * reports it: see {@see \MarkdownPresentation\Render\PresentationRenderer::unknownFonts()}.
     */
    public function has(string $face): bool
    {
        return isset($this->tables[$face]) || isset($this->monospaced[$face]);
    }

    /**
     * The faces there are widths for, in alphabetical order.
     *
     * @return list<string>
     */
    public function faces(): array
    {
        $faces = [...array_keys($this->tables), ...array_keys($this->monospaced)];
        sort($faces);

        return $faces;
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
            $pixels += $this->advance($piece);
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
            $piece['text'] = $chunk;
            $advance = $this->advance($piece);

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

    /**
     * How wide one piece of text is, in pixels.
     *
     * @param Piece $piece
     */
    private function advance(array $piece): int
    {
        $units = 0;
        $offset = 0;
        $length = strlen($piece['text']);

        // A `while` rather than a `for` because how far the cursor moves is the answer
        // rather than a step: a character outside ASCII is more than one byte, and the
        // header of a `for` has nowhere to say so without reading as an afterthought.
        while ($offset < $length) {
            $character = self::character($piece['text'], $offset);
            $offset += strlen($character);
            $units += $this->units($character, $piece);
        }

        return (int) round($units * $piece['size'] / self::UNITS_PER_EM);
    }

    /**
     * How wide one character is, in units per em.
     *
     * @param Piece $piece The run the character is in, for its face and weight.
     */
    private function units(string $character, array $piece): int
    {
        // A monospaced face advances by the same amount whatever the character, so the
        // face's own advance is the width of all of them — and the bold cut of one is
        // the same amount again.
        $advance = $this->monospaced[$piece['face']] ?? null;

        if ($advance !== null) {
            return $advance;
        }

        $table = $this->widths($piece['face'], $piece['bold']);
        $width = $table[$character] ?? null;

        if ($width !== null) {
            return $width;
        }

        // Three bytes or more is a CJK ideograph, a Hangul syllable or an emoji, and each is
        // a full em wide in every face this model covers. Two bytes is an accented Latin
        // letter, which is about as wide as the letter it accents, so it takes the mean of
        // the face's own table rather than a mean of all of them.
        return strlen($character) >= 3 ? self::FULL_EM : intdiv(array_sum($table), count($table));
    }

    /**
     * The widths of a face, in units per em.
     *
     * A face with no table of its own is measured with the reference face's, which is what
     * every face was measured with before there were tables of their own.
     *
     * @return array<int|string, int>
     */
    private function widths(string $face, bool $bold): array    {
        $cut = $bold ? 'bold' : 'regular';

        return $this->tables[$face][$cut] ?? $this->tables[FontMetrics::REFERENCE][$cut];
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
}
