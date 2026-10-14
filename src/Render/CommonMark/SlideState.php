<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Node;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\Free\FreeShape;
use MarkdownPresentation\Theme\Theme;

/**
 * Where the walk has got to on one slide, and what it is walking.
 *
 * A block carries the step it appears on, and a column is where a block is drawn rather than
 * anything about the block, so the walk holds both as it goes and hands each block the pair
 * as it writes it. A free shape carries a step and no column, for the same reason, and goes on
 * the state beside them: it is placed rather than laid out, so nothing about where the layout
 * would have put it applies to it and a column would only be a thing to keep it out of.
 *
 * The theme is here for the same reason the directory and the file scope are: resolving
 * `fill=panel` needs the theme the deck named, and a directive naming an attribute nothing
 * reads has to be refused before anything is built rather than after a shape exists to refuse
 * it on.
 *
 * The lines the deck was written as are held too. CommonMark normalises the text it parses —
 * a code block loses the indentation of its fence, a paragraph loses the trailing space of
 * each line — and a slide is drawn as its author wrote it, so the lines are what a block
 * that is *the deck's own text* is read from.
 */
final class SlideState
{
    /** The step a slide with no marker in it is drawn at, which is every step's starting point. */
    public int $step = 1;

    /** The column a slide with no marker in it is written for, which is the first of them. */
    public int $column = 0;

    /** How many `::column` markers the slide has written, whether or not a block follows it. */
    public int $markers = 0;

    /**
     * The shapes the slide placed itself, in the order the deck wrote them.
     *
     * @var list<FreeShape>
     */
    private array $shapes = [];

        /**
     * @param list<string> $lines The slide's content, one entry per line.
     */
    public function __construct(
        public readonly int $slide,
        public readonly string $directory,
        public readonly PathScope $files,
        private readonly array $lines,
        public readonly Theme $theme,
    ) {
    }

    /**
     * A shape the deck placed itself, which the layout has nothing to do with.
     *
     * Here rather than handed back up the walk because it is not a block: {@see
     * \MarkdownPresentation\Render\SlideBlocks} carries it in a list of its own and {@see
     * \MarkdownPresentation\Render\PresentationRenderer} writes it beside the layout's shapes,
     * and neither of those is a decision the walk can make — a walk that returned shapes would
     * have to return them as something, and there is no block here.
     */
    public function place(FreeShape $shape): void
    {
        $this->shapes[] = $shape;
    }

    /**
     * @return list<FreeShape>
     */
    public function shapes(): array
    {
        return $this->shapes;
    }

    /**
     * The lines a node covers, as the deck wrote them.
     *
     * League counts lines from one and includes the fence a fenced block opens and closes
     * with, so this is the node's own lines rather than the lines between them;
     * {@see \MarkdownPresentation\Render\CommonMark\BlockTranslator} is what takes the fences
     * off.
     *
     * Empty for a node the parser built for itself rather than out of the deck — a table's
     * rows, a link's label — because there are no lines of the deck behind one.
     *
     * @return list<string>
     */
    public function lines(Node $node): array
    {
        $start = $node instanceof AbstractBlock ? $node->getStartLine() : null;
        $end = $node instanceof AbstractBlock ? $node->getEndLine() : null;

        return $start === null || $end === null
            ? []
            : array_slice($this->lines, $start - 1, $end - $start + 1);
    }

    /**
     * The line a node starts on, which is what a refusal about it quotes.
     *
     * `array_slice()` rather than an index, because a node the parser built for itself has no
     * lines and an index that is not there is a warning rather than a sentence.
     */
    public function written(Node $node): string
    {
        return implode('', array_slice($this->lines($node), 0, 1));
    }
}
