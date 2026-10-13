<?php

declare(strict_types=1);

namespace MarkdownPresentation\Document;

use MarkdownPresentation\Exception\FencedFrontmatter;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Splits a Markdown deck into slides.
 *
 * The separator grammar is a port of `parseSync` in
 * `slidev/packages/parser/src/core.ts`, pinned at commit 11fe3f79 and checked
 * against it on every build — `tests/fixtures/slidev/` holds decks the real
 * parser split, and `divergences.json` holds every place this one deliberately
 * does not agree, with the reason. The design is worth taking: it is the grammar
 * language models already write, so a deck does not have to be explained before
 * it can be converted.
 *
 * The fenced-code handling is not a port. Slidev matches a closing fence with
 * `startsWith` against a backtick run, which means a tilde fence is not a fence
 * and a flush closer does not close an indented opener — so a `---` inside an
 * ordinary block splits the deck. That is CommonMark implemented incorrectly
 * rather than a dialect worth keeping, so the rules here are CommonMark's.
 * {@see self::dialect()} writes out the whole grammar.
 *
 * The algorithm is one pass over the lines. A line that starts with `---` closes
 * the slide being read; if that line is not a longer run of dashes and the line
 * after it is not blank, the block between the two is the new slide's frontmatter. A
 * fenced code block is skipped whole, so a `---` inside a fence cannot split a
 * slide, and an HTML comment is tracked so that a `---` inside one cannot either.
*/
final class DeckParser
{
    /**
     * A `---` line and the block up to the next `---`, anchored at the start of the
     * slide. Slidev's `RE_FRONTMATTER`, with `.*` written as the class it matches:
     * a JavaScript dot stops at a carriage return as well as at a newline, so
     * `[^\r\n]*` is what the original means.
     *
     * The `^`, the `$` and the `m` flag are a deliberate divergence, and they are the whole
     * of what a closer is here. Slidev matches three dashes *anywhere*, so `description: a---b`
     * closed the block at the dashes in the middle of the value: the description was `a` and
     * `-b` became the first line of the slide's body, with nothing anywhere to say so, and a
     * quoted value holding `---` was not YAML at all. A delimiter is a line and a value is a
     * value, so a closer is a line here too.
     *
     * Three spaces of indentation are allowed because that is what every other block
     * construct here allows — see {@see self::closesFence()} — and anchoring the closer at
     * column zero instead would trade this bug for two worse ones: a block closed by an
     * indented `---` would stop being frontmatter at all, and where a slide delimiter
     * followed one, the frontmatter would swallow the rest of the deck and fold it into a
     * value of its own.
     *
     * The match now ends at the end of the closing line rather than three characters into it,
     * which is what takes the rest of that line with it: the content of a slide starts after
     * its delimiter, not inside it. The opener is unchanged, and a block with no `---` line in
     * it is not a block this can find.
    */
    private const FRONTMATTER = '/\A---[^\r\n]*\r?\n([\s\S]*?)^ {0,3}---[^\r\n]*$/m';

    /** An opening YAML code fence, which Slidev would read as frontmatter. */
    private const FENCED_YAML = '/\A[ \t]*`{3,}[ \t]*(?:yaml|yml)\b/';

    /** A note opener: `::note` and nothing else on the line. The case is folded, like every marker. */
    private const OPENS_NOTE = '/\A[ \t]*::note[ \t]*\z/i';

    /** The line a note closes on: `::` and nothing else. */
    private const CLOSES_NOTE = '/\A[ \t]*::[ \t]*\z/';

    /**
     * A whole note, from its opener to the `::` that closes it at the end of the slide.
     *
     * Capture 1 is everything before the opener and capture 2 the note itself. The closer
     * is anchored to the end rather than to the first `::` line, so a deck with two of them
     * has one note carrying both — a `.pptx` slide has one notes pane, and two notes is a
     * deck asking for something the format has one of.
     */
    private const NOTE = '/\A([\s\S]*?)^[ \t]*::note[ \t]*\n([\s\S]*?)^[ \t]*::[ \t]*\z/mi';
    /**
     * The opening fence of a code block: three or more backticks or three or more
     * tildes, indented by no more than three spaces.
     *
     * CommonMark's rule rather than Slidev's, which matches `startsWith` against a
     * backtick run and so recognises neither tildes nor a differently indented block.
     * Capture 1 is the fence character and run, which is what a closing fence is then
     * matched against.
    */
    private const FENCE_OPEN = '/\A {0,3}(`{3,}|~{3,})/';

