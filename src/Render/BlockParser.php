<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Filesystem\PathScope;
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
    /**
     * The furthest step a `::click` on one slide may name.
     *
     * A step is a slide: every step of a build-up is written as a `.pptx` part of its own,
     * with its shapes, its rels and its entry in `[Content_Types].xml`. The Markdown that
     * asks for them is kilobytes, so nothing else bounds `::click=2147483647` — measured on
     * this package, 16 bytes of deck render 500 slides in a tenth of a second, and
     * `::click=99999999999999999999` saturates the cast to `PHP_INT_MAX` and asks for two
     * billion, which is why {@see self::jump()} refuses the argument rather than casting it.
     *
     * 500 is two orders of magnitude above any build-up a person writes — a slide with five
     * hundred clicks on it is not a slide — and the whole ceiling renders in about a tenth
     * of a second, so a deck that reaches it was not written to.
     */
    public const MAX_STEP = 500;

    /** The step a slide with no marker in it is drawn at, which is every step's starting point. */
    private const FIRST_STEP = 1;

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
     * A step boundary: `::click` alone on a line, carrying the step it names.
     *
     * Two colons, and the line has to be nothing but the marker. Anchored at the start of
     * the line and closed at its end, so `::click` inside a sentence is the text of that
     * sentence rather than a step a reader cannot predict.
     *
     * Capture 1 is the step, absent for the bare form and empty for `::click=` with nothing
     * after the equals, which is a mistake {@see self::jump()} refuses by name.
     *
     * The three-colon {@see self::REGION} cannot match here and cannot be matched by this:
     * after two colons this pattern wants `click` and the region has a third colon, and
     * after three the region wants `click` and this has none. Both are checked rather than
     * assumed, because the pair is the whole grammar.
    */
    private const MARKER = '/\A[ \t]*::click[ \t]*(?:=[ \t]*(.*?)[ \t]*)?[ \t]*\z/';

    /**
     * The same boundary, opened three times: what follows until `:::` is one step of its own.
     *
     * Capture 1 is named by {@see self::MARKER} and means the same thing, because both go
     * through {@see self::jump()} against the one step counter.
    */
    private const REGION = '/\A[ \t]*:::click[ \t]*(?:=[ \t]*(.*?)[ \t]*)?[ \t]*\z/';

    /** The line a region closes on. Three colons and nothing else. */
    private const CLOSES_REGION = '/\A[ \t]*:::[ \t]*\z/';

    /** A note opener {@see \MarkdownPresentation\Document\DeckParser} did not take off the slide. */
    private const NOTE = '~^[ \t]*::note[ \t]*$~m';

    /** The info string of a fence, whose first word is the language. */
    private const LANGUAGE = '/\A(\S*)/';

    /**
     * An HTML comment: the whole of it where it is closed, and the rest of the line where
     * it is not.
     *
     * A comment is refused wherever it stands rather than read as text, because this format
     * has no HTML in it. A step is `::click` and a note is `::note`, and
     * {@see \MarkdownPresentation\Document\DeckParser} has taken the second off the slide by
     * the time the content reaches here; a comment left is a deck written for another tool.
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

    /**
     * A `:::` line that is neither a region nor its closer, which is what Slidev nests
     * content in.
     *
     * The two negative lookaheads are what leave the three-colon grammar alone: a line with
     * nothing after its colons is a region closing, and `click` after them is a region
     * opening, so neither is a container and neither is refused. Anything else under three
     * colons is.
     */
    private const CONTAINER = '/^[ \t]*:::(?![ \t]*$)(?!click\b)[^\n]*/m';

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
     * `$slide` and `$directory` are for the failures, and `$files` is the policy behind half
     * of them: an image that does not resolve, one that resolves outside the directories the
     * caller allowed, and a step that goes backwards are all read out of a slide its author
     * can go and find.
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
    public function parse(string $content, int $slide, string $directory, PathScope $files): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        // Before anything is read rather than as each line goes by, so that a slide is
        // judged whole: a directive inside a table row or a wrapped list item is drawn no
        // less literally than one standing on a line of its own.
        self::refuse($slide, self::prose($lines));

        return $this->blocks($lines, $slide, $directory, $files, self::FIRST_STEP);
    }

    /**
     * The walk itself, at a step the caller has already reached.
     *
     * A method of its own rather than the body of {@see self::parse()} because a region is
     * read by calling this again with the lines between its fences and the step its opener
     * named, and a slide has one walk and not one per region — a `::click` written inside a
     * region moves the same counter from the step the region opened at.
     *
     * @param list<string> $lines
     *
     * @return list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>
     */
    private function blocks(array $lines, int $slide, string $directory, PathScope $files, int $step): array
    {
        $blocks = [];
        $quote = [];
        $paragraph = [];

        $index = 0;

        // A `while` rather than a `for` because a code block, a table and a list each move
        // the walk themselves, and a `for` whose counter the body writes to is a loop whose
        // step depends on what it is stepping over.
        while ($index < count($lines)) {
            $line = $lines[$index];
            $index++;

            // A `:::` the walk reaches has not closed anything: the one that closes a region
            // is skipped when the region is read, and the one that opens a region is matched
            // below. Reaching one is a closer with nothing to close, and it is refused rather
            // than drawn, because three colons on a slide read as a mistake and two do not.
            if (preg_match(self::CLOSES_REGION, $line) === 1) {
                throw UnknownMarker::strayCloser($slide);
            } elseif (preg_match(self::REGION, $line, $region) === 1) {
                // Everything before the opener stays on the step it was written at, and
                // everything inside is one step of its own, so the opener names that step
                // through the same jump a bare marker does.
                $end = self::closingRegion($lines, $index, $slide);
                $this->flush($blocks, $quote, $paragraph, $step);
                $step = self::jump($slide, $region[1] ?? null, $step);

                array_push($blocks, ...$this->blocks(
                    array_slice($lines, $index, $end - $index),
                    $slide,
                    $directory,
                    $files,
                    $step,
                ));

                $index = $end + 1;

                continue;
            } elseif (preg_match(self::MARKER, $line, $marker) === 1) {
                // Capture 1 is the step and nothing below looks at it: absent is the bare form
                // and an empty capture is a `::click=` with nothing after the equals, and the
                // two are not the same marker. `jump()` is what tells them apart.
                //
                // The step opens *after* the block the marker closes. Advancing first would
                // put the block a writer wrote above the marker on the step below it, which
                // is the reverse of what the author meant.
                $this->flush($blocks, $quote, $paragraph, $step);
                $step = self::jump($slide, $marker[1] ?? null, $step);

                continue;
            }

            if (trim($line) === '') {
                $this->flush($blocks, $quote, $paragraph, $step);

                continue;
            }

            // Consecutive `>` lines are one block, the way they are in Markdown: a quote
            // written across two lines is one sentence, and two shapes are two of the
            // indentation, the gap and the line height that one sentence would not have.
            // Only the first of them opens the block — the rest are still inside it.
            if (preg_match(self::QUOTE, $line, $quoted) === 1) {
                if ($quote === []) {
                    $this->flush($blocks, $quote, $paragraph, $step);
                }

                $quote[] = $quoted[1];

                continue;
            }

            // The line was stepped over above, so this is where a block ends rather than where
            // it began, and the walk resumes on the line after that.
            [$block, $end] = $this->block($lines, $index - 1, $slide, $directory, $files, $step);
            $index = $end + 1;

            if ($block !== null) {
                $this->flush($blocks, $quote, $paragraph, $step);
                $blocks[] = $block;

                continue;
            }

            $paragraph[] = trim($line);
        }

        $this->flush($blocks, $quote, $paragraph, $step);

        return $blocks;
    }

    /**
     * The block the line at `$index` is a whole on its own, and the line that block ends on.
     *
     * A heading, a rule and an image are one line and nothing more, so the walk does not have
     * to learn how far any of them reaches. A code block, a pipe table and a list each reach
     * past the line that opens them and are handed the lines that follow it: a walk that asked
     * how far each of them reached would be carrying three notions of "ends here" at once.
     * A null block and the line it was asked about, where the line opens none of the six.
     *
     * {@see self::blocks()} asks this of a line its quote has not taken, which is what keeps a
     * quoted pipe row a quote: `> a|b` carries a `|` and an alignment row below it would make
     * it a table, and a table headed `> a` is not one.
     *
     * The union rather than {@see Block} because {@see self::flush()} appends to a list
     * every kind of block may go into, and a wider answer here would need narrowing there.
     *
     * @param list<string> $lines
     *
     * @return array{0: Code|Heading|Image|ListBlock|Rule|Table|null, 1: int}
     */
    private function block(array $lines, int $index, int $slide, string $directory, PathScope $files, int $step): array
    {
        $line = $lines[$index];

        // One question — "is this line a whole block?" — asked as one expression, so the
        // answer is the block rather than a place the caller has to go and look. The three
        // that reach past the line they open already answer with the line they end on.
        return match (true) {
            preg_match(self::HEADING, $line, $heading) === 1
                => [new Heading($this->inline->parse($heading[2]), strlen($heading[1]), $step), $index],
            preg_match(self::RULE, $line) === 1 => [new Rule($step), $index],
            preg_match(self::IMAGE, trim($line), $matches) === 1
                => [$this->image($matches, $slide, $directory, $files, $step), $index],
            preg_match(self::FENCE, $line, $fence) === 1 => self::code($lines, $index, $fence, $step),
            str_contains($line, '|') && preg_match(self::ALIGNMENT_ROW, $lines[$index + 1] ?? '') === 1
                => $this->table($lines, $index, $step),
            // A bullet and an ordered item are one block kind: paragraphs of the same frame,
            // with `self::list()` reading the indent off whichever pattern matched.
            preg_match(self::BULLET, $line, $item) === 1 || preg_match(self::ORDERED, $line, $item) === 1
                => self::list($lines, $index, $this->inline, $item, $step),
            default => [null, $index],
        };
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

        $index = 0;

        while ($index < count($lines)) {
            if (preg_match(self::FENCE, $lines[$index], $fence) === 1) {
                $index = self::closingFence($lines, $index, $fence) + 1;

                continue;
            }

            if (preg_match(self::MARKER, $lines[$index]) !== 1) {
                $prose[] = $lines[$index];
            }

            $index++;
        }

        return implode("\n", $prose);
    }

    /**
     * The line a fence opened at `$open` closes on, or one past the end of the slide when it
     * is never closed.
     *
     * The walk resumes after this line, which is what makes the difference between a fence
     * that closes and one that does not: a `---` inside an unclosed fence must be read as
     * code, and returning one past the end rather than the last line is what keeps the walk
     * from asking about it.
     *
     * @param list<string>               $lines
     * @param array{0: string, 1: string, 2: string} $fence
     */
    private static function closingFence(array $lines, int $open, array $fence): int
    {
        for ($index = $open + 1; $index < count($lines); $index++) {
            if (preg_match(self::closes($fence), rtrim($lines[$index])) === 1) {
                return $index;
            }
        }

        return count($lines);
    }

    /**
     * The line the region opened before `$from` closes on, or a refusal where it never does.
     *
     * An unclosed region is refused rather than read to the end of the slide, because
     * everything after it would then be one step: a deck that meant three and wrote one is
     * a deck whose slides do not say what its author wrote.
     *
     * @param list<string> $lines
     *
     * @throws UnknownMarker
     */
    private static function closingRegion(array $lines, int $from, int $slide): int
    {
        for ($index = $from; $index < count($lines); $index++) {
            if (preg_match(self::CLOSES_REGION, $lines[$index]) === 1) {
                return $index;
            }
        }

        throw UnknownMarker::unclosed($slide);
    }

    /**
     * The spellings a slide can be written in that this library cannot draw.
     *
     * Comments, then the elements, then the containers, then a note nobody closed — and each
     * message names the spelling that would have worked, which is the whole of what a
     * refusal is for.
     *
     * @throws UnknownDirective
     */
    private static function refuse(int $slide, string $content): void
    {
        // A finding as one line, so a comment written across four of them can still be
        // quoted. A local rather than a method of its own: `refuse()` is its only caller.
        $oneLine = static fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', $text));

        if (preg_match(self::COMMENT, $content, $comment) === 1) {
            throw UnknownDirective::comment($slide, $oneLine($comment[0]));
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
            throw UnknownDirective::container($slide, $oneLine($fence[0]));
        }

        if (preg_match(self::NOTE, $content, $note) === 1) {
            throw UnknownDirective::unclosedNote($slide);
        }
    }

    /**
     * The step a marker names, refusing every way of getting that wrong.
     *
     * One function for the bare marker and for a region opener, because both answer the same
     * question against the same counter: there is no second numbering path for the
     * three-colon form to drift away from.
     *
     * @throws UnknownMarker
     */
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

        // Cast first and quoted second: a twenty-digit step saturates to `PHP_INT_MAX`
        // rather than overflowing, so the refusal names the twenty digits the deck wrote
        // rather than the number PHP could hold.
        if ($number > self::MAX_STEP) {
            throw UnknownMarker::tooLarge($slide, $argument, self::MAX_STEP);
        }

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
     * The alignment row is read as text and not parsed: `---:` is an alignment, and a
     * parser handed one would read `---` as a rule.
     *
     * @param list<string> $lines
     *
     * @return array{0: Table, 1: int}
    */
    private function table(array $lines, int $index, int $step): array
    {
        // A cell is a paragraph's worth of text and takes the same inline markup one does,
        // so a row is read as the runs each of its cells is drawn from.
        $rows = [array_map($this->inline->parse(...), self::cells($lines[$index]))];

        // Inline rather than a method of its own: the alignment row is read once, here, and a
        // private method reached only through `array_map(self::alignment(...))` is a reader
        // having to leave this one to find out what a `:` in the second row does.
        $align = array_map(
            static fn (string $cell): string => match (true) {
                str_starts_with($cell, ':') && str_ends_with($cell, ':') => 'center',
                str_ends_with($cell, ':') => 'right',
                default => 'left',
            },
            self::cells($lines[$index + 1]),
        );

        $end = $index + 1;

        while (
            $end + 1 < count($lines)
            && trim($lines[$end + 1]) !== ''
            && str_contains($lines[$end + 1], '|')
        ) {
            $rows[] = array_map($this->inline->parse(...), self::cells($lines[$end + 1]));
            $end++;
        }

        return [new Table($rows, $align, $step), $end];
    }

    /**
     * The cells of a pipe row, with the optional outer pipes removed.
     *
     * @return list<string>
    */
    private static function cells(string $line): array
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
     * The markers are read per item rather than for the list, because a deck mixes them:
     * `- a`, `1. b` and `- c` are one list with a bullet, a number and a bullet in it.
     *
     * @param list<string>                             $lines
     * @param array{0: string, 1: string, 2: string, 3: string} $first The match for the
     *                                                                   line at `$index`.
     *
     * @return array{0: ListBlock, 1: int}
    */
    private static function list(array $lines, int $index, InlineParser $inline, array $first, int $step): array
    {
        // The base is the first item's indent, not the last one's: a continuation is
        // relative to where the list began, so a sub-item does not swallow its own text.
        $base = strlen($first[1]);
        $number = self::number($first);
        $open = [$base];
        $items = [[
            'level' => self::depth($open, $base),
            'runs' => $inline->parse($first[3]),
            'ordered' => $number !== null,
            'start' => $number,
        ]];
        $end = $index;

        for ($cursor = $index + 1; $cursor < count($lines); $cursor++) {
            $line = $lines[$cursor];

            if (trim($line) === '' || preg_match(self::RULE, $line) === 1 || preg_match(self::FENCE, $line) === 1) {
                break;
            }

            if (preg_match(self::BULLET, $line, $next) === 1 || preg_match(self::ORDERED, $line, $next) === 1) {
                $level = self::depth($open, strlen($next[1]));
                $number = self::number($next);
                $items[] = [
                    'level' => $level,
                    'runs' => $inline->parse($next[3]),
                    'ordered' => $number !== null,
                    'start' => self::start($items, $level, $number),
                ];
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

        return [new ListBlock($items, $step), $end];
    }

    /**
     * The number an item was written with, or null where it has none.
     *
     * One function answers both what an item is and where its numbering begins: a bullet's
     * second capture is its marker and never a digit, an ordered item's is its number, and
     * a deck that mixes the two writes them in any order.
     *
     * @param array{0: string, 1: string, 2: string, 3: string} $item
     */
    private static function number(array $item): ?int
    {
        return ctype_digit($item[2]) ? (int) $item[2] : null;
    }

    /**
     * The number to start a numbering at, or null where this item carries on from the one
     * above it.
     *
     * Every item's number, rather than the first one's, would restate the numbering at
     * each item — `1. 2. 3.` comes out as three numberings that happen to agree. The item
     * below a level change, or below a bullet, starts one again, because that is where a
     * reader expects the count to begin again.
     *
     * @param list<array{level: int, runs: list<Run>, ordered: bool, start: ?int}> $items
     */
    private static function start(array $items, int $level, ?int $written): ?int
    {
        if ($written === null) {
            return null;
        }

        $previous = $items[count($items) - 1] ?? null;

        return $previous === null || $previous['level'] !== $level || $previous['start'] === null
            ? $written
            : null;
    }

    /**
     * The nesting depth this indent puts an item at, holding the levels open above it.
     *
     * A level per step rather than per two spaces, which is what CommonMark reads and what
     * four spaces of indent means: `- a` followed by `    - b` is a list inside a list, not
     * a list inside two lists. Counting the spaces made four of them skip a level, so a
     * sub-list written at a tab's width was drawn two levels down with nothing at the first.
     *
     * @param list<int> $open The indents of the levels currently open, shallowest first.
     */
    private static function depth(array &$open, int $indent): int
    {
        while (count($open) > 1 && $indent < (int) end($open)) {
            array_pop($open);
        }

        if ($indent > (int) end($open)) {
            $open[] = $indent;
        }

        return count($open);
    }

    /**
     * @param array{0: string, 1: string, 2: string} $image The match for the image.
     *
     * @throws ImageNotFound
     * @throws UnsupportedImageFormat
     */
    private function image(array $image, int $slide, string $directory, PathScope $files, int $step): Image
    {
        $written = $image[2];

        if (PathScope::isUrl($written)) {
            throw ImageNotFound::aUrl($slide, $written);
        }

        // Where a path is written *from* says nothing about whether it is allowed: what is
        // allowed is where it lands. This joins the two spellings a deck can write, and the
        // containment below is the same test whichever one it wrote.
        $joined = str_starts_with($written, '/') ? $written : rtrim($directory, '/') . '/' . $written;

        if (!is_file($joined)) {
            throw ImageNotFound::at($slide, $written, $joined);
        }

        // `realpath()` rather than the path as written, because `..` and a symlink both walk
        // out of a directory that a prefix test says they are inside, and a symlink is not
        // something a deck meant either.
        $resolved = PathScope::canonical($joined);

        if ($resolved === null || !$files->holds($resolved)) {
            throw ImageNotFound::outside($slide, $written, $resolved ?? $joined, $files->roots());
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
     * Close whatever the last lines left open: a quote first and then a paragraph, because
     * that is the order they were written in.
     *
     * Both buffers, rather than the paragraph one, because a quote runs over as many lines
     * as it is written in. The line after a quote closes the quote the way a blank line
     * does, without being one.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     * @param list<string>                                                   $quote
     * @param list<string>                                                   $paragraph
    */
    private function flush(array &$blocks, array &$quote, array &$paragraph, int $step): void
    {
        if ($quote !== []) {
            $blocks[] = new Quote($this->inline->parse(implode("\n", $quote)), $step);
            $quote = [];
        }

        if ($paragraph === []) {
            return;
        }

        $blocks[] = new Paragraph($this->inline->parse(implode("\n", $paragraph)), $step);
        $paragraph = [];
    }
}
