<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;

/**
 * The block structure of a slide: its headings, paragraphs, lists, code, tables, quotes,
 * rules and images, in the order they were written and each on the step it appears.
 *
 * One line-based pass, because the deck has already been split into slides and this only
 * has to describe what is inside one. The grammar is the subset a slide actually uses, and
 * it is CommonMark's where the two differ in what matters here: a fence that is never closed
 * runs to the end of the slide rather than being read as text, and a list ends at the first
 * line that starts neither another item nor an indented continuation of one.
*/
final class BlockParser
{
    /** The opening fence of a code block. Capture 1 is the character and run. */
    private const FENCE = '/\A {0,3}(`{3,}|~{3,})(.*)\z/';

    /** An ATX heading, with the closing hashes a writer may leave on the end. */
    private const HEADING = '/\A {0,3}(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*\z/';

    /** A thematic break of three or more of one character, which `---` never is here. */
    private const RULE = '/\A {0,3}(?:(?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,})\z/';

    private const BULLET = '/\A( *)([*+-])[ \t]+(.*)\z/';
    private const ORDERED = '/\A( *)(\d+)[.)][ \t]+(.*)\z/';
    private const QUOTE = '/\A {0,3}>[ \t]?(.*)\z/';

    /** A line that could be the alignment row that makes a pipe row the header of a table. */
    private const ALIGNMENT_ROW = '/\A[ \t]*\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*\z/';

    /** A line that is nothing but an image. */
    private const IMAGE = '/\A!\[([^\]]*)\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)\z/';

    /**
     * A `v-click` marker standing alone on a line, carrying the step it names.
     *
     * A marker is only honoured on a line of its own. Slidev also accepts one closing an
     * element — `<!-- v-click -->` at the end of a list item — and honouring that here
     * would mean splitting one list block into several, which is a different feature from
     * the one this renderer claims.
     *
     * Capture 1 is the step, absent for the bare form and empty for `v-click=` with
     * nothing after the equals, which is a mistake.
     *
     * The body between the markers is {@see DeckParser::MARKER_BODY} rather than a second
     * copy of it. {@see DeckParser} decides what a trailing comment is before a slide ever
     * reaches here, so the shape it calls a note and the shape this calls a step have to be
     * the same one — and a deck ending on a marker is what notices when they are not.
    */
    private const MARKER = '/\A[ \t]*<!--[ \t]*' . DeckParser::MARKER_BODY . '-->[ \t]*\z/';

    /** The info string of a fence, whose first word is the language. */
    private const LANGUAGE = '/\A(\S*)/';

    /**
     * An HTML comment: the whole of it where it is closed, and the rest of the line where
     * it is not.
     *
     * A comment is refused wherever it stands rather than read as text, because the only two
     * a slide carries are a step and the note after its last line, and both have been taken
     * by the time the content reaches here.
     */
    private const COMMENT = '/<!--.*?-->|<!--.*?(?:\n|\z)/s';

    /**
     * An HTML tag: capture 1 is its name, capture 2 its attributes.
     *
     * Every tag, not only the ones that are refused, so that telling a directive from ordinary
     * text is a question about the tag rather than a question about what was found.
     */
    private const TAG = '~</?([A-Za-z][A-Za-z0-9:._-]*)((?:\s[^<>]*)?)/?>~';

    /** A `v-*` directive in a tag's attributes, which is what `v-if` is. */
    private const ATTRIBUTE = '/\sv-[A-Za-z]/';

    /** The line a container fence is opened on, which Slidev nests content in. */
    private const CONTAINER = '/^[ \t]*:::[^\n]*/m';

    /**
     * `$inline` is public because a slide's title is normalised with the same parser its
     * body was read with: {@see \MarkdownPresentation\Render\PresentationRenderer} decides
     * whether a heading is that title by comparing the two, and two parsers would be two
     * definitions of what the text of a run of markup is.
     */
    public function __construct(
        public readonly InlineParser $inline = new InlineParser(),
        private readonly ImageConverter $images = new ImageConverter(),
    ) {
    }