    /**
     * A heading, with `.` written as the class it matches: a JavaScript dot stops
     * at a carriage return as well as at a newline, so a title stops at one too.
    */
    private const HEADING = '/^(#+) ([^\r\n]*)/m';

    /** A whole fenced code block, stripped before a title is looked for. */
    private const CODE_BLOCK = '/^ {0,3}(?:`{3,}|~{3,})[^\n]*\n[\s\S]*?^ {0,3}(?:`{3,}|~{3,})[ \t]*$/m';
        public function parse(string $markdown): Deck
    {
        // `explode` after folding CRLF rather than a regular expression: the same
        // split as Slidev's `markdown.split(/\r?\n/)`, with no failure mode to
        // handle. A lone carriage return is not a line ending here or there.
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $last = count($lines) - 1;
        $slides = [];
        $start = 0;
        $inHtmlComment = false;
        $inNote = false;

        $index = 0;

        // A `while` rather than a `for` because a frontmatter block and a fenced code block
        // each decide for themselves where the walk resumes, and a `for` whose counter the
        // body writes to is a loop whose step depends on what it is stepping over. The step
        // is at the foot of the body for the same reason: it runs after a block that has
        // moved `$index` on, exactly as the `for`'s would have.
        while ($index <= $last) {
            $line = $lines[$index];
            $trimmed = rtrim($line);

            // Ahead of the comment, and for the same reason: a note is text the deck wrote
            // for the notes pane, and a `---` inside one is a line of that text rather than
            // a delimiter.
            if ($inNote) {
                $inNote = preg_match(self::CLOSES_NOTE, $trimmed) !== 1;
                $index++;

                continue;
            }

            if ($inHtmlComment) {
                $inHtmlComment = self::commentContinues($line, true);
                $index++;

                continue;
            }

            if (str_starts_with($trimmed, '---')) {
                $index = self::delimiter($lines, $trimmed, $index, $last, $start, $slides);
            } elseif (preg_match(self::FENCE_OPEN, $trimmed, $fence) === 1) {
                $index = self::closingFence($lines, $index, $last, $fence[1]);
            } elseif (preg_match(self::OPENS_NOTE, $trimmed) === 1) {
                $inNote = true;
            } else {
                $inHtmlComment = self::commentContinues($line, false);
            }

            $index++;
        }

        // A deck that ends on a delimiter has nothing after it to make a slide of.
        if ($start <= $last) {
            $slides[] = self::slide($lines, $start, count($lines), count($slides));
        }

        return new Deck($markdown, $slides);
    }

    /**
     * A `---` line: the slide being read ends there, and the block after it is the
     * frontmatter of the next one where a line follows carrying something.
     *
     * Both halves in one method, because they are one line's grammar — a delimiter that ends
     * a slide and a delimiter that opens a frontmatter block are the same line, and
     * {@see self::parse()} reads line kinds rather than their halves.
     *
     * What it returns is the last line the delimiter consumed, which the walk steps past:
     * a delimiter that opened nothing returns the line it was handed.
     *
     * @param list<string> $lines
     * @param list<Slide>  $slides
     */
    private static function delimiter(array $lines, string $trimmed, int $index, int $last, int &$start, array &$slides): int
    {
        // An empty block is not a slide: Slidev's `slice()` returns without
        // moving the start, and moving it would turn a deck written with a
        // leading `---` frontmatter block into an empty slide in front of it.
        if ($start !== $index) {
            $slides[] = self::slide($lines, $start, $index, count($slides));
            $start = $index + 1;
        }

        // A run of four dashes or more closes the slide without opening
        // frontmatter — `$trimmed[3] !== '-'` — and the line after has to
        // carry something, or there is no block to read. `--- with a
        // trailing word` does open one, which is why this tests the
        // character rather than the line.
        if (($trimmed[3] ?? '') !== '-' && trim($lines[$index + 1] ?? '') !== '') {
            $start = $index;

            return self::closingDelimiter($lines, $index + 1, $last);
        }

        return $index;
    }

    /**
     * The line the frontmatter block opened before `$from` closes on, or one past the end
     * of the deck when it never closes.
     *
     * One past the end rather than the last line, because the walk steps past whatever it
     * is handed: the closer is then read as the last line of the block rather than as a
     * delimiter of its own. That is also what makes a block which is never closed a slide
     * with no frontmatter rather than an exception, which is what Slidev does with it.
     *
     * @param list<string> $lines
     */
    private static function closingDelimiter(array $lines, int $from, int $last): int
    {
        for ($index = $from; $index <= $last; $index++) {
            if (rtrim($lines[$index]) === '---') {
                return $index;
            }
        }

        return $last + 1;
    }

    /**
     * The line the fence opened at `$open` closes on, or the last line of the deck when it
     * is never closed.
     *
     * The last line rather than one past it, so that a fence running to the end of the deck
     * stops the walk rather than being read as one more line of it — a `---` inside an
     * unclosed fence is code, and treating it as a delimiter would split the slide.
     *
     * The closing fence is the same character, at least as long, indented by no more than
     * the opening one, and carrying nothing else. Slidev leaves the walk where it was when a
     * fence never closes, so every following line is read as ordinary content and the
     * delimiter in it does split.
     *
     * @param list<string> $lines
     */
    private static function closingFence(array $lines, int $open, int $last, string $fence): int
    {
        for ($index = $open + 1; $index <= $last; $index++) {
            if (self::closesFence($lines[$index], $fence[0], strlen($fence))) {
                return $index;
            }
        }

        return $last;
    }

    /**
     * The dialect, written out: what the delimiters do, and the two shapes this
     * port refuses rather than reads differently from Slidev.
     *
     * A `---` line at the top level is a delimiter, so a top-level thematic break
     * cannot be written as a bare `---` inside a slide. `***` is one, and Slidev
     * behaves identically — matching it keeps the dialect predictable for the
     * models trained on it. A run of four dashes is a delimiter too, so `----` is
     * not the way out.
    */
    public static function dialect(): string
    {
        return <<<'TEXT'
            A `---` line closes the slide being read.

            `---` on its own, with something after it on the next line, opens a
            frontmatter block that the next `---` closes. A top-level thematic break
            cannot be written as a bare `---` inside a slide: `***` is one, and
            `----` is not.

            A fenced code block is skipped whole, so a `---` inside one cannot split
            a slide. Backticks or tildes, three or more. The closing fence is the
            same character, at least as long, indented no more than the opener, and
            carrying nothing else. A fence that is never closed runs to the end of
            the deck, so a `---` inside it does not split either.

            Those are CommonMark's rules. Slidev matches the closing fence with
            `startsWith` on the raw line, which is stricter in one direction and
            looser in the other; `tests/fixtures/slidev/divergences.json` records
            each case and why it differs.

            An HTML comment is tracked across lines, so a `---` inside one cannot
            split a slide either.

            The first block is the deck's frontmatter. It is the first slide's, not a
            block of its own: a deck that opens with a `---` block and then a slide
            is one slide carrying both, and `Deck::frontmatter()` returns its keys.

            `::click` and `:::click` are left in the content; they are the renderer's
            step markers, not the splitter's.

            A speaker note is `::note` on a line of its own, its text, and a
            closing `::`, in either case. A `::note` nothing closes is part of
            the slide's text, and the renderer refuses it rather than drawing it.

            Two shapes are refused rather than read:

            - Frontmatter in a fenced `yaml` block. Slidev reads it as frontmatter;
              here it would be a code block, so a slide opening with one is an error.
            - A `title`, `name` or `level` that is not the type it has to be.
            TEXT;
    }

    /**
     * @param list<string> $lines
    */
    private static function slide(array $lines, int $start, int $end, int $index): Slide
    {
        return self::parseSlide(implode("\n", array_slice($lines, $start, $end - $start)), $index, $start, $end);
    }

    private static function parseSlide(string $raw, int $index, int $start, int $end): Slide
    {
        $frontmatter = [];
        $content = $raw;

        if (preg_match(self::FRONTMATTER, $raw, $matches) === 1) {
            $frontmatter = self::frontmatter($matches[1], $index);
            $content = substr($raw, strlen($matches[0]));
        }

        $content = trim($content);

        if (preg_match(self::FENCED_YAML, $content) === 1) {
            throw FencedFrontmatter::at($index + 1);
        }

        [$content, $note] = self::takeNote($content);
        [$title, $level] = self::title($frontmatter, $content, $index);

        return new Slide($index, $raw, $content, $frontmatter, $title, $level, $note, $start, $end);
    }

