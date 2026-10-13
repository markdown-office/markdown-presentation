<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading as LeagueHeading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock as LeagueList;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Table\Table as LeagueTable;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Exception\UnsupportedLink;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\Block\Block;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image as ImageBlock;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph as ParagraphBlock;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Render\ImageConverter;
use MarkdownPresentation\Render\Run;
use MarkdownPresentation\Render\SlideBlocks;

/**
 * A slide read by league/commonmark, as the blocks a slide is made of.
 *
 * Nothing downstream of this class knows it exists: the blocks it returns are the same
 * objects the hand-written walk returns, in the same order, with the same step and the same
 * column beside each one, so a slide's geometry, its shapes and its click chain cannot tell
 * which parser read the deck.
 *
 * What is left different is a difference of *reading*, not of drawing, and each is where
 * CommonMark says something the line-based walk does not: a `===` underline is a heading, an
 * indented block is code, the emphasis inside a link's label is kept, and an emphasis
 * delimiter that does not close is not a run at all.
 */
final class BlockTranslator
{
    /**
     * A quoted line: three spaces of indentation, a `>` and one space of it. Whatever is
     * left is the sentence.
     *
     * Written out rather than read off the parser's own quote block because a quote on a
     * slide is the deck's own text with one marker taken off each line — and a nested quote,
     * a list inside a quote and a sentence CommonMark reads as part of one are all the same
     * thing here: words, one level in.
     */
    private const QUOTE = '/\A {0,3}>[ \t]?(.*)\z/';

    /**
     * The steps one slide may build up in.
     *
     * 500 is two orders of magnitude above any build-up a person writes — a slide with five
     * hundred clicks on it is not a slide — and unlike the ceiling it replaces it costs the
     * renderer nothing to be generous with.
     */
    public const MAX_STEP = 500;

    private readonly EnvironmentInterface $environment;

    private readonly InlineTranslator $inline;

    public function __construct(private readonly ImageConverter $images = new ImageConverter())
    {
        $this->environment = SlideEnvironment::build();
        $this->inline = new InlineTranslator($this->environment);
    }

    /**
     * The inline translator this one parses its own runs with.
     *
     * Public because the renderer reads a title and a heading with the same translator the
     * blocks were read with. Two would be two definitions of what the text of a run of markup
     * is, and the comparison between a title and the heading that may be it would then be
     * between two parsers' opinions.
     */
    public function inline(): InlineTranslator
    {
        return $this->inline;
    }

    /**
     * The blocks of one slide's content, and the column each was written for.
     *
     * @throws ImageNotFound
     * @throws UnknownDirective
     * @throws UnknownMarker
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    public function parse(string $content, int $slide, string $directory, PathScope $files): SlideBlocks
    {
        $state = new SlideState($slide, $directory, $files, explode("\n", str_replace("\r\n", "\n", $content)));
        $blocks = [];
        $columns = [];

        foreach ($this->read($content, $state) as [$block, $column]) {
            $blocks[] = $block;
            $columns[] = $column;
        }

        return new SlideBlocks($blocks, $columns, $state->markers);
    }

    /**
     * One piece of Markdown read into blocks, with everything a refusal needs to name.
     *
     * Refused whole before anything is walked, so that a slide is judged as a whole and a
     * marker further down cannot decide which of two mistakes on one slide is reported.
     *
     * @return list<array{0: Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table, 1: int}>
     *
     * @throws ImageNotFound
     * @throws UnknownDirective
     * @throws UnknownMarker
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function read(string $content, SlideState $state): array
    {
        $document = (new MarkdownParser($this->environment))->parse($content);

        Refusals::guard($document, $state);

        return $this->walk($document, $state);
    }

    /**
     * @return list<array{0: Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table, 1: int}>
     *
     * @throws ImageNotFound
     * @throws UnknownDirective
     * @throws UnknownMarker
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function walk(Node $parent, SlideState $state): array
    {
        $written = [];

        foreach ($parent->children() as $node) {
            foreach ($this->block($node, $state) as $pair) {
                $written[] = $pair;
            }
        }

        return $written;
    }

    /**
     * The block a node is, and the column it was written for.
     *
     * A marker is not a block: it is where the next block begins, and a region is the blocks
     * between its fences rather than one of them.
     *
     * @return list<array{0: Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table, 1: int}>
     *
     * @throws ImageNotFound
     * @throws UnknownDirective
     * @throws UnknownMarker
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function block(Node $node, SlideState $state): array
    {
        // `match (true)` rather than an `if` per arm: the arms are tried in order, and a
        // marker is a block this walk asks about while everything after it is a block it
        // draws. An `if` per arm is the same dispatch with a return per arm on top.
        return match (true) {
            $node instanceof Marker => match ($node->kind()) {
                MarkerKind::Closer => throw UnknownMarker::strayCloser($state->slide),
                MarkerKind::Container => throw UnknownDirective::container($state->slide, Refusals::oneLine($node->written())),
                MarkerKind::Column => $this->column($node, $state),
                MarkerKind::Step, MarkerKind::Region => $this->step($node, $state),
            },
            $node instanceof LeagueHeading => $this->placed(new Heading($this->inline->runs($node), $node->getLevel(), $state->step), $state),
            $node instanceof ThematicBreak => $this->placed(new Rule($state->step), $state),
            $node instanceof FencedCode || $node instanceof IndentedCode => $this->placed($this->code($node, $state), $state),
            $node instanceof LeagueList => $this->placed(new ListBlock($this->items($node, 1, [], $state), $state->step), $state),
            $node instanceof BlockQuote => $this->quote($node, $state),
            $node instanceof LeagueTable => $this->placed($this->table($node, $state), $state),
            $node instanceof HtmlBlock => $this->placed($this->html($node, $state), $state),
            // No arm for a directive, and that is the invariant rather than an omission:
            // {@see Refusals::guard()} has refused every leaf whose name this library does not
            // draw, a container is a `MarkerKind::Container` in the first arm, and `::click` and
            // `::column` are markers too. A directive that got this far is a paragraph.
            default => $this->paragraph($node, $state),
        };
    }

    /**
     * A paragraph, or the one image a slide draws as a shape rather than as words.
     *
     * A picture is a picture only on a line of its own: inside a sentence it is text, and
     * every Markdown renderer draws a picture it cannot draw as its own source rather than
     * inventing a shape for it.
     *
     * @return list<array{0: Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table, 1: int}>
     *
     * @throws ImageNotFound
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function paragraph(Node $node, SlideState $state): array
    {
        $only = $node instanceof Paragraph && iterator_count($node->children()) === 1 ? $node->firstChild() : null;

        if ($only instanceof Image) {
            return $this->placed($this->image($only, $state), $state);
        }

        return $this->placed(new ParagraphBlock($this->inline->runs($node), $state->step), $state);
    }

    /**
     * The step a marker names, and the blocks a region holds.
     *
     * A region carries its column out of itself and not its step, which is what its opener
     * writes: everything inside it is on the step the opener named, and what follows the
     * closer is on that step too rather than on the last one the region reached.
     *
     * @return list<array{0: Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table, 1: int}>
     *
     * @throws ImageNotFound
     * @throws UnknownDirective
     * @throws UnknownMarker
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function step(Marker $node, SlideState $state): array
    {
        if (!$node->isClosed() && $node->kind() === MarkerKind::Region) {
            throw UnknownMarker::unclosed($state->slide, $node->written());
        }

        $opened = self::jump($state->slide, $node->argument(), $state->step, $node->written());
        $state->step = $opened;

        if ($node->kind() !== MarkerKind::Region) {
            return [];
        }

        $inside = $this->walk($node, $state);

        // A region keeps its own steps: what follows the closer is on the step the opener
        // named, not on the last one the region reached. Its column does carry out, because a
        // column is where a block is drawn and everything after the closer is drawn there.
        $state->step = $opened;

        return $inside;
    }

    /**
     * The column a `::column` marker moves to.
     *
     * No step moves with it, and the count moves with the column: a marker with nothing
     * after it leaves no block behind, and the renderer still has to know it was written.
     *
     * @return list<array{0: Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table, 1: int}>
     *
     * @throws UnknownMarker
     */
    private function column(Marker $node, SlideState $state): array
    {
        // Refused before anything is counted, so that `::column=2` never reaches the count:
        // it is not a marker, and a column cannot be named as one.
        if ($node->argument() !== null) {
            throw UnknownMarker::argument($state->slide, $node->written());
        }

        $state->column++;
        $state->markers++;

        return [];
    }

    /**
     * The step a marker names, refusing every way of getting that wrong.
     *
     * The same four refusals and through the same messages as the hand-written walk: a marker
     * with nothing after the equals, one whose argument is not a number, one past the ceiling
     * and one that goes backwards. The line is handed over rather than rebuilt from the
     * capture, because a message naming `::Click=2` for a deck that wrote `::Click=2` is a
     * message somebody can go and find.
     *
     * @param ?string $argument The argument as the deck wrote it, null for the bare form.
     *
     * @throws UnknownMarker
     */
    private static function jump(int $slide, ?string $argument, int $step, string $marker): int
    {
        if ($argument === null) {
            return $step + 1;
        }

        if ($argument === '') {
            throw UnknownMarker::empty($slide, $marker);
        }

        if (preg_match('/\A\d+\z/', $argument) !== 1) {
            throw UnknownMarker::notANumber($slide, $argument);
        }

        $number = (int) $argument;

        // Cast first and quoted second: a twenty-digit step saturates to `PHP_INT_MAX` rather
        // than overflowing, so the refusal names the twenty digits the deck wrote rather than
        // the number PHP could hold.
        if ($number > self::MAX_STEP) {
            throw UnknownMarker::tooLarge($slide, $argument, self::MAX_STEP);
        }

        if ($number <= $step) {
            throw UnknownMarker::backwards($slide, $number, $step);
        }

        return $number;
    }

    /**
     * A block of code, as the lines the deck wrote it in.
     *
     * CommonMark takes the indentation of the opening fence off every line of the body and the
     * trailing spaces off the end of each. That is right for a document and wrong for a slide,
     * where a code block is drawn as written and a line of it indented is a line the author
     * indented — so the lines are read back off the deck rather than off the node.
     *
     * A fence nothing closes runs to the end of the slide, and its last line is code whatever
     * it is written as, so whether that line is a closer is asked rather than tracked: a fence
     * that *was* closed by it and one that merely ends on those characters are answered by the
     * same question.
     *
     * An indented block is code here and a paragraph in the hand-written walk, which is one of
     * the recorded differences. It has no language, because a fence is what names one.
     */
    private function code(FencedCode|IndentedCode $node, SlideState $state): Code
    {
        if ($node instanceof IndentedCode) {
            return new Code(array_map(trim(...), $state->lines($node)), '', $state->step);
        }

        $lines = array_slice($state->lines($node), 1);
        $closing = '/\A {0,3}' . preg_quote($node->getChar(), '/') . '{' . $node->getLength() . ',}[ \t]*\z/';
        $last = end($lines);

        if ($last !== false && preg_match($closing, $last) === 1) {
            array_pop($lines);
        }

        return new Code($lines, $node->getInfoWords()[0] ?? '', $state->step);
    }

    /**
     * The items of a list and of every list inside it, at the depth each was nested at.
     *
     * The depth comes off the tree rather than off an indent, which is where a list written
     * `1. a` and continued at the item's own indent agrees with the hand-written walk, and
     * where a list that mixes its markers does not: CommonMark starts a second list there,
     * and that walk keeps one list with a bullet and a number in it.
     *
     * @param list<array{level: int, runs: list<Run>, ordered: bool, start: ?int}> $items
     *
     * @return list<array{level: int, runs: list<Run>, ordered: bool, start: ?int}>
     *
     * @throws UnsupportedLink
     */
    private function items(LeagueList $node, int $level, array $items, SlideState $state): array
    {
        $data = $node->getListData();
        $ordered = $data->type === LeagueList::TYPE_ORDERED;

        // A numbering that begins at one needs nothing: no `startAt` is written for it at all,
        // and every reader counts on from one. One that begins at any other number has the same
        // `startAt` written on each of its paragraphs — which is what PowerPoint expects, and
        // continues itself — and LibreOffice restarts at each one instead, so `7.` and `8.` read
        // as `7.` and `7.` there. Every item of such a list therefore carries its own number,
        // and nothing is left to a reader to infer.
        $explicit = $ordered && $data->start !== 1;
        $number = $data->start;

        foreach ($node->children() as $item) {
            $items[] = [
                'level' => $level,
                'runs' => $this->itemRuns($item, $state),
                'ordered' => $ordered,
                // The number the list was opened with, on its first item alone: an item that
                // carries on from the one above it has no number of its own to draw.
                'start' => $ordered ? ($explicit ? $number : ($items === [] ? $data->start : null)) : null,
            ];

            $number++;

            foreach ($item->children() as $child) {
                if ($child instanceof LeagueList) {
                    $items = $this->items($child, $level + 1, $items, $state);
                }
            }
        }

        return $items;
    }

    /**
     * The words of one list item, without the lists nested inside it.
     *
     * Read before them rather than after, because a bullet is drawn before the bullets
     * under it: the deck writes the parent first and the tree holds its children first, and
     * a slide that showed them the other way round would read bottom up.
     *
     * @return list<Run>
     *
     * @throws UnsupportedLink
     */
    private function itemRuns(Node $item, SlideState $state): array
    {
        $runs = [];

        foreach ($item->children() as $child) {
            if ($child instanceof LeagueList) {
                continue;
            }

            if ($child instanceof Paragraph || $child instanceof LeagueTable) {
                array_push($runs, ...$this->inline->runs($child, ' '));

                continue;
            }

            // A shape written inside a bullet — a fence, a block of HTML — is not one
            // a slide draws there, so it is read as the words it carries. Dropping it would
            // drop the words the author wrote, which is the one outcome neither parser is
            // allowed to offer.
            $lines = implode("\n", array_map(trim(...), $state->lines($child)));

            array_push($runs, ...$this->inline->runsIn($lines, ' '));
        }

        return $runs;
    }

    /**
     * A quoted block, as the sentences it carries.
     *
     * Read from the deck's own lines, one `>` at a time, because that is what a quote is on a
     * slide. A line that is not marked ends it — CommonMark's rule and this one's — and
     * whatever followed it is content again rather than part of the quotation.
     *
     * @return list<array{0: Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table, 1: int}>
     *
     * @throws ImageNotFound
     * @throws UnknownDirective
     * @throws UnknownMarker
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function quote(BlockQuote $node, SlideState $state): array
    {
        $lines = $state->lines($node);
        $quoted = [];
        $offset = 0;

        while (isset($lines[$offset]) && preg_match(self::QUOTE, $lines[$offset], $match) === 1) {
            $quoted[] = $match[1];
            $offset++;
        }

        $blocks = $this->placed(new Quote($this->inline->runsIn(implode("\n", $quoted)), $state->step), $state);

        return $offset === count($lines)
            ? $blocks
            : [...$blocks, ...$this->read(implode("\n", array_slice($lines, $offset)), $state)];
    }

    /**
     * The pipe table, as its rows of runs and its columns' alignment.
     *
     * The alignment is read off the header row's cells, which is where the extension keeps
     * it: a column with no colons in its `---` is a column with no alignment written, and a
     * table on a slide is left-aligned unless the deck said otherwise.
     */
    private function table(LeagueTable $node, SlideState $state): Table
    {
        $rows = [];
        $align = [];

        foreach ($node->children() as $section) {
            $header = $section instanceof TableSection && $section->isHead();

            foreach ($section->children() as $row) {
                [$cells, $columns] = $this->row($row, $header);

                $rows[] = $cells;
                array_push($align, ...$columns);
            }
        }

        return new Table($rows, $align, $state->step);
    }

