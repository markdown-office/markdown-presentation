<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Render\Block\Block;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Render\Free\FreeAutoShape;
use MarkdownPresentation\Render\Free\FreePicture;
use MarkdownPresentation\Render\Free\FreeShape;
use MarkdownPresentation\Render\Free\FreeText;
use MarkdownPresentation\Render\Free\Pictures;
use MarkdownPresentation\Render\Free\ShapeKind;
use MarkdownPresentation\Render\Free\TextStyle;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\AutoShape;
use PhpOffice\PhpPresentation\Shape\AbstractGraphic;
use PhpOffice\PhpPresentation\Shape\Drawing\AbstractDrawingAdapter;
use PhpOffice\PhpPresentation\Shape\Drawing\Base64;
use PhpOffice\PhpPresentation\Shape\Drawing\File;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\RichText\Paragraph as TextParagraph;
use PhpOffice\PhpPresentation\Shape\Table as TableShape;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Border;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\Font as StyleFont;
use SplObjectStorage;

/**
 * Turns a block into a shape, and says how tall it has to be before it does.
 *
 * Measure and draw are the same object's two methods on purpose: a height computed by one
 * pass and a shape drawn by another drift apart the first time somebody edits one of them,
 * and the drift shows up as a gap rather than as a failure.
 *
 * That is also the answer to the obvious question about a class this size, and it is worth
 * having written down: this is not split, and `php:S1448` is open on it on purpose. The
 * crossings that make it one thing are visible in the calls rather than in the headings —
 * `columns()` and `rowHeights()` are each read by the measure half *and* the draw half, and
 * `HEADER_ROW` by both halves of the table, so a column width or a row height decided once in
 * each place is a table whose drawn cells do not match the room they were measured for.
 * `bodySize()` is the same crossing for text: the height of a frame, the line count of a
 * paragraph and the runs both of them draw all read it.
 *
 * The placed shapes this class also draws cross three more, and they are the reason it was
 * not the obvious place to put them instead. `filled()` is the one place a fill becomes
 * `FILL_SOLID` or `FILL_NONE`, read by an autoshape and by a placed text box alike, because
 * `Fill::FILL_UNSET` means *inherit* and a shape on a blank layout has nothing to inherit
 * from. `runs()` writes every run's size, face and colour, and `boxed()` fills in the ones a
 * run says nothing about, so the two have to agree on where a run's type lives.
 * `runFace()` is the same crossing for the face: the run that is measured and the run that
 * is drawn are asked the same question, so a word cannot be measured in one face and drawn
 * in another.
 * `textHeight()` measures a frame once for a column and again through `sized()` for a text
 * block the deck sized itself, and `freeText()` draws what the second measured.
 *
 * Each of those is a place a split would have to cut, and each is a place where the seam
 * runs through a fact two methods must agree on.
 *
 * Every shape is named after the kind of thing it was drawn from, so the whole mapping can be
 * read off `ppt/slides/slideN.xml` without opening the library. A {@see TextFrame} is the one
 * shape drawn from several blocks at once, and it is named for what it holds rather than for
 * the first of them.
 *
 * @phpstan-import-type Piece from TextMetric
 * @phpstan-import-type FrameParagraph from TextFrame
 */
final class ShapeWriter
{
    /** Which row of a table is its header, counting from the first. */
    private const HEADER_ROW = 0;

    /**
     * The drawing each picture of a shape is written as.
     *
     * A picture in the chrome of a template is the same shape on every slide that names the
     * template, and a picture in a package is one media part: the drawing is made once and
     * every slide that draws the picture points at it.
     *
     * @var SplObjectStorage<FreePicture, AbstractDrawingAdapter>
     */
    private SplObjectStorage $drawings;

    public function __construct(
        private readonly Theme $theme,
        private readonly SlideGeometry $geometry = new SlideGeometry(),
        private readonly TextMetric $metric = new TextMetric(),
        private readonly FontReport $fonts = new FontReport(),
    ) {
        $this->drawings = new SplObjectStorage();
    }

    /**
     *
     * How tall a shape is, in pixels. `$room` is how much of its box is left, which only an
     * image consults: an image takes what a paragraph and a table would not.
     *
     * `$box` is the column the shape is laid out in rather than the shape's own box: the
     * width text wraps in is the column's, and a shape is measured before it has a box.
     *
     * The union rather than `Block`, because it is exhaustive: another kind of shape is not
     * something this method can guess a height for, and naming the five is what says so at
     * the type rather than at run time. A heading, a quote, a paragraph and a list are all in
     * it as a {@see TextFrame} rather than as themselves: they are type rather than shape,
     * they reach a slide only inside a frame, and a frame measured apart from its paragraphs
     * is a frame whose height says nothing about the type in it.
     */
    public function height(Code|TextFrame|Image|Rule|Table $drawable, Box $box, int $room): int
    {
        return $this->sized($drawable, $box, $room, null);
    }

    /**
     * The same height, for a text frame whose own `font-size` is not the theme's body size.
     *
     * A placed text block carries a size its attributes named, and a frame measured at the
     * body's size is a measurement of type this block does not draw: a 24pt heading measured at
     * 16pt is shorter than it is, and the block would be clamped to room it did not need and
     * the last paragraph would be reported as lost when it was not.
     *
     * One method rather than two because the arithmetic is the same in every other respect —
     * the wrapping, the air between blocks and the insets do not depend on who named the size,
     * and a second copy of {@see self::textHeight()} is a second answer to the same question.
     *
     * @param int|null    $size    The size to measure at, in points, or null for the theme's body size.
     * @param float|null  $padding The inset the text will be drawn with, in pixels, or null for
     *                             the one PHPPresentation writes. Only a placed text block has
     *                             one; every other block is laid out by a layout that sets no
     *                             inset of its own, which is why it has a default.
     * @param string|null $face    The face to measure in, or null for the face of the block the
     *                             frame holds. Only a placed text block names one: a box with
     *                             a `font-family` of its own is drawn in it, and a box measured
     *                             in another face is a box measured for type it does not draw.
     */
    public function sized(
        Code|TextFrame|Image|Rule|Table $drawable,
        Box $box,
        int $room,
        ?int $size,
        ?float $padding = null,
        ?string $face = null,
    ): int
    {
        $width = $this->textWidth($box, $padding);

        return (int) round(match (true) {
            $drawable instanceof TextFrame => $this->textHeight($drawable, $width, $size, $face),
            $drawable instanceof Table => $this->tableHeight($drawable, $box),
            $drawable instanceof Rule => SlideGeometry::RULE_HEIGHT,
            $drawable instanceof Code => $this->codeHeight($drawable, $width),
            $drawable instanceof Image => $room,
        });
    }

