<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnsupportedColumn;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Render\Free\FreeShape;
use MarkdownPresentation\Render\Free\FreeText;
use MarkdownPresentation\Theme\LayoutRole;
use PhpOffice\PhpPresentation\Slide as WrittenSlide;

/**
 * Where a slide is being written, and by whom.
 *
 * The three things every draw on one slide needs and none of them vary within it. They are an
 * object rather than three parameters on every method here, which is what kept
 * {@see SlideShapes::column()} over the parameter ceiling for a week before it was noticed.
 *
 * @internal
 */
final class Writes
{
    public function __construct(
        public readonly WrittenSlide $slide,
        public readonly ShapeWriter $writer,
        public readonly int $number,
    ) {
    }
}

/**
 * Every shape on one slide, in the order they go down the screen, and the step each is on.
 *
 * Out of {@see PresentationRenderer} because it is one idea with three passes in it, and a method
 * carrying all three is one the renderer no longer reads: the shapes a deck placed behind the
 * layout's own, the layout's own, and the shapes it placed over them. Two of those are layout
 * facts — where a role's columns are and which blocks a column holds — and the third is a fact
 * about a shape and its box.
 *
 * The order *is* `layer`: a later shape in `p:spTree` is drawn over an earlier one, so a panel
 * behind a slide's text has to be written before the text and nothing else will put it there.
 * That is why this is one class rather than three methods on the renderer: splitting it would
 * hide the only reason the passes are in that order.
 */
final class SlideShapes
{
    public function __construct(
        private readonly SlideGeometry $geometry,
        private readonly Overflows $overflows,
    ) {
    }

    /**
     * The title box of a role, and the size its type is drawn at, which is the only one this
     * class does not place itself.
     *
     * Asked of the geometry through here rather than the renderer reaching into it, because a
     * caller should not have to carry a geometry *and* the thing that uses it — and because
     * `$level` is a question about this box: it is the frontmatter's `level:` read as a title
     * size rather than as a heading.
     *
     * @param ?int $level The depth `level:` named, or null where it named none.
     *
     * @return ?array{box: Box, size: int, alignment: string}
     */
    public function title(LayoutRole $role, ?int $level): ?array
    {
        $title = $this->geometry->title($role);

        if ($title === null || $level === null) {
            return $title;
        }

        $title['size'] = $level <= 1 ? $title['size'] : $this->geometry->headingSize($level - 1);

        return $title;
    }

    /**
     * @param int $first The id the first shape takes, which is one past the title where there is
     *                    one — see {@see PresentationRenderer::render()} for where that comes
     *                    from.
     *
     * @return array<int, int> Shape id to the step it appears on, in write order.
     *
     * @throws UnsupportedColumn
     */
    public function write(
        WrittenSlide $slide,
        SlideBlocks $content,
        LayoutRole $role,
        ShapeWriter $writer,
        int $number,
        int $first,
    ): array {
        $steps = [];
        $writes = new Writes($slide, $writer, $number);
        $next = $first;

        foreach ($content->shapes() as $shape) {
            if ($shape->isBack()) {
                $steps[$next++] = $this->placed($shape, $writes);
            }
        }

        $next = $this->stacked($content, $role, $writes, $steps, $next);

        foreach ($content->shapes() as $shape) {
            if (!$shape->isBack()) {
                $steps[$next++] = $this->placed($shape, $writes);
            }
        }

        return $steps;
    }

    /**
     * The layout's own shapes, stacked into the role's body boxes.
     *
     * Stacked as shapes rather than as blocks: {@see TextFrame::group()} decides which blocks
     * share one text frame first, because the air between two of them is then the frame's own
     * paragraph spacing rather than room taken out of the column between two boxes.
     *
     * `$steps` is added to rather than returned, because the ids of the two placed layers run
     * straight through it and returning a partial map to be merged would be the same fact twice.
     *
     * @param array<int, int> $steps Grown by this pass.
     *
     * @return int The id the next shape takes.
     *
     * @throws UnsupportedColumn
     */
    private function stacked(
        SlideBlocks $content,
        LayoutRole $role,
        Writes $writes,
        array &$steps,
        int $first,
    ): int {
        $blocks = $content->blocks();
        $columns = $this->columns($content, $role, $writes->number);
        $next = $first;

        foreach ($this->geometry->body($role) as $column => $box) {
            $next = $this->column($column, $box, $blocks, $columns, $writes, $steps, $next);
        }

        return $next;
    }

    /**
     * One column's blocks, stacked into its box and written.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     * @param list<int>                                                       $columns
     * @param array<int, int>                                                 $steps Grown by this column.
     *
     * @return int The id the next shape takes.
     */
    private function column(
        int $column,
        Box $box,
        array $blocks,
        array $columns,
        Writes $writes,
        array &$steps,
        int $first,
    ): int {
        $y = $box->y;
        $bottom = $box->y + $box->height;
        $drawables = TextFrame::group($this->visible($blocks, $columns, $column));
        $last = count($drawables) - 1;
        $next = $first;

        foreach ($drawables as $at => $drawable) {
            // A drawable that is a block is its own step; a {@see TextFrame} is several blocks
            // drawn as one shape, and every block in a frame is on the same step —
            // {@see TextFrame::group()} ends a run at a step boundary for exactly that reason —
            // so the frame's step is its first block's.
            $step = $drawable instanceof TextFrame ? $drawable->firstStep() : $drawable->step();

            // A column whose contents are taller than its box runs off the bottom of it, and an
            // image spends the whole of the room left — so both the cursor and the height are
            // held inside the box. A shape that does not fit is drawn as small as the room
            // allows rather than over whatever is under it.
            $y = min($y, $bottom - SlideGeometry::MIN_SIZE);
            $room = max(0, $bottom - $y);
            $wanted = $writes->writer->height($drawable, $box, $room);
            $height = max(min($wanted, $room), SlideGeometry::MIN_SIZE);

            if ($height < $wanted) {
                $this->overflows->record($writes->number, $step, $column, $drawable->name(), $wanted, $room);
            }

            $writes->writer->write(
                $writes->slide,
                $drawable,
                new Box($box->x, $y, $box->width, $height),
                $height,
            );
            $steps[$next++] = $step;

            // The air between two shapes is the air between the two blocks at the seam, which is
            // the pair {@see TextFrame::group()} put next to one another — so a table under a
            // paragraph gets the room a table wants whatever the frame around either of them.
            // Nothing is added after the last one: room below the last shape of a column is the
            // column's, not the layout's, and spending it there pushes a slide off its bottom.
            if ($at < $last) {
                $y += $height + (int) round($this->geometry->gapBetween($drawable, $drawables[$at + 1]));
            }
        }

        return $next;
    }

    /**
     * One placed shape, at the height it asked for or as small as its box allows.
     *
     * Only a text block is measured: the other two are exactly the box the deck gave them, and a
     * picture's own ratio is what {@see ShapeWriter} fits it to.
     */
    private function placed(FreeShape $shape, Writes $writes): int
    {
        $box = $shape->placement->box;
        $height = $box->height;

        if ($shape instanceof FreeText) {
            // The padding is passed because the height is measured in the width the text is
            // drawn in, and {@see \MarkdownPresentation\Render\ShapeWriter::freeText()} is what
            // writes that inset. Measuring against the default instead is what made a wide
            // `padding` report a block as fitting when PowerPoint wraps it past its box.
            // The face is passed for the same reason: a box with a `font-family` of its own is
            // drawn in it, and a box measured in another face is a box measured for type it
            // does not draw.
            $wanted = $writes->writer->sized(
                $shape->frame,
                $box,
                $box->height,
                $shape->style->size,
                $shape->style->padding,
                $shape->style->face,
            );

            if ($wanted > $height) {
                $this->overflows->record($writes->number, $shape->step, null, $shape->name(), $wanted, $height);
            }

            // The declared height, not the measured one. Shrinking the box to its text made
            // `vertical-align` meaningless: `height=48 vertical-align=middle` was written at
            // the 23px the text needed, anchored centre in 23px sitting at the top of a 48px
            // space — so the text rode high by (48 − 23) / 2, and the box the deck named was
            // not the box in the file. A deck places a box and aligns text inside it, so the
            // box is the one it declared and only an overflow may take the room away.
            $height = $wanted > $height ? max($height, SlideGeometry::MIN_SIZE) : $box->height;
        }

        $writes->writer->free($writes->slide, $shape, $box, $height);

        return $shape->step;
    }

    /**
     * Which column each block belongs to, once the slide's layout has agreed to them.
     *
     * What the deck wrote, read off the parser rather than guessed here: a `::column` moves the
     * blocks after it into the next column and nothing else does, so a heading is a heading and
     * a slide that writes no `::column` fills the first column and leaves the rest empty.
     *
     * Measured on the markers rather than on the column a block landed in, because a marker
     * with nothing after it leaves no block behind, and a rule that turned on what follows the
     * last marker of a slide would be a rule a deck cannot see. N markers ask for the columns
     * zero through N, so a role has to have more than N of them — which covers the one marker on
     * a role with one column, the third on a slide with two, and either of them written at the
     * end of a slide where nothing follows.
     *
     * @return list<int>
     *
     * @throws UnsupportedColumn
     */
    private function columns(SlideBlocks $content, LayoutRole $role, int $slide): array
    {
        $available = count($this->geometry->body($role));

        if ($content->columnMarkers() >= $available) {
            throw UnsupportedColumn::beyond($slide, $role->value, $available);
        }

        return $content->columns();
    }

    /**
     * The blocks of one column, in order.
     *
     * Every one of them, whatever step it is on: a build-up is one slide holding every block,
     * and which of them a reader can see is what the click chain says rather than which of them
     * were written.
     *
     * @param list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table> $blocks
     * @param list<int>                                                       $columns Which column each block belongs to.
     *
     * @return list<Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table>
     */
    private function visible(array $blocks, array $columns, int $column): array
    {
        $visible = [];

        foreach ($blocks as $offset => $block) {
            if ($columns[$offset] === $column) {
                $visible[] = $block;
            }
        }

        return $visible;
    }
}