    /**
     * The blocks of one slide's content.
     *
     * `$slide` and `$directory` are for the failures: an image that does not resolve and a
     * step that goes backwards are both read out of a slide its author can go and find.
     *
     * The union rather than {@see Block}, because it is the whole of what this method
     * makes: a caller that adds a ninth kind of block has to say here that it is one.
     *
     * @return list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>
     *
     * @throws ImageNotFound
     * @throws UnknownDirective
     * @throws UnknownMarker
     * @throws UnsupportedImageFormat
    */
    public function parse(string $content, int $slide, string $directory): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        // Before anything is read rather than as each line goes by, so that a slide is
        // judged whole: a directive inside a table row or a wrapped list item is drawn no
        // less literally than one standing on a line of its own.
        self::refuse($slide, self::prose($lines));

        $blocks = [];
        $paragraph = [];
        $step = 1;

        for ($index = 0; $index < count($lines); $index++) {
            if (preg_match(self::FENCE, $lines[$index], $fence) === 1) {
                [$code, $index] = self::code($lines, $index, $fence, $step);

                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $blocks[] = $code;

                continue;
            }

            [$isMarker, $argument] = self::marker($lines[$index]);

            if ($isMarker) {
                // The step opens *after* the block the marker closes. Advancing first would
                // put the block a writer wrote above the marker on the step below it, which
                // is the reverse of what the author meant.
                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $step = self::jump($slide, $argument, $step);

                continue;
            }

            $line = $lines[$index];

            if (trim($line) === '') {
                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];

                continue;
            }

            if (preg_match(self::HEADING, $line, $heading) === 1) {
                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $blocks[] = new Heading($this->inline->parse($heading[2]), strlen($heading[1]), $step);

                continue;
            }

            if (preg_match(self::RULE, $line) === 1) {
                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $blocks[] = new Rule($step);

                continue;
            }

            if (preg_match(self::QUOTE, $line, $quoted) === 1) {
                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $blocks[] = new Quote($this->inline->parse($quoted[1]), $step);

                continue;
            }

            if (preg_match(self::IMAGE, trim($line), $image) === 1) {
                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $blocks[] = self::image($image, $slide, $directory, $step);

                continue;
            }

            if (str_contains($line, '|') && preg_match(self::ALIGNMENT_ROW, $lines[$index + 1] ?? '') === 1) {
                [$table, $index] = self::table($lines, $index, $step);

                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $blocks[] = $table;

                continue;
            }

            if (preg_match(self::BULLET, $line, $item) === 1) {
                [$list, $index] = self::list($lines, $index, $this->inline, false, $item, $step);

                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $blocks[] = $list;

                continue;
            }

            if (preg_match(self::ORDERED, $line, $item) === 1) {
                [$list, $index] = self::list($lines, $index, $this->inline, true, $item, $step);

                $blocks = self::flush($blocks, $paragraph, $step, $this->inline);

                $paragraph = [];
                $blocks[] = $list;

                continue;
            }