    /**
     * The width text is laid out in inside a box, in pixels.
     *
     * PHPPresentation insets a text shape's body on all four sides whether it is told to or
     * not, so this is narrower than the box for every block that draws type, and not only
     * for the padding inside a code block's fill.
     *
     * `$padding` is the inset the deck asked for, and it is read here rather than left to
     * {@see SlideGeometry::INSET_X} because this has to be the width the text is *drawn* in.
     * The two answering differently is the whole of the bug this fixes: a `padding` reaches
     * the file through {@see self::freeText()} and never reached this, so every `padding=0`
     * was measured twenty pixels narrower than it was drawn — over-estimating lines and
     * clamping blocks that fit — and every `padding` above the default was measured wider,
     * which is the direction that silently loses text off the bottom of a box.
     *
     * Zero rather than {@see SlideGeometry::MIN_SIZE} below, because a negative inset is a box
     * with no room in it and one pixel is not a column. {@see \MarkdownPresentation\Render\TextMetric::lines()}
     * needs a positive width to terminate its word-breaking loop and treats what it is given
     * as the narrowest column there is, which reports the block as not fitting — the truth for
     * a box narrower than its own padding.
     */
    private function textWidth(Box $box, ?float $padding = null): int
    {
        return max(0, (int) round($box->width - 2 * ($padding ?? SlideGeometry::INSET_X)));
    }

    /**
     * The size a paragraph and a list item are drawn at, in points.
     *
     * Asked of the theme rather than stated here, because the type scale is a theme's decision
     * and a size a caller cannot change without a file rewrite is not one.
     */
    private function bodySize(): int
    {
        return $this->geometry->style()->body();
    }

    /**
     * The ink a heading is painted in: the theme's accent, or the ink the body text is in.
     */
    private function headingInk(): Color
    {
        return $this->geometry->style()->headingUsesAccent()
            ? $this->colour('accent1')
            : $this->ink();
    }

    /**
     * @param list<Run> $runs
    */
    private function runLines(
        array $runs,
        int $width,
        int $size,
        bool $bold = false,
        ?string $face = null,
    ): int
    {
        return $this->metric->lines($this->pieces($runs, $size, $bold, $face), $width);
    }

    /**
     * These runs as the pieces {@see TextMetric} measures, each at its own size, face and
     * weight — which is why a code span in a paragraph is not measured at the paragraph's
     * size, and a header row is measured wider than it is drawn.
     *
     * A code span is drawn in the theme's monospaced face and is measured in that face
     * too: the face is what decides how wide a character is, and a monospaced face is
     * measured by its own advance rather than by the table of a proportional one.
     *
     * @param list<Run> $runs
     *
     * @return list<Piece>
    */
    private function pieces(
        array $runs,
        int $size,
        bool $bold = false,
        ?string $face = null,
    ): array
    {
        $pieces = [];

        foreach ($runs as $run) {
            $this->reported($run);

            $pieces[] = TextMetric::piece(
                $run->text,
                self::pixels($run->size ?? ($run->code ? SlideGeometry::CODE_SIZE : $size)),
                $run->bold || $bold,
                $this->runFace($run, $face),
            );
        }

        return $pieces;
    }

    /**
     * A size of type in points as the size of it in pixels.
     *
     * A width is only ever compared with a box, and a box is measured in pixels, so the
     * size of the type is converted where the two meet. Without it a line of text is
     * measured a quarter narrower than it is drawn: it is laid out on fewer lines than it
     * takes, the box is written too short for it, and the text runs over whatever is
     * under it — which is the one thing the measurement exists to prevent.
     */
    private static function pixels(int $points): int
    {
        return (int) round($points * SlideGeometry::PIXELS_PER_POINT);
    }

    /**
     * A face a run names that there is no table of advance widths for, recorded.
     *
     * The face is measured with the reference face's widths, which are not its own, and
     * the report is where that is said: a face that was not measured says nothing. The
     * place is on the run, because the run is what knows where the face was written.
     *
     * A run that names no face is not a finding: the face of the block it is in is the
     * theme's, and the theme's faces are reported beside it.
     */
    private function reported(Run $run): void
    {
        if ($run->font === null || $run->fontWhere === null || $this->metric->has($run->font)) {
            return;
        }

        $this->fonts->record($run->font, $run->fontWhere, $this->metric->faces());
    }

    private function codeHeight(Code $block, int $width): int
    {
        $lines = 0;

        foreach ($block->lines as $line) {
            $lines += $this->metric->lines(
                [TextMetric::piece($line, self::pixels(SlideGeometry::CODE_SIZE), face: $this->font('mono'))],
                $width,
            );
        }

        return $lines * $this->geometry->lineHeight(SlideGeometry::CODE_SIZE) + 2 * SlideGeometry::INSET_Y;
    }

    /**
     * The height of a frame: its lines at the size each is drawn at, and the air between the
     * blocks that carry them.
     *
     * The air between two paragraphs of a frame is the theme's own rhythm —
     * {@see \MarkdownPresentation\Render\SlideGeometry::spaceBelow()} and
     * {@see self::gapBetween()} above — written as those paragraphs' own spacing before and
     * after, and PowerPoint spends it after every break the text contains, a newline inside
     * a paragraph or the mark between two of them, rather than only between blocks. Measured
     * rather than assumed, by asking PowerPoint to lay a frame out and report the height it
     * came back with, and by reading the line positions out of the PDF it exported: a
     * paragraph written over four source lines and wrapped to seven by its face came back
     * with a gap after each of its three newlines and none after any of the four times it
     * wrapped.
     *
     * So a break is a newline in the text or a paragraph mark, and a line the text merely
     * wrapped onto carries no gap. That is also what {@see TextMetric::lines()} counts, which
     * is what keeps the two halves of this from drifting apart: a newline is one more line
     * *and* one more gap, and counting one without the other leaves the frame a gap short of
     * the text in it.
     *
     * A heading and a quote are counted here for the first time. Each was a shape of its own
     * measured against the body box, and each is now a paragraph of this frame, so a heading's
     * lines are counted at the size of its level and a quote's at the size it is drawn in —
     * which is why this sums a height per block rather than multiplying one line height by a
     * total line count.
     */
    private function textHeight(TextFrame $frame, int $width, ?int $size, ?string $face): float
    {
        $blocks = $frame->blocks();
        $last = count($blocks) - 1;
        $height = 0.0;

        foreach ($blocks as $at => $block) {
            $height += $this->inFrameHeight($block, $width, $size, $face);

            if ($at < $last) {
                $height += $this->gapBetween($block, $blocks[$at + 1]);
            }
        }

        return $height;
    }

    /**
     * The height one block takes inside a frame: its lines, and the air after every break
     * the text inside it contains.
     *
     * The frame's blocks rather than its paragraphs, because the air belongs to the block —
     * see {@see TextFrame::blocks()} — and because a list is several paragraphs of one block,
     * walked here as the items it is.
     */
    private function inFrameHeight(Heading|Paragraph|Quote|ListBlock $block, int $width, ?int $at, ?string $face): float
    {
        $size = $at ?? $this->entrySize($block);
        $lines = 0;
        $breaks = 0;

        foreach ($this->frameParagraphs($block) as $entry) {
            $runs = $this->entryRuns($entry);
            $lines += $this->runLines(
                $runs,
                $this->entryWidth($block, $entry, $width),
                $size,
                bold: $block instanceof Heading && $this->geometry->style()->headingIsBold(),
                face: $face ?? $this->faceOf($block),
            );

            foreach ($runs as $run) {
                $breaks += substr_count($run->text, "\n");
            }
        }

        return $lines * $this->geometry->lineHeight($size) + $breaks * $this->breakGap($block);
    }

    /**
     * The paragraphs one block of a frame is drawn as: a list is several, the rest is one.
     *
     * @return list<FrameParagraph>
     */
    private function frameParagraphs(Heading|Paragraph|Quote|ListBlock $block): array
    {
        return $block instanceof ListBlock ? $block->items : [$block];
    }

    /**
     * The size a block of a frame is drawn at, which is the body's unless it says otherwise.
     */
    private function entrySize(Heading|Paragraph|Quote|ListBlock $block): int
    {
        return match (true) {
            $block instanceof Heading => $this->geometry->headingSize($block->level),
            $block instanceof Quote => SlideGeometry::QUOTE_SIZE,
            default => $this->bodySize(),
        };
    }

    /**
     * The face a block of a frame is drawn in, which is the face it is measured with.
     *
     * A heading is drawn in the heading face and everything else in the body face, and the
     * two are the same name in a theme whose faces are the same. Asked of the theme here for
     * the same reason the size is: the theme decides which face a block is drawn in, and the
     * measurement has to be of the face that will draw it.
     *
     * A placed text block names a face of its own and is drawn in it, so it is measured in
     * it too — which is why {@see self::inFrameHeight()} asks for that face first.
     */
    private function faceOf(Heading|Paragraph|Quote|ListBlock $block): string
    {
        return $block instanceof Heading ? $this->font('heading') : $this->font('body');
    }

    /**
     * The width the text of one paragraph of a frame is measured in.
     *
     * A list item's text starts a gutter in for its marker, and one gutter more for each
     * level, which is the width the text above it was measured in too. A quote starts a
     * gutter in as well: as a shape of its own it was an offset and a narrower box, and as a
     * paragraph it is an indent, which moves the text inside the box without moving the box —
     * the same paragraph property a list level uses, and the reason the two can share a
     * frame without either of them being a shape the reader cannot lift out on its own.
     *
     * @param FrameParagraph $entry A paragraph, or one item of a list.
     */
    private function entryWidth(
        Heading|Paragraph|Quote|ListBlock $block,
        Paragraph|Heading|Quote|array $entry,
        int $width,
    ): int {
        $gutter = $this->geometry->style()->gutter();

        return $width - $gutter * (is_array($entry) ? $entry['level'] : 0)
            - ($block instanceof Quote ? $gutter : 0);
    }

    /**
     * The runs of one paragraph of a frame, which is a paragraph, a heading, a quote, or one
     * item of a list.
     *
     * @param FrameParagraph $entry
     *
     * @return list<Run>
     */
    private function entryRuns(Paragraph|Heading|Quote|array $entry): array
    {
        return is_array($entry) ? $entry['runs'] : $entry->runs;
    }

    /**
     * The air PowerPoint spends after a break *inside* a block's own text, in pixels.
     *
     * Both sides of the block, where the air between two blocks is only the sides that meet
     * at a seam — because a hard line break is not a seam, it is the paragraph's own rhythm
     * being spent, and it is measured at the full spacing the paragraph carries rather than
     * at the half that happens to land on one side of a seam. Splitting it halved the air
     * under every hard line break in every deck, which is the one place a reader writing
     * prose by hand feels the number at all.
     *
     * A word the face merely wrapped onto is not a break and gets none of this, which is
     * what {@see TextMetric::lines()} is for: it counts a newline as a line and everything
     * else as a line only.
     */
    private function breakGap(Block $block): float
    {
        return ($this->geometry->spaceBelow($block) + $this->geometry->spaceAbove($block))
            * SlideGeometry::PIXELS_PER_POINT;
    }

    /**
     * The air between two blocks of a frame, in pixels.
     */
    private function gapBetween(Block $above, Block $below): float
    {
        return $this->geometry->gapBetween($above, $below);
    }

    private function tableHeight(Table $block, Box $box): int
    {
        return array_sum($this->rowHeights($block, $box));
    }

    /**
     * The height of each of a table's rows, in pixels.
     *
     * A row is at least {@see SlideGeometry::TABLE_ROW} whatever it says, and taller than
     * that when its longest cell wraps: PowerPoint grows a row to fit its content, so the
     * height has to know before the row does.
     *
     * @return list<int>
     */
    private function rowHeights(Table $block, Box $box): array
    {
        $widths = $this->columns($block, $box);
        $line = $this->geometry->lineHeight(SlideGeometry::TABLE_SIZE);
        $heights = [];

        foreach ($block->cells as $number => $row) {
            $lines = 1;

            foreach ($widths as $index => $width) {
                $lines = max($lines, $this->runLines(
                    $row[$index] ?? [],
                    $width - 2 * SlideGeometry::TABLE_PADDING,
                    SlideGeometry::TABLE_SIZE,
                    bold: $number === self::HEADER_ROW,
                    face: $this->font('body'),
                ));
            }

            $heights[] = max(SlideGeometry::TABLE_ROW, $lines * $line);
        }

        return $heights;
    }

    /**
     * The width of each of a table's columns, in pixels.
     *
     * Proportional to the widest cell in each rather than equal, because a table whose
     * columns are all a third of the body box wraps its narrow columns one word to a line
     * while its wide one sits empty.
     *
     * @return non-empty-list<int>
    */
    private function columns(Table $block, Box $box): array
    {
        $count = max(1, count($block->cells[0] ?? []));
        $wanted = [];

        for ($index = 0; $index < $count; $index++) {
            $widest = SlideGeometry::TABLE_COLUMN;

            foreach ($block->cells as $row) {
                $widest = max($widest, $this->metric->width(
                    $this->pieces($row[$index] ?? [], SlideGeometry::TABLE_SIZE, face: $this->font('body')),
                ));
            }

            $wanted[] = $widest;
        }

        return self::distribute($wanted, $box->width);
    }

    /**
     * Scale weights to a total, to the pixel.
     *
     * The last column takes the remainder rather than `intdiv()` on every column, which drops
     * up to a pixel each: twelve columns of 880 came to 876 and three came to 879, and a grid
     * narrower than the shape it sits in is one PowerPoint lays out again itself.
     *
     * @param non-empty-list<int> $weights
     *
     * @return non-empty-list<int>
     */
    private static function distribute(array $weights, int $total): array
    {
        $sum = array_sum($weights);
        $widths = [];
        $used = 0;
        $last = array_key_last($weights);

        foreach ($weights as $index => $weight) {
            $share = $index === $last
                ? $total - $used
                : (int) floor($weight * $total / $sum);
            $widths[] = $share;
            $used += $share;
        }

        return $widths;
    }

        /**
     * A shape the deck placed itself, as the one shape it is written as.
     *
     * Three cases rather than one because PHPPresentation takes the paint of each differently: an
     * autoshape is written with an {@see \PhpOffice\PhpPresentation\Style\Outline} of one colour and
     * one width, while a text box and a picture take a {@see Border}. That is the whole of why a
     * dashed outline is offered on neither and refused by name instead.
     *
     * @param Box $box    The box the deck placed the shape in.
     * @param int $height How tall to draw it, which is the box unless the text did not fit in it.
     */
    public function free(Slide $slide, FreeShape $shape, Box $box, int $height): AbstractShape
    {
        $written = match (true) {
            $shape instanceof FreeText => $this->freeText($slide, $shape, $box, $height),
            $shape instanceof FreePicture => $this->freePicture($slide, $shape),
            // Everything else is an ink shape, which is what a free shape is when it is
            // neither type nor a picture: {@see FreeShape} has a class of its own for each
            // kind, and this is the one that is left.
            default => $this->autoshape($slide, $shape),
        };

        // Applied here rather than in each of the three, because it is the one thing all three
        // take the same way and a shadow written in three places is a shadow that three of them
        // can disagree about.
        $shape->shadow?->apply($written);

        return $written->setName($shape->name());
    }

    /**
     * An ink shape, painted through its outline.
     *
     * `getOutline()` rather than `getBorder()` because that is what the writer reads for an
     * autoshape — and because `a:noFill` on it is what says "no stroke". A `Border` left alone
     * writes an `a:ln` with no fill in it, which is valid and means *inherit*, and a free shape
     * has nothing to inherit from but the theme.
     *
     * @param FreeShape $shape Any free shape that is not type and not a picture. Takes the base
     *                         class rather than {@see FreeAutoShape} because the ink case is the
     *                         one that is left rather than a third thing to match.
     */
    private function autoshape(Slide $slide, FreeShape $shape): AbstractShape
    {
        $written = $slide->createAutoShape();
        $paint = $shape->paint;

        // PHPPresentation's AutoShape defaults to a heart, so a shape left unset is a heart on
        // the slide rather than a rectangle.
        $written->setType($shape->kind->autoShape() ?? AutoShape::TYPE_RECTANGLE);

        if ($shape->kind === ShapeKind::RoundRect && $paint->radius > 0) {
            $written->setRoundRectCorner($paint->radius);
        }

        $this->filled($written->getFill(), $paint->fill);
        $this->filled($written->getOutline()->getFill(), $paint->stroke);
        $written->getOutline()->setWidth($this->lineWidth($paint->strokeWidth));

        $box = $shape->placement->box;
        $this->place($written, $box, $box->height);
        $written->setRotation($shape->placement->rotate);

        // The shape's own text, in the shape's own body — one shape in the file, and the
        // native thing: double-click the arrow and the cursor is in the arrow, and dragging the
        // arrow drags the words with it, which two overlapping shapes do not do.
        //
        // What it costs is everything {@see self::freeText()} can do and this cannot.
        // PHPPresentation writes `<a:r><a:t>…</a:t></a:r>` with no `a:rPr`, so the words are
        // drawn in `tx1` — the theme's body text colour, which is what the page is read against
        // and which follows `dark:`. So a label is legible on a filled shape in both themes, and
        // what it has no contrast against is the shape's *own fill* where that is close to the
        // page's: a pale fill on a dark theme, or a dark one on a light theme. There is no
        // attribute that changes it, and one object and no styling is the other half of the
        // trade. A label that has to be legible against its own fill is a `:::content` box.
        if ($shape->label !== '') {
            $written->setText($shape->label);
        }

        return $written;
    }

    /**
     * A fill as the deck named it, or `a:noFill` where it named none.
     *
     * Said rather than left alone on both: a `Fill` left at its default writes nothing at all,
     * which for a shape means *inherit from the theme*, and a free shape is drawn on a blank
     * layout where there is nothing behind it to inherit from. `a:noFill` is the only way to
     * mean "nothing".
     */
    private function filled(Fill $fill, ?Color $colour): void
    {
        $fill->setFillType($colour === null ? Fill::FILL_NONE : Fill::FILL_SOLID);

        if ($colour !== null) {
            $fill->setStartColor($colour);
        }
    }

    /**
     * A stroke's width in pixels, which is the unit an {@see \PhpOffice\PhpPresentation\Style\Outline}
     * is written in.
     *
     * A minimum of one because a deck is written in points and `stroke-width=0.25` is three
     * tenths of a pixel, which `a:ext` has no way to say.
     */
    private function lineWidth(float $points): int
    {
        return max(1, (int) round($points * SlideGeometry::PIXELS_PER_POINT));
    }

    /**
     * A picture in the box the deck put it in.
     *
     * The same {@see Image} block and the same {@see self::fromFile()} and
     * {@see self::fromBytes()} a picture in a column goes through; only the box is different,
     * and `fit=stretch` is the one flag — {@see self::image()} centres a picture in its box and
     * a stretched one fills it.
     */
    private function freePicture(Slide $slide, FreePicture $shape): AbstractDrawingAdapter
    {
        $written = $this->drawing($slide, $shape);

        // The alt text is what a screen reader reads and what PowerPoint's own pane shows, and
        // a file path read aloud is not a description of a picture. {@see self::image()} has
        // the longer version of why nothing in the file records where the deck found it.
        $written->setDescription($shape->image->alt === '' ? $shape->image->written : $shape->image->alt);

        $box = $shape->placement->box;

        // `stretch` turns the ratio off, and then {@see self::place()} is the whole of it —
        // both dimensions stick, because there is no ratio left for either to re-derive from.
        if ($shape->fit === Pictures::STRETCH) {
            $written->setResizeProportional(false);
            $this->place($written, $box, $box->height);
            $written->setRotation($shape->placement->rotate);

            return $written;
        }

        // `contain` is the default and needs the fit the column path uses: see
        // {@see self::fitted()} on why `place()` is not enough on its own.
        $this->place($written, $box, $box->height);
        $this->fitted($written, $box, $box->height);
        $written->setRotation($shape->placement->rotate);

        return $written;
    }

    /**
     * The drawing a picture is written as, and the one every slide that draws the same
     * picture points at.
     *
     * The picture of a template's chrome is the same shape on every slide that names the
     * template, and a picture in a package is one media part: the drawing is made once and
     * every slide that draws it points at it. A picture a deck placed on a slide of its own
     * is a shape of its own, and is written as one.
     */
    private function drawing(Slide $slide, FreePicture $shape): AbstractDrawingAdapter
    {
        if ($this->drawings->offsetExists($shape)) {
            $written = $this->drawings[$shape];

            // The drawing is already in the package, and the slide it is drawn on needs it in
            // its own shape tree: the same drawing, on two slides.
            $slide->addShape($written);

            return $written;
        }

        // The same fork {@see self::image()} makes, for the same reason: a converted picture
        // carries its bytes and its size with them and is written from those, while a file is
        // measured by PHPPresentation from the path it was given.
        $converted = $shape->image->converted;

        // Both arms: `converted` is null for every format PHPPresentation can embed, so the
        // file path is what a placed picture usually takes and the bytes are for a WebP or an
        // SVG, which a `.pptx` cannot hold on its own.
        $written = $converted === null
            ? $this->fromFile($slide, $shape->image)
            : $this->fromBytes($slide, $converted);

        $this->drawings[$shape] = $written;

        return $written;
    }

    /**
     * A text block in the box the deck put it in, painted and set as its attributes asked.
     *
     * The paragraphs come from {@see self::textFrame()} and are then overridden rather than
     * written a second time: the frame already knows the air between blocks, how a list is
     * numbered and what a quote is, and a second writer for the same frame would be a second
     * answer to each of those.
     */
    private function freeText(Slide $slide, FreeText $shape, Box $box, int $height): RichText
    {
        $written = $this->textFrame($slide, $shape->frame, $box, $height, $shape->style);
        $paint = $shape->paint;
        $style = $shape->style;

        $this->filled($written->getFill(), $paint->fill);

        // A border left alone writes `LINE_SINGLE` in PHPPresentation's default, so the absence
        // of one has to be said rather than left.
        $written->getBorder()->setLineStyle($paint->stroke === null ? Border::LINE_NONE : Border::LINE_SINGLE);

        if ($paint->stroke !== null) {
            $written->getBorder()->setLineWidth($paint->strokeWidth)->setColor($paint->stroke);
        }

        if ($style->padding !== null) {
            $written->setInsetLeft($style->padding)
                ->setInsetRight($style->padding)
                ->setInsetTop($style->padding)
                ->setInsetBottom($style->padding);
        }

        foreach ($written->getParagraphs() as $paragraph) {
            $paragraph->getAlignment()->setHorizontal($style->align)->setVertical($style->verticalAlign);
        }

        $written->setRotation($shape->placement->rotate);

        return $written;
    }

    /**
     * The runs of one paragraph of a frame, with the box's own type filled in where a run has
     * none of its own.
     *
     * The box's colour, size and face are the defaults for the words inside it rather than an
     * override of them: a run that carries a colour of its own keeps it, and one that does not
     * takes the box's. That is what lets a word in a box be a different colour from the rest
     * of the box.
     *
     * @param FrameParagraph $entry A paragraph, a heading, a quote, or one item of a list.
     *
     * @return FrameParagraph
     */
    private function boxed(Paragraph|Heading|Quote|array $entry, ?TextStyle $style): Paragraph|Heading|Quote|array
    {
        if ($style === null) {
            return $entry;
        }

        $runs = [];

        foreach ($this->entryRuns($entry) as $run) {
            $runs[] = $run->filled([
                'colour' => $style->colour === null ? null : '#' . $style->colour->getRGB(),
                'size' => $style->size,
                'font' => $style->face,
            ]);
        }

        return match (true) {
            is_array($entry) => array_replace($entry, ['runs' => $runs]),
            $entry instanceof Heading => new Heading($runs, $entry->level, $entry->step),
            $entry instanceof Quote => new Quote($runs, $entry->step),
            default => new Paragraph($runs, $entry->step),
        };
    }

/**
     *
     * The shape a drawable becomes, added to the slide.
     *
     * @param Box $box    Where it starts and how much of its box it has left.
     * @param int $height The height {@see self::height()} gave it.
     */
    public function write(Slide $slide, Code|TextFrame|Image|Rule|Table $drawable, Box $box, int $height): AbstractShape
    {
        $shape = match (true) {
            $drawable instanceof Code => $this->code($slide, $drawable, $box, $height),
            $drawable instanceof Table => $this->table($slide, $drawable, $box, $height),
            $drawable instanceof Image => $this->image($slide, $drawable, $box, $height),
            $drawable instanceof Rule => $this->rule($slide, $box),
            $drawable instanceof TextFrame => $this->textFrame($slide, $drawable, $box, $height),
        };

        $shape->setName($drawable->name());

        return $shape;
    }

    /**
     * A run of type, as one shape holding one paragraph each.
     *
     * The frame is the unit a reader edits: it is drawn once, placed once, and PowerPoint
     * flows its paragraphs inside it — which is why a heading and a quote are paragraphs here
     * rather than boxes of their own. Both carry their own runs, so the paragraph they are
     * written as says what the shape used to: the heading's size, weight, face and ink, the
     * quote's indent, italic and quiet ink.
     */
    private function textFrame(Slide $slide, TextFrame $frame, Box $box, int $height, ?TextStyle $style = null): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);

        $blocks = $frame->blocks();
        $last = count($blocks) - 1;
        $first = true;

        foreach ($blocks as $at => $block) {
            $entries = $this->frameParagraphs($block);
            $tail = count($entries) - 1;

            foreach ($entries as $index => $entry) {
                // A fresh shape already carries one paragraph, and creating another for the
                // first would leave that empty one in the file as a blank line.
                $paragraph = $first ? $shape->getActiveParagraph() : $shape->createParagraph();
                $first = false;
                $this->paragraph($paragraph, $entry, $style);

                // `runs()` zeroes the spacing on every paragraph it fills, which is right for
                // a shape of one paragraph and wrong for a frame of several. The air goes back
                // on here: the two halves of it on the paragraph at the seam between two
                // blocks, which is where {@see self::textHeight()} counted them, and neither
                // on the first block's first paragraph nor the last block's last — a leading
                // gap pushes the frame's text down inside a box measured without it, and a
                // trailing one is space nothing was measured for.
                if ($index === 0 && $at > 0) {
                    $paragraph->setSpacingBefore($this->geometry->spaceAbove($block));
                }

                if ($index === $tail && $at < $last) {
                    $paragraph->setSpacingAfter($this->geometry->spaceBelow($block));
                }
            }
        }

        return $shape;
    }

    /**
     * The runs of one paragraph of a frame, filled in at the size and in the ink that
     * paragraph's own kind calls for.
     *
     * Four arms rather than one, because four kinds of paragraph is what a frame holds and a
     * heading is not a paragraph in a larger size: it is a different weight, a different face
     * and, under a theme that asks for it, a different colour. {@see self::entrySize()} is
     * where the measure half reads the same answer from, so the two cannot disagree about how
     * big this is.
     *
     * @param FrameParagraph $entry A paragraph, a heading, a quote, or one item of a list.
     * @param ?TextStyle     $style The box the paragraph is drawn in, whose own type is the
     *                              default for the words in it.
     */
    private function paragraph(TextParagraph $paragraph, Paragraph|Heading|Quote|array $entry, ?TextStyle $style = null): void
    {
        $entry = $this->boxed($entry, $style);

        match (true) {
            $entry instanceof Heading => $this->runs(
                $paragraph,
                $entry->runs,
                $this->geometry->headingSize($entry->level),
                bold: $this->geometry->style()->headingIsBold(),
                font: $this->headingInk(),
                face: $this->font('heading'),
            ),
            $entry instanceof Quote => $this->quote($paragraph, $entry),
            $entry instanceof Paragraph => $this->runs($paragraph, $entry->runs, $this->bodySize()),
            default => $this->item($paragraph, $entry),
        };
    }

    /**
     * A quote, which as a paragraph is an indent rather than a shape.
     *
     * It was a shape of its own once, offset by a gutter and drawn in a box a gutter narrower,
     * which put it outside the run of type around it and meant the air above and below it was
     * the column's arithmetic rather than the theme's. As a paragraph the indent moves the
     * text inside the frame without moving the frame, and the air is written like anyone
     * else's.
     */
    private function quote(TextParagraph $paragraph, Quote $block): void
    {
        $paragraph->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setMarginLeft($this->geometry->style()->gutter());

        $this->runs(
            $paragraph,
            $block->runs,
            SlideGeometry::QUOTE_SIZE,
            italic: true,
            font: $this->subtleInk(),
        );
    }

    /**
     * One item of a list, which in the file is a paragraph with a marker on it.
     *
     * @param array{level: int, runs: list<Run>, ordered: bool, start: ?int} $item
     */
    private function item(TextParagraph $paragraph, array $item): void
    {
        $paragraph->setBulletStyle($this->bullet(
            $item['ordered'] ? Bullet::TYPE_NUMERIC : Bullet::TYPE_BULLET,
            $item['start'],
        ));

        // The level the writer numbers at, which is what makes a numbered sub-list
        // number itself instead of carrying on from the list above it. The margin and
        // the indent below are stated outright, so this moves the numbering and not the
        // position.
        $paragraph->getAlignment()->setLevel($item['level'] - 1);
        $paragraph->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $paragraph->getAlignment()->setMarginLeft($item['level'] * $this->geometry->style()->gutter());
        $paragraph->getAlignment()->setIndent(-$this->geometry->style()->gutter());

        $this->runs($paragraph, $item['runs'], $this->bodySize());
    }

    private function code(Slide $slide, Code $block, Box $box, int $height): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);
        $shape->setDescription($block->language);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->panel());

        $first = true;

        foreach ($block->lines as $line) {
            $paragraph = $first ? $shape->getActiveParagraph() : $shape->createParagraph();
            $first = false;

            $paragraph->setBulletStyle($this->bullet(Bullet::TYPE_NONE));
            $paragraph->setSpacingBefore(0);
            $paragraph->setSpacingAfter(0);
            $paragraph->getFont()->setSize(SlideGeometry::CODE_SIZE)
                ->setName($this->font('mono'))
                ->setColor($this->subtleInk());

            $paragraph->createTextRun($line);
        }

        return $shape;
    }

    private function table(Slide $slide, Table $block, Box $box, int $height): TableShape
    {
        $columns = $this->columns($block, $box);
        $heights = $this->rowHeights($block, $box);
        $shape = $slide->createTableShape(count($columns));
        $this->place($shape, $box, $height);
        $shape->setDescription(implode(',', $block->align));
        // Both flags stated rather than inherited: PHPPresentation's `Table` turns `firstRow` and
        // `bandRow` both on by default, and a default that changed under this library would
        // change what a header row looks like. Banding is off because the table carries its
        // own fills and a band drawn behind them would fight them.
        $shape->setFirstRow(true)->setBandRow(false);

        foreach ($block->cells as $number => $row) {
            // The height the shape was measured with, set on the row too: PHPPresentation
            // writes `<a:tr h>` from the row rather than from the shape, so a row left at
            // its own default is drawn at a height nothing else in the layout knows about.
            $created = $shape->createRow()->setHeight($heights[$number]);

            // The header is the row a reader looks for first and nothing in the format marks
            // one out unless it is asked to. `a:tblPr firstRow` is the flag PowerPoint's own
            // table styles hang their header treatment on, and the weight and fill below are
            // that treatment spelled out — this library writes no table style for it to come
            // from. `<a:tblHeader/>`, which repeats the row where a table splits across
            // slides, is not written: PHPPresentation's row carries no such flag, and a
            // table clamped to one slide is not a table that splits.
            if ($number === self::HEADER_ROW) {
                $created->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->panel());
            }

            $cells = $created->getCells();

            foreach ($row as $index => $runs) {
                // Every row is as wide as the header, because that is where the width is
                // established: a row with fewer cells is padded and one with more is drawn as
                // far as the header goes. There was a guard here for a row wider than the
                // header, and it could not fire — a table that stopped drawing half a row was
                // the worse of the two failures anyway.
                $cell = $cells[$index];
                $cell->setWidth($columns[$index]);
                $paragraph = $cell->getActiveParagraph();
                $paragraph->getAlignment()
                    ->setHorizontal($this->alignment($block->align[$index] ?? 'left'))
                    ->setMarginLeft(SlideGeometry::TABLE_PADDING)
                    ->setMarginRight(SlideGeometry::TABLE_PADDING);
                $paragraph->setBulletStyle($this->bullet(Bullet::TYPE_NONE));

                $this->runs(
                    $paragraph,
                    $runs,
                    SlideGeometry::TABLE_SIZE,
                    bold: $number === self::HEADER_ROW,
                    font: $this->ink(),
                );
            }
        }

        return $shape;
    }

    private function image(Slide $slide, Image $block, Box $box, int $height): AbstractDrawingAdapter
    {
        $converted = $block->converted;
        $shape = $converted === null ? $this->fromFile($slide, $block) : $this->fromBytes($slide, $converted);
        // The alt text, because `descr` is what a screen reader reads out and what
        // PowerPoint's alt-text pane shows, and a file path read aloud is not a description
        // of a picture. The written path stands in only for a deck that named no alt: it is
        // what the author recognises, and an empty description says nothing at all. Nothing
        // in the file records where the deck found the picture — the media part carries the
        // bytes under a name of PHPPresentation's, and the slide's own relationships name no
        // image — so this is the only description of it that reaches the file.
        $shape->setDescription($block->alt === '' ? $block->written : $block->alt);

        $this->place($shape, $box, $height);

        $this->fitted($shape, $box, $height);

        return $shape;
    }

    /**
     * A picture fitted to the room it was given, and centred in what is left of it.
     *
     * {@see AbstractGraphic::setWidthAndHeight()} **once**, and never {@see self::place()}'s
     * separate width and height calls for a picture that keeps its ratio: with proportional
     * resize on, `setWidth()` re-derives the height from the ratio and `setHeight()` then
     * re-derives the width from *that*, so a 2:1 picture in a square box comes back twice as
     * wide as the box. The compounding is invisible for a picture whose ratio happens to match
     * its box and wrong by a factor of the ratio for every other one.
     *
     * Centred on both axes, because the slack is only ever on one of them: a picture that fills
     * the box's width has a gap above and below and none at the sides. The offsets are floored
     * at the box because `setWidthAndHeight()` rounds the losing dimension *up*, and centring
     * that would push the picture past the block below it.
     *
     * @param int $room How tall the picture may be, which is the box unless it was clamped.
     */
    private function fitted(AbstractGraphic $shape, Box $box, int $room): void
    {
        // The room the block was given may have run out, so the box it is fitted to is
        // clamped before the ratio is read off it: a zero dimension disables the
        // proportional fit and hands the picture the negative room unchanged.
        $width = max($box->width, SlideGeometry::MIN_SIZE);
        $tall = max($room, SlideGeometry::MIN_SIZE);

        $shape->setWidthAndHeight($width, $tall);

        $shape->setOffsetX($box->x + max(0, intdiv($width - $shape->getWidth(), 2)))
            ->setOffsetY($box->y + max(0, intdiv($tall - $shape->getHeight(), 2)));
    }

    /**
     * A drawing that embeds the file the deck named.
     *
     * `setPath()` before `place()`, because PHPPresentation measures a drawing with
     * `getimagesize()` only while it has no size of its own, and a shape that already knows
     * how big it is is one it will not measure. Sizing first leaves the ratio unknown, and a
     * picture with no ratio to keep is stretched to the box.
     */
    private function fromFile(Slide $slide, Image $block): File
    {
        $shape = $slide->createDrawingShape();
        $shape->setPath($block->resolved);

        return $shape;
    }

    /**
     * A drawing that carries bytes rather than a path, which is what a converted image is.
     *
     * The size is set from the converted image rather than left for PHPPresentation to
     * measure, because `Drawing\Base64` measures nothing where `Drawing\File` measures the
     * file it was given — and the size is what
     * {@see \PhpOffice\PhpPresentation\Shape\AbstractGraphic::setWidthAndHeight()} fits the
     * picture to its box by.
     */
    private function fromBytes(Slide $slide, ConvertedImage $converted): Base64
    {
        $shape = new Base64();
        $shape->setData('data:image/png;base64,' . base64_encode($converted->bytes));
        $shape->setWidth($converted->width)->setHeight($converted->height);
        $slide->addShape($shape);

        return $shape;
    }

    private function rule(Slide $slide, Box $box): AbstractShape
    {
        $shape = $slide->createAutoShape();
        // PHPPresentation's AutoShape defaults to a heart, so a thematic break left unset is
        // a heart on the slide rather than a line.
        $shape->setType(AutoShape::TYPE_RECTANGLE);
        // The bar is centred in the room the rule occupies, and its thickness is the theme's:
        // a hairline under a serif deck and a heavy one under a corporate one are the same
        // shape at two weights.
        $weight = $this->geometry->style()->ruleWeight();
        $shape->setOffsetX($box->x)
            ->setOffsetY($box->y + intdiv(SlideGeometry::RULE_HEIGHT - $weight, 2))
            ->setWidth($box->width)
            ->setHeight($weight);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->colour('accent1'));
        $shape->getBorder()->setLineStyle(Border::LINE_NONE);

        return $shape;
    }

    /**
     * A title, which is the one place a heading is not asked for: the title box takes the
     * slide's title at the size the role gives it, whatever level the heading was written
     * at.
     *
     * Runs rather than a string, because a title carries the same inline markup any other
     * text does, and `# Release **v2** notes` is the spelling a model reaches for first.
     *
     * @param list<Run> $runs
     */
    public function title(Slide $slide, array $runs, Box $box, int $size, string $alignment): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $box->height);
        $shape->setName('title');
        $shape->getActiveParagraph()->getAlignment()->setHorizontal($alignment);

        $this->runs(
            $shape->getActiveParagraph(),
            $runs,
            $size,
            bold: $this->geometry->style()->headingIsBold(),
            font: $this->ink(),
            face: $this->font('heading'),
        );

        return $shape;
    }

    /**
 * * A text box that shrinks rather than grows.
 *
 * PHPPresentation's default is `spAutoFit`, and a shape carrying it is resized to its
 * content by the reader — downwards over whatever the renderer put below it, which is the
 * one failure a layout cannot recover from. `normAutofit` is the other way round: the shape
 * keeps the height it was given and the type is scaled down until it fits. {@see
 * self::height()} is an estimate, so a mis-estimate has to land somewhere; a box the reader
 * finds a line too tall is a little whitespace, and a box it finds a line too short is the
 * next block drawn over this one.
 */
    private function textBox(Slide $slide): RichText
    {
        $shape = $slide->createRichTextShape();

        return $shape->setAutoFit(RichText::AUTOFIT_NORMAL);
    }

    /**
     * The only place a shape is given its position and size, and the only place that
     * clamps them: a block can be laid out past the bottom of the box it was given, and
     * `a:off`/`a:ext` are signed and unsigned coordinates respectively.
     */
    private function place(AbstractShape $shape, Box $box, int $height): void
    {
        $shape->setOffsetX($box->x)
            ->setOffsetY($box->y)
            ->setWidth(max($box->width, SlideGeometry::MIN_SIZE))
            ->setHeight(max($height, SlideGeometry::MIN_SIZE));
    }

    /**
     *
     * The runs of one paragraph.
     *
     * The paragraph rather than the shape around it, because a table cell's is not the
     * shape's: {@see \PhpOffice\PhpPresentation\Shape\Table\Cell} is a container of its own,
     * and a shape asked for its active paragraph cannot name a cell's.
     *
     * Spacing is zeroed here rather than left at PowerPoint's default because
     * {@see self::height()} decides where the next shape starts, and a leading gap the
     * heights do not know about is what puts one shape on top of the one before it. A
     * A {@see TextFrame} puts its own air back on top of that at its first block and its last,
     * and leaves the blocks between them at zero: they are paragraphs of one thing, so the gap
     * the frame asks for is the gap around it rather than a gap inside it.
     *
     * @param list<Run> $runs
    */
    private function runs(
        TextParagraph $paragraph,
        array $runs,
        int $size,
        bool $bold = false,
        bool $italic = false,
        ?Color $font = null,
        ?string $face = null,
    ): void {
        $paragraph->setSpacingBefore(0);
        $paragraph->setSpacingAfter(0);

        $plain = $font ?? $this->ink();

        foreach ($runs as $run) {
            $element = $paragraph->createTextRun($run->text);
            $element->getFont()
                ->setSize($run->size ?? ($run->code ? SlideGeometry::CODE_SIZE : $size))
                ->setName($this->runFace($run, $face))
                ->setBold($run->bold || $bold)
                ->setItalic($run->italic || $italic)
                ->setStrikethrough($run->strike ? StyleFont::STRIKE_SINGLE : StyleFont::STRIKE_NONE)
                // The link branch is asked first: `$font ?? …` short-circuited it, so a link
                // inside a heading or a quote was drawn in the heading's own colour and
                // looked like ordinary text that happened to be clickable.
                ->setColor($this->runColour($run, $plain))
                ->setUnderline($this->runUnderline($run));

            if ($run->link === null) {
                continue;
            }

            $element->getHyperlink()->setUrl($run->link);
        }
    }

    /**
     * The face a run is drawn in, which is the face it is measured in.
     *
     * A run that names a face of its own is drawn in it — a text style may set one, and so
     * may the box the run is in — and a code run is drawn in the theme's monospaced face.
     * Everything else is drawn in the face of the block it is in, which is the theme's
     * heading face for a heading and its body face for the rest.
     *
     * One method for both halves of the writer, because they have to answer the same
     * question: a run measured in one face and drawn in another is a box that is the wrong
     * height for the words in it.
     */
    private function runFace(Run $run, ?string $face): string
    {
        return $run->font
            ?? ($run->code ? $this->font('mono') : ($face ?? $this->font('body')));
    }

    /**
     * The ink a run is drawn in: its own, the link's, or the paragraph's.
     *
     * A run's own colour wins over the paragraph's, because a text style is the more
     * particular of the two statements — and the paragraph's is the fallback for every run
     * that says nothing about its colour.
     */
    private function runColour(Run $run, Color $plain): Color
    {
        if ($run->colour !== null) {
            return new Color(ltrim($run->colour, '#'));
        }

        return $run->link === null ? $plain : $this->colour('hlink');
    }

    /**
     * The underline a run is drawn with: its own, the link's, or none.
     *
     * A link is underlined because a reader has to see that it is one, and a run that says
     * otherwise is a run that knows better.
     */
    private function runUnderline(Run $run): string
    {
        return $run->underline || $run->link !== null
            ? StyleFont::UNDERLINE_SINGLE
            : StyleFont::UNDERLINE_NONE;
    }

    /**
     *
     * A bullet, in the theme's accent colour.
     *
     * Built rather than taken from the paragraph: `getBulletStyle()` is declared nullable
     * even though the constructor always fills it, and a paragraph that had lost its bullet
     * would otherwise end the render with a call on null.
     *
     * `$start` is null for a bullet, and for an ordered item written without one.
     * PHPPresentation's own rule is that a start equal to the number the numbering before it
     * began at continues it, so `1. 1. 1.` still reads as `1. 2. 3.` and `3. 4.` reads as
     * it is written.
    */
    private function bullet(string $type, ?int $start = null): Bullet
    {
        $bullet = new Bullet();
        $bullet->setBulletType($type)
            ->setBulletChar('•')
            ->setBulletNumericStyle(Bullet::NUMERIC_ARABICPERIOD)
            ->setBulletColor($this->colour('accent1'))
            ->setBulletNumericStartAt($start);

        return $bullet;
    }

    private function alignment(string $align): string
    {
        return match ($align) {
            'center' => Alignment::HORIZONTAL_CENTER,
            'right' => Alignment::HORIZONTAL_RIGHT,
            default => Alignment::HORIZONTAL_LEFT,
        };
    }

    /**
     * Every colour a shape is painted with, by the part it plays rather than by the slot
     * that holds it.
     *
     * The accents and the hyperlinks are slots either way and go through {@see self::colour()}
     * directly; everything that paints the page, the type on it, or a panel on it is
     * resolved here, so a dark theme's shapes follow its `dark: true` rather than the
     * `dk1` they would have been given either way.
     */
    private function ink(): Color
    {
        return $this->colour($this->theme->ink());
    }

    private function subtleInk(): Color
    {
        return $this->colour($this->theme->subtleInk());
    }

    private function panel(): Color
    {
        return $this->colour($this->theme->panel());
    }

    private function colour(string $slot): Color
    {
        return new Color(ltrim($this->theme->colour($slot) ?? '#000000', '#'));
    }

    private function font(string $slot): string
    {
        return $this->theme->fonts()->get($slot) ?? '';
    }
}