    /**
     * One row of a table: the runs of its cells, and the alignment of its columns where it is
     * the header row. A body row's second half is empty, because how a column lines up is
     * written on the header row and nowhere else.
     *
     * @return array{0: list<list<Run>>, 1: list<string>}
     */
    private function row(Node $row, bool $header): array
    {
        $cells = [];
        $align = [];

        foreach ($row->children() as $cell) {
            $cells[] = $this->inline->runs($cell);

            if ($header) {
                $align[] = $cell instanceof TableCell && $cell->getAlign() !== null ? $cell->getAlign() : 'left';
            }
        }

        return [$cells, $align];
    }

    /**
     * An HTML block, as the words it carries.
     *
     * A tag this library does not act on is text, a tag it refuses is a failure, and either
     * way it is not a shape: a slide draws eight kinds of block and an element is not one of
     * them. So the block is read as the lines the deck wrote, each without the indentation
     * that made them one block.
     */
    private function html(HtmlBlock $node, SlideState $state): ParagraphBlock
    {
        $lines = implode("\n", array_map(trim(...), $state->lines($node)));

        return new ParagraphBlock($this->inline->runsIn($lines), $state->step);
    }

    /**
     * The picture on a line of its own, as the shape it is drawn as.
     *
     * The alt text is the label's words with its markup taken out, which is what a screen
     * reader reads. The hand-written walk takes the label as the raw text between the brackets,
     * so a `![*a picture*](x.png)` is described as `a picture` here and as `*a picture*` there.
     *
     * @throws ImageNotFound
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function image(Image $node, SlideState $state): ImageBlock
    {
        // Decoded, because that is how a name with a space in it is written: CommonMark has no
        // bare space in a destination, so `my picture.png` is a paragraph and `my%20picture.png`
        // is the picture. league hands the target over encoded, and looking for the encoded
        // spelling on disk is looking for a file nobody has.
        $written = rawurldecode($node->getUrl());

        if (PathScope::isUrl($written)) {
            throw ImageNotFound::aUrl($state->slide, $written);
        }

        // Where a path is written *from* says nothing about whether it is allowed: what is
        // allowed is where it lands.
        $joined = str_starts_with($written, '/') ? $written : rtrim($state->directory, '/') . '/' . $written;

        if (!is_file($joined)) {
            throw ImageNotFound::at($state->slide, $written, $joined);
        }

        // `realpath()` rather than the path as written, because `..` and a symlink both walk
        // out of a directory that a prefix test says they are inside.
        $resolved = PathScope::canonical($joined);

        if ($resolved === null || !$state->files->holds($resolved)) {
            throw ImageNotFound::outside($state->slide, $written, $resolved ?? $joined, $state->files->roots());
        }

        return new ImageBlock(
            $written,
            $resolved,
            $this->images->png($state->slide, $written, $resolved),
            InlineTranslator::text($this->inline->runs($node)),
            $state->step,
        );
    }

    /**
     * A block written in the column the walk has reached.
     *
     * @param Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table $block
     *
     * @return list<array{0: Code|Heading|ImageBlock|ListBlock|ParagraphBlock|Quote|Rule|Table, 1: int}>
     */
    private function placed(Block $block, SlideState $state): array
    {
        return [[$block, $state->column]];
    }
}