            $paragraph[] = trim($line);
        }

        return self::flush($blocks, $paragraph, $step, $this->inline);
    }

    /**
     * Whether a line is a `v-click` marker, and the step it names.
     *
     * Null means the bare form and `''` means `v-click=` with nothing after the equals. The
     * two are told apart because they are not the same marker: the bare form is the next
     * step, and an equals with nothing after it is a writer who meant to number a step and
     * did not.
     *
     * @return array{0: bool, 1: string|null} True and the step number, false when the line
     *                                    is not a marker at all.
    */
    private static function marker(string $line): array
    {
        return preg_match(self::MARKER, $line, $marker) === 1
            ? [true, $marker[1] ?? null]
            : [false, null];
    }

    /**
     * The slide's own text, with the two things this library acts on left out of it.
     *
     * Code fences and step markers. A comment inside a fence is a line of code, and a deck
     * quoting HTML in a code block is the ordinary way to show it — refusing there would be
     * the loud failure pointed at the wrong line. A marker standing on a line of its own is
     * the one comment the grammar acts on; one sharing its line with a word is not a marker,
     * and what is left of that line is what the refusal below is looking at.
     *
     * @param list<string> $lines
     */
    private static function prose(array $lines): string
    {
        $prose = [];

        for ($index = 0; $index < count($lines); $index++) {
            if (preg_match(self::FENCE, $lines[$index], $fence) === 1) {
                // To the closing fence, or to the end of the slide: an unclosed fence is code
                // to the end of the slide, as {@see self::code()} reads it.
                for ($index++; $index < count($lines); $index++) {
                    if (preg_match(self::closes($fence), rtrim($lines[$index])) === 1) {
                        break;
                    }
                }

                continue;
            }

            if (preg_match(self::MARKER, $lines[$index]) === 1) {
                continue;
            }

            $prose[] = $lines[$index];
        }

        return implode("\n", $prose);
    }

    /**
     * The three spellings a slide can be written in that this library cannot draw.
     *
     * Comments first, then the elements, then the containers, and each message names the
     * spelling that would have worked — which is the whole of what a refusal is for.
     *
     * @throws UnknownDirective
     */
    private static function refuse(int $slide, string $content): void
    {
        if (preg_match(self::COMMENT, $content, $comment) === 1) {
            throw UnknownDirective::comment($slide, self::oneLine($comment[0]));
        }

        preg_match_all(self::TAG, $content, $tags, PREG_SET_ORDER);

        foreach ($tags as $tag) {
            // The name or an attribute: `<v-click>` and `<div v-if="x">` are the same
            // directive, and the second one is written like ordinary HTML.
            if (str_starts_with(strtolower($tag[1]), 'v-') || preg_match(self::ATTRIBUTE, $tag[2]) === 1) {
                throw UnknownDirective::element($slide, $tag[0]);
            }
        }

        if (preg_match(self::CONTAINER, $content, $fence) === 1) {
            throw UnknownDirective::container($slide, self::oneLine($fence[0]));
        }
    }

    /** A finding as one line, so a comment written across four of them can still be quoted. */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private static function jump(int $slide, ?string $argument, int $step): int
    {
        if ($argument === null) {
            return $step + 1;
        }

        if ($argument === '') {
            throw UnknownMarker::empty($slide);
        }

        if (preg_match('/\A\d+\z/', $argument) !== 1) {
            throw UnknownMarker::notANumber($slide, $argument);
        }

        $number = (int) $argument;

        if ($number <= $step) {
            throw UnknownMarker::backwards($slide, $number, $step);
        }

        return $number;
    }

    /**
     * The fenced block starting at `$index`, and the line it ends on.
     *
     * @param list<string>                          $lines
     * @param array{0: string, 1: string, 2: string} $fence The match for the opening fence.
     *
     * @return array{0: Code, 1: int}
    */
    private static function code(array $lines, int $index, array $fence, int $step): array
    {
        $closing = self::closes($fence);
        preg_match(self::LANGUAGE, trim($fence[2]), $language);
        $body = [];

        for ($cursor = $index + 1; $cursor < count($lines); $cursor++) {
            if (preg_match($closing, rtrim($lines[$cursor])) !== 1) {
                $body[] = $lines[$cursor];

                continue;
            }

            return [new Code($body, $language[1] ?? '', $step), $cursor];
        }

        // An unclosed fence runs to the end of the slide. CommonMark's rule, and the one
        // that stops a single stray backtick from turning the rest of a deck into code.
        return [new Code($body, $language[1] ?? '', $step), count($lines) - 1];
    }

    /**
     * The pattern a fence opened by `$fence` is closed with.
     *
     * One pattern for {@see self::code()} and {@see self::prose()}, because the two have to
     * agree about where a code block ends: a block the second one still sees prose in is a
     * block whose HTML the first one refuses.
     *
     * @param array{0: string, 1: string, 2: string} $fence
     */
    private static function closes(array $fence): string
    {
        return '/\A {0,3}' . preg_quote($fence[1][0], '/') . '{' . strlen($fence[1]) . ',}[ \t]*\z/';
    }

    /**
     * The pipe table whose header row is `$index`, and the line its last row is on.
     *
     * @param list<string> $lines
     *
     * @return array{0: Table, 1: int}
    */
    private static function table(array $lines, int $index, int $step): array
    {
        $rows = [self::row($lines[$index])];
        $align = array_map(self::alignment(...), self::row($lines[$index + 1]));
        $end = $index + 1;

        while (
            $end + 1 < count($lines)
            && trim($lines[$end + 1]) !== ''
            && str_contains($lines[$end + 1], '|')
        ) {
            $rows[] = self::row($lines[$end + 1]);
            $end++;
        }

        return [new Table($rows, $align, $step), $end];
    }

    private static function alignment(string $cell): string
    {
        return match (true) {
            str_starts_with($cell, ':') && str_ends_with($cell, ':') => 'center',
            str_ends_with($cell, ':') => 'right',
            default => 'left',
        };
    }

    /**
     * The cells of a pipe row, with the optional outer pipes removed.
     *
     * @return list<string>
    */
    private static function row(string $line): array
    {
        $cells = explode('|', trim($line));

        if (trim($cells[0]) === '') {
            array_shift($cells);
        }

        if ($cells !== [] && trim($cells[count($cells) - 1]) === '') {
            array_pop($cells);
        }

        return array_map(static fn (string $cell): string => trim($cell), $cells);
    }

    /**
     * The list whose first item is `$index`, and the line its last item is on.
     *
     * @param list<string>                             $lines
     * @param array{0: string, 1: string, 2: string, 3: string} $first The match for the
     *                                                                   line at `$index`.
     *
     * @return array{0: ListBlock, 1: int}
    */
    private static function list(array $lines, int $index, InlineParser $inline, bool $ordered, array $first, int $step): array
    {
        // The base is the first item's indent, not the last one's: a continuation is
        // relative to where the list began, so a sub-item does not swallow its own text.
        $base = strlen($first[1]);
        $items = [['level' => self::depth($first[1]), 'runs' => $inline->parse($first[3])]];
        $end = $index;

        for ($cursor = $index + 1; $cursor < count($lines); $cursor++) {
            $line = $lines[$cursor];

            if (trim($line) === '' || preg_match(self::RULE, $line) === 1 || preg_match(self::FENCE, $line) === 1) {
                break;
            }

            if (preg_match(self::BULLET, $line, $next) === 1 || preg_match(self::ORDERED, $line, $next) === 1) {
                $items[] = ['level' => self::depth($next[1]), 'runs' => $inline->parse($next[3])];
                $end = $cursor;

                continue;
            }

            if (preg_match('/\A( +)\S/', $line, $indented) !== 1 || strlen($indented[1]) <= $base) {
                break;
            }

            $last = count($items) - 1;
            $items[$last]['runs'] = $inline->parse(InlineParser::text($items[$last]['runs']) . ' ' . trim($line));
            $end = $cursor;
        }

        return [new ListBlock($items, $ordered, $step), $end];
    }

    /** An item's indent as a nesting depth: every two spaces is one level. */
    private static function depth(string $indent): int
    {
        return intdiv(strlen($indent), 2) + 1;
    }

    /**
     * @param array{0: string, 1: string, 2: string} $image The match for the image.
     *
     * @throws ImageNotFound
     * @throws UnsupportedImageFormat
     */
    private function image(array $image, int $slide, string $directory, int $step): Image
    {
        $written = $image[2];
        $resolved = str_starts_with($written, '/') ? $written : rtrim($directory, '/') . '/' . $written;

        if (!is_file($resolved)) {
            throw ImageNotFound::at($slide, $written, $resolved);
        }

        return new Image(
            $written,
            $resolved,
            $this->images->png($slide, $written, $resolved),
            $image[1],
            $step,
        );
    }

    /**
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     * @param list<string>                                                   $paragraph
     *
     * @return list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>
    */
    private static function flush(array $blocks, array $paragraph, int $step, InlineParser $inline): array
    {
        if ($paragraph === []) {
            return $blocks;
        }

        $blocks[] = new Paragraph($inline->parse(implode("\n", $paragraph)), $step);

        return $blocks;
    }
}