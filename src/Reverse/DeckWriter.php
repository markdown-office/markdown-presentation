<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use MarkdownPresentation\Exception\UnreadableDeck;
use PhpOffice\PhpPresentation\DocumentProperties;

/**
 * The blocks of a deck, written out as the Markdown a deck is written in.
 *
 * The format is the one {@see \MarkdownPresentation\Document\DeckParser} reads:
 * slides separated by `---`, a title as the slide's first heading, a note in a
 * `::note` block, and the deck's own properties in a frontmatter block at the
 * top — which is the first slide's frontmatter, because that is where the
 * parser reads a deck's from.
 */
final class DeckWriter
{
    /**
     * The spaces a nested list item is indented by.
     *
     * Four, and not two, because a numbered item's content starts three
     * characters in and a nested list has to clear that: an item indented by
     * two would end its parent rather than sit inside it. Four is also short
     * of the seven that would make it a code block.
     */
    private const INDENT = '    ';

    /**
     * The title PHPPresentation gives a deck that carries none.
     *
     * It is the library's own default rather than a title somebody wrote, so a
     * deck that carries it says nothing about its title.
     */
    private const DEFAULT_TITLE = 'Untitled Presentation';

    public function __construct(private readonly Options $options)
    {
    }

    /**
     * The deck, as Markdown.
     *
     * @param list<Slide> $slides
     * @param string|null  $theme The name of the theme the deck was drawn in, when it is one
     *                            a deck can name again.
     */
    public function write(array $slides, DocumentProperties $properties, ?string $theme = null): string
    {
        $out = [];

        foreach ($slides as $index => $slide) {
            $body = $this->slide($slide);

            if ($index === 0) {
                // The deck's own frontmatter is the first slide's, so the first slide's own
                // settings are written into it rather than beside it.
                $frontmatter = $this->frontmatter($properties, $theme, $slide->frontmatter, $slide->title);

                if ($frontmatter !== '') {
                    $body = $frontmatter . "\n" . $body;
                }
            } else {
                $body = $this->settings($slide->frontmatter) . $body;
            }

            $out[] = $body;
        }

        // A slide that carries a frontmatter opens with the delimiter: the block after it is
        // the slide's, and a blank line after it would put the block out of the slide's reach.
        // The settings of a slide supply the rest of the block — see {@see self::settings()}.
        $markdown = implode("\n---\n", $out);

        if ($this->options->lineEnding === "\n") {
            return $markdown . "\n";
        }

        return str_replace("\n", $this->options->lineEnding, $markdown) . $this->options->lineEnding;
    }

    /**
     * The settings a slide carries back, as the block that names them.
     *
     * The block is closed with a delimiter of its own and opened by the one that separates
     * the slides: a slide's settings are a frontmatter block, a block that is not closed is
     * not read, and a delimiter with a blank line after it opens nothing — so the two are one
     * arrangement, and the separator in {@see self::write()} must stay as it is.
     *
     * @param array<string, string|int|bool> $settings
     */
    private function settings(array $settings): string
    {
        if ($settings === []) {
            return "\n";
        }

        $lines = [];

        foreach ($settings as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, $this->value($value));
        }

        return implode("\n", $lines) . "\n---\n\n";
    }

    /**
     * The deck's own properties, as a frontmatter block.
     *
     * The theme is one of them: a deck read back into Markdown and drawn again comes back in
     * the theme it was drawn in rather than in the library's own, and the design of a deck is
     * most of what a deck is.
     *
     * The title is the deck's own where it has one, and the first slide's where it has not:
     * the frontmatter is the first slide's, and a `title:` in it is that slide's title, so a
     * deck whose title is on its slide and nowhere else would lose it.
     *
     * @param string|null $theme The name of the theme the deck was drawn in, when it is one
     *                           a deck can name again.
     * @param array<string, string|int|bool> $own The first slide's own settings, which are
     *                           written into the deck's own block with them.
     * @param string|null $slideTitle The title of the first slide, which stands in for the
     *                           deck's own title where the properties carry none.
     */
    private function frontmatter(
        DocumentProperties $properties,
        ?string $theme = null,
        array $own = [],
        ?string $slideTitle = null,
    ): string {
        $values = [
            'title' => $this->title($properties, $slideTitle),
            'theme' => $theme,
            'author' => $properties->getCreator(),
            'description' => $properties->getDescription(),
            ...$own,
        ];

        $lines = [];

        foreach ($values as $key => $value) {
            // A theme the deck does not name is not written: the line would say `theme:` and
            // name nothing, which is a theme of the library's own rather than of the deck's.
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    continue;
                }
            }

            $lines[] = sprintf('%s: %s', $key, $this->value($value));
        }

        if ($lines === []) {
            return '';
        }

        return "---\n" . implode("\n", $lines) . "\n---\n";
    }

    /**
     * The title the deck's frontmatter carries, or null where it carries none.
     *
     * The properties' title is the deck's own, unless it is {@see self::DEFAULT_TITLE},
     * which says nothing about a deck that carries it.
     *
     * The first slide's title stands in for it then, because the frontmatter is the first
     * slide's and a `title:` in it is that slide's title: a deck whose title is on its slide
     * and nowhere else would lose it otherwise.
     */
    private function title(DocumentProperties $properties, ?string $slideTitle): ?string
    {
        $title = $properties->getTitle();

        return $title === self::DEFAULT_TITLE ? $slideTitle : $title;
    }

    /**
     * A YAML scalar, quoted so that a colon or a hash in the value is not read
     * as syntax.
     *
     * A number and a flag are not quoted: a quoted number is a string, and the value a
     * slide's own settings hold is read as the number and the flag it is.
     */
    private function value(string|int|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return $this->quote($value);
    }

    /**
     * A YAML scalar, quoted so that a colon or a hash in the value is not read
     * as syntax.
     */
    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * One slide, as Markdown.
     *
     * The slide's parts are joined by a blank line rather than each carrying
     * one, so that a slide with nothing in it but a note does not begin with
     * an empty line.
     */
    private function slide(Slide $slide): string
    {
        $sections = [];

        if ($slide->title !== null && $slide->title !== '') {
            $sections[] = '# ' . Escaping::text($slide->title, lineStart: true);
        }

        foreach ($slide->blocks as $block) {
            $lines = $this->block($block);

            if ($lines !== []) {
                $sections[] = implode("\n", $lines);
            }
        }

        if ($slide->note !== null && $slide->note !== '') {
            $sections[] = "::note\n" . $slide->note . "\n::";
        }

        return implode("\n\n", $sections);
    }

    /**
     * A block, as the lines it takes up.
     *
     * @return list<string>
     */
    private function block(Block $block): array
    {
        return match ($block->kind) {
            Block::PARAGRAPH => $this->paragraph($block),
            Block::LIST => $this->list($block),
            Block::TABLE => $this->table($block),
            Block::IMAGE => $this->image($block),
            Block::SHAPE => $this->shape($block),
            default => [],
        };
    }

    /**
     * A free shape, as the directive that draws it.
     *
     * A free text block is a fence rather than a line: its body goes between the fences and
     * the closing fence is on a line of its own.
     *
     * @return list<string>
     */
    private function shape(Block $block): array
    {
        $shape = $block->shape;

        if (!$shape instanceof Shape) {
            return [];
        }

        $attributes = [];

        foreach ($shape->attributes as $name => $value) {
            $attributes[] = sprintf('%s=%s', $name, $value);
        }

        // A free text block is a fence of three colons, because it holds a body rather than
        // being one line.
        $colons = $shape->hasBody() ? ':::' : '::';
        $written = sprintf('%s%s{%s}', $colons, $shape->directive, implode(' ', $attributes));

        if (!$shape->hasBody()) {
            return [$written];
        }

        $body = [];

        foreach ($shape->body as $block) {
            $body = [...$body, ...$this->block($block)];
        }

        return [sprintf("%s\n%s\n:::", $written, implode("\n", $body))];
    }

    /**
     * @return list<string>
     */
    private function paragraph(Block $block): array
    {
        $text = $this->inlines($block->inlines);

        if ($text === '') {
            return [];
        }

        return [$text];
    }

    /**
     * @return list<string>
     */
    private function list(Block $block, int $depth = 0): array
    {
        $lines = [];
        $number = 0;

        // The writer is public API: a caller can hand it a tree the reader
        // never saw, so the ceiling is checked here as well.
        if ($depth > Options::MAX_NESTING) {
            throw UnreadableDeck::nestedTooDeep(Options::MAX_NESTING);
        }

        foreach ($block->children as $item) {
            $number++;
            $marker = $block->ordered ? $number . '. ' : '- ';
            $lines = [...$lines, ...$this->item($item, $marker, $depth)];
        }

        return $lines;
    }

    /**
     * One item of a list, and the list nested under it.
     *
     * @return list<string>
     */
    private function item(Block $item, string $marker, int $depth): array
    {
        $lines = [];
        $blocks = $item->children;
        $first = true;

        foreach ($blocks as $block) {
            if ($block->kind === Block::LIST) {
                continue;
            }

            $text = $this->inlines($block->inlines);

            if ($text === '') {
                continue;
            }

            $lines[] = str_repeat(self::INDENT, $depth) . ($first ? $marker : str_repeat(' ', strlen($marker))) . $text;
            $first = false;
        }

        foreach ($blocks as $block) {
            if ($block->kind !== Block::LIST) {
                continue;
            }

            $lines = [...$lines, ...$this->list($block, $depth + 1)];
        }

        return $lines;
    }

    /**
     * A table, as a Markdown table.
     *
     * The first row is the header, because a Markdown table has to have one and
     * a `.pptx` table does not say which row it is. The header is the row a
     * reader would read as one.
     *
     * @return list<string>
     */
    private function table(Block $block): array
    {
        $rows = $block->children;

        if ($rows === []) {
            return [];
        }

        $lines = [];
        $width = 0;

        foreach ($rows as $row) {
            $cells = [];

            foreach ($row->children as $cell) {
                $cells[] = $this->cell($cell);
                $width = max($width, count($cells));
            }

            $lines[] = '| ' . implode(' | ', array_pad($cells, $width, '')) . ' |';
        }

        // The delimiter row goes under the header, which is the first row.
        array_splice($lines, 1, 0, ['| ' . implode(' | ', array_fill(0, $width, '---')) . ' |']);

        return $lines;
    }

    /**
     * A cell of a table, as the text of one.
     *
     * A cell holds whatever was written into it, which can be more than one
     * block: a bulleted paragraph is a list rather than a paragraph, and a
     * list has no inline content of its own.
     *
     * A Markdown table cell holds one line of text, so the blocks of a cell are
     * joined with a space: a Markdown table cannot hold a paragraph break, and
     * pretending otherwise would produce a table that does not parse.
     */
    private function cell(Block $cell): string
    {
        $parts = [];

        foreach ($cell->children as $block) {
            $lines = $this->block($block);

            if ($lines !== []) {
                $parts[] = implode(' ', $lines);
            }
        }

        return str_replace("\n", ' ', implode(' ', $parts));
    }

    /**
     * A picture, as an image reference.
     *
     * The reference is the path the picture was written to, so that the deck
     * and the file agree about where it is.
     *
     * @return list<string>
     */
    private function image(Block $block): array
    {
        $image = $block->image;

        if ($image === null || $image->target === '' || $this->options->mediaDirectory === null) {
            return [];
        }

        return [sprintf(
            '![%s](%s)',
            Escaping::label($image->alt),
            Escaping::address($this->options->mediaDirectory . '/' . $image->target),
        )];
    }

    /**
     * The inline runs of a paragraph, as Markdown.
     *
     * @param list<Inline> $inlines
     */
    private function inlines(array $inlines, bool $inTable = false): string
    {
        $out = '';
        $lineStart = true;

        foreach ($inlines as $inline) {
            if ($inline->kind === Inline::BREAK) {
                $out .= "\\\n";
                $lineStart = true;

                continue;
            }

            $text = $this->text($inline->text, $lineStart, $inTable);
            $lineStart = false;

            if ($inline->kind === Inline::LINK) {
                $out .= '[' . $this->inlines($inline->children, $inTable) . '](' . Escaping::address($inline->url) . ')';

                continue;
            }

            $out .= $this->wrap($text, $inline);
        }

        return $out;
    }

    /**
     * The characters of a run, wrapped in the Markdown that says how they are
     * set.
     */
    private function wrap(string $text, Inline $inline): string
    {
        if ($inline->strike) {
            $text = '~~' . $text . '~~';
        }

        if ($inline->italic) {
            $text = '*' . $text . '*';
        }

        if ($inline->bold) {
            $text = '**' . $text . '**';
        }

        return $text;
    }

    /**
     * The characters of a run, escaped.
     *
     * A run may hold more than one line — a body placeholder whose text was
     * written in one go holds all of it in one run — and each of those lines
     * begins a line of Markdown, so each of them is escaped as one.
     */
    private function text(string $text, bool $lineStart, bool $inTable): string
    {
        $out = '';

        foreach (explode("\n", $text) as $index => $line) {
            if ($index > 0) {
                $out .= "\n";
            }

            $out .= Escaping::text($line, lineStart: $lineStart || $index > 0, inTable: $inTable);
        }

        return $out;
    }
}