    /**
     * The frontmatter of a slide, which has to be a mapping: everything read out of
     * it is read by key, so a list or a scalar is a mistake worth naming.
     *
     * @return array<string, mixed>
    */
    private static function frontmatter(string $yaml, int $index): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw MalformedFrontmatter::notYaml($index + 1, $exception);
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw MalformedFrontmatter::notAMapping($index + 1);
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * The note is a `::note` block and its closing `::`, and only that: an opener with
     * nothing closing it is part of the slide's text, which is what
     * {@see \MarkdownPresentation\Render\CommonMark\BlockTranslator} refuses it for.
     *
     * @return array{0: string, 1: ?string} The content without the note, and the note.
    */
    private static function takeNote(string $content): array
    {
        if (preg_match(self::NOTE, $content, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [$content, null];
        }

        // Capture 1 starts at the subject, which is where `\A` puts the whole match, so the
        // opener is its *end* rather than its offset: subtracting the prefix from a zero
        // leaves an empty slide.
        $opener = $match[1][1] + strlen($match[1][0]);
        $note = trim($match[2][0]);

        return [rtrim(substr($content, 0, $opener)), $note === '' ? null : $note];
    }

    /**
     * @param array<string, mixed> $frontmatter
     *
     * @return array{0: ?string, 1: ?int} The title, and how deep it is when it is a heading.
    */
    private static function title(array $frontmatter, string $content, int $index): array
    {
        $title = self::text($frontmatter, 'title', $index) ?? self::text($frontmatter, 'name', $index);

        // A `level:` in the frontmatter wins over the heading's own depth, because
        // that is what it is for: naming a title that is not a heading at all.
        $declared = self::level($frontmatter, $index);

        if ($title !== null) {
            return [$title, $declared];
        }

        // A `#` line inside a fence is a comment in the code, not a title.
        $outsideCode = preg_replace(self::CODE_BLOCK, '', $content) ?? $content;

        if (preg_match(self::HEADING, $outsideCode, $matches) !== 1) {
            return [null, $declared];
        }

        return [trim($matches[2]), $declared ?? strlen($matches[1])];
    }

    /**
     * @param array<string, mixed> $frontmatter
    */
    private static function text(array $frontmatter, string $key, int $index): ?string
    {
        if (!isset($frontmatter[$key])) {
            return null;
        }

        if (!is_string($frontmatter[$key])) {
            throw MalformedFrontmatter::wrongType($index + 1, $key, 'a string');
        }

        return $frontmatter[$key] === '' ? null : $frontmatter[$key];
    }

    /**
     * A `level:` in the frontmatter names the heading depth of a title that is not
     * one.
     *
     * @param array<string, mixed> $frontmatter
    */
    private static function level(array $frontmatter, int $index): ?int
    {
        if (!isset($frontmatter['level'])) {
            return null;
        }

        if (!is_int($frontmatter['level']) || $frontmatter['level'] < 1) {
            throw MalformedFrontmatter::wrongType($index + 1, 'level', 'a positive integer');
        }

        return $frontmatter['level'];
    }

    /**
     * Whether a line closes a fence opened with `$length` of `$character`.
     *
     * CommonMark: the same character, a run at least as long as the opening one, up to
     * three spaces of indentation, and nothing after it. The last of those is why a
     * closing fence is matched whole rather than by `startsWith` — an opening fence's
     * info string would otherwise satisfy it — and the trailing `[ \t]*` is why the
     * line is not trimmed first.
    */
    private static function closesFence(string $line, string $character, int $length): bool
    {
        return preg_match(
            '/\A {0,3}(' . preg_quote($character, '/') . '{' . $length . ',})[ \t]*\z/',
            $line
        ) === 1;
    }

    /**
     * Whether an HTML comment is still open at the end of a line.
     *
     * Slidev's `advanceHtmlCommentState`, which walks the line rather than matching
     * whole comments: a line may open a comment and close it, close one and open
     * another, or do either across the line boundary, and only the running state
     * answers the question.
    */
    private static function commentContinues(string $line, bool $inside): bool
    {
        $cursor = 0;
        $length = strlen($line);

        while ($cursor < $length) {
            if ($inside) {
                $end = strpos($line, '-->', $cursor);
            } else {
                $start = strpos($line, '<!--', $cursor);

                // Nothing opens on this line and nothing is open, so nothing is left open.
                // This is the one answer the two halves below do not share.
                if ($start === false) {
                    return false;
                }

                $end = strpos($line, '-->', $start + 4);
            }

            // Shared on purpose: both halves have found the end of a comment, or have run
            // out of line looking for one. A comment running off the end of the line is what
            // the next line has to be read in, and saying so once is what keeps the two
            // halves from disagreeing about it.
            if ($end === false) {
                return true;
            }

            $inside = false;
            $cursor = $end + 3;
        }

        return $inside;
    }
}
