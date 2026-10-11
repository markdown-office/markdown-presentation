<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\AutoShape;
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

/**
 * Turns a block into a shape, and says how tall it has to be before it does.
 *
 * Measure and draw are the same object's two methods on purpose: a height computed by one
 * pass and a shape drawn by another drift apart the first time somebody edits one of them,
 * and the drift shows up as a gap rather than as a failure.
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

    public function __construct(
        private readonly Theme $theme,
        private readonly SlideGeometry $geometry = new SlideGeometry(),
        private readonly TextMetric $metric = new TextMetric(),
    ) {
    }

    /**
     *
     * How tall a shape is, in pixels. `$room` is how much of its box is left, which only an
     * image consults: an image takes what a paragraph and a table would not.
     *
     * `$box` is the column the shape is laid out in rather than the shape's own box: the
     * width text wraps in is the column's, and a shape is measured before it has a box.
     *
     * The union rather than `Block`, because it is exhaustive: a tenth kind of shape is not
     * something this method can guess a height for, and naming the nine is what says so at
     * the type rather than at run time. A paragraph and a list are in it as a
     * {@see TextFrame} rather than as themselves: they only ever reach a slide inside one, and
     * a frame measured apart from its paragraphs is a frame whose height says nothing about
     * the type in it.
     */
    public function height(Code|TextFrame|Heading|Image|Quote|Rule|Table $drawable, Box $box, int $room): int
    {
        $width = $this->textWidth($box);

        return match (true) {
            $drawable instanceof TextFrame => $this->textHeight($drawable, $width),
            $drawable instanceof Heading => $this->geometry->headingHeight(
                $drawable->level,
                $this->runLines($drawable->runs, $width, $this->geometry->headingSize($drawable->level), bold: true),
            ),
            $drawable instanceof Code => $this->codeHeight($drawable, $width),
            $drawable instanceof Table => $this->tableHeight($drawable, $box),
            $drawable instanceof Rule => SlideGeometry::RULE_HEIGHT,
            $drawable instanceof Image => $room,
            $drawable instanceof Quote => $this->runLines(
                $drawable->runs,
                $width - $this->geometry->style()->gutter(),
                SlideGeometry::QUOTE_SIZE,
            ) * $this->geometry->lineHeight(SlideGeometry::QUOTE_SIZE),
        };
    }

    /**
     * The width text is laid out in inside a box, in pixels.
     *
     * PHPPresentation insets a text shape's body on all four sides whether it is told to or
     * not, so this is narrower than the box for every block that draws type, and not only
     * for the padding inside a code block's fill.
     */
    private function textWidth(Box $box): int
    {
        return max(SlideGeometry::MIN_SIZE, $box->width - 2 * SlideGeometry::INSET_X);
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
    private function runLines(array $runs, int $width, int $size, bool $mono = false, bool $bold = false): int
    {
        return $this->metric->lines($this->pieces($runs, $size, $mono, $bold), $width);
    }

    /**
     * These runs as the pieces {@see TextMetric} measures, each at its own size, face and
     * weight — which is why a code span in a paragraph is not measured at the paragraph's
     * size, and a header row is measured wider than it is drawn.
     *
     * @param list<Run> $runs
     *
     * @return list<Piece>
    */
    private function pieces(array $runs, int $size, bool $mono = false, bool $bold = false): array
    {
        $pieces = [];

        foreach ($runs as $run) {
            $pieces[] = TextMetric::piece(
                $run->text,
                $run->code ? SlideGeometry::CODE_SIZE : $size,
                $run->code || $mono,
                $run->bold || $bold,
            );
        }

        return $pieces;
    }

    private function codeHeight(Code $block, int $width): int
    {
        $lines = 0;

        foreach ($block->lines as $line) {
            $lines += $this->metric->lines(
                [TextMetric::piece($line, SlideGeometry::CODE_SIZE, mono: true)],
                $width,
            );
        }

        return $lines * $this->geometry->lineHeight(SlideGeometry::CODE_SIZE) + 2 * SlideGeometry::INSET_Y;
    }

    /**
     * The height of a frame: its paragraphs one after another, with the theme's own gap
     * between them.
     *
     * The gap is the one the layout spends between two shapes of a column, moved into the one
     * shape the paragraphs now share — counted once here and written there as
     * {@see SlideGeometry::paragraphSpace()}, which is what stops the two from being spent
     * twice over a frame that is a gap taller than the text in it.
     */
    private function textHeight(TextFrame $frame, int $width): int
    {
        $paragraphs = $frame->paragraphs();
        $last = count($paragraphs) - 1;
        $height = 0;

        foreach ($paragraphs as $at => $entry) {
            $height += $this->entryLines($entry, $width) * $this->geometry->lineHeight($this->bodySize());

            if ($at < $last) {
                $height += $this->geometry->style()->blockGap();
            }
        }

        return $height;
    }

    /**
     * How many lines one paragraph of a frame takes.
     *
     * @param FrameParagraph $entry A paragraph, or one item of a list.
     */
    private function entryLines(Paragraph|array $entry, int $width): int
    {
        if ($entry instanceof Paragraph) {
            return $this->runLines($entry->runs, $width, $this->bodySize());
        }

        // A list item's text starts a gutter in for its marker, and one gutter more for each
        // level, which is the width the text above it was measured in too.
        return $this->runLines(
            $entry['runs'],
            $width - $entry['level'] * $this->geometry->style()->gutter(),
            $this->bodySize(),
        );
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
                    $this->pieces($row[$index] ?? [], SlideGeometry::TABLE_SIZE),
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
     *
     * The shape a drawable becomes, added to the slide.
     *
     * @param Box $box    Where it starts and how much of its box it has left.
     * @param int $height The height {@see self::height()} gave it.
     */
    public function write(Slide $slide, Code|TextFrame|Heading|Image|Quote|Rule|Table $drawable, Box $box, int $height): AbstractShape
    {
        $shape = match (true) {
            $drawable instanceof Code => $this->code($slide, $drawable, $box, $height),
            $drawable instanceof Table => $this->table($slide, $drawable, $box, $height),
            $drawable instanceof Image => $this->image($slide, $drawable, $box, $height),
            $drawable instanceof Rule => $this->rule($slide, $box),
            $drawable instanceof Heading => $this->heading($slide, $drawable, $box, $height),
            $drawable instanceof TextFrame => $this->textFrame($slide, $drawable, $box, $height),
            $drawable instanceof Quote => $this->quote($slide, $drawable, $box, $height),
        };

        $shape->setName($drawable->name());

        return $shape;
    }

    /**
     * A run of paragraphs and lists, as one shape holding one paragraph each.
     *
     * The frame is the unit a reader edits: it is drawn once, placed once, and PowerPoint
     * flows its paragraphs inside it.
     */
    private function textFrame(Slide $slide, TextFrame $frame, Box $box, int $height): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);

        $paragraphs = $frame->paragraphs();
        $last = count($paragraphs) - 1;
        $first = true;

        foreach ($paragraphs as $at => $entry) {
            // A fresh shape already carries one paragraph, and creating another for the first
            // would leave that empty one in the file as a blank line.
            $paragraph = $first ? $shape->getActiveParagraph() : $shape->createParagraph();
            $first = false;

            if ($entry instanceof Paragraph) {
                $this->runs($paragraph, $entry->runs, $this->bodySize());
            } else {
                $this->item($paragraph, $entry);
            }

            // `runs()` zeroes the spacing on every paragraph it fills, which is right for a
            // shape of one paragraph and wrong for a frame of several. The gap between two of
            // them is written here instead, and only where there is a paragraph under this
            // one to be separated from — a trailing one would be space the frame was never
            // measured for.
            if ($at < $last) {
                $paragraph->setSpacingAfter($this->geometry->paragraphSpace());
            }
        }

        return $shape;
    }

    private function quote(Slide $slide, Quote $block, Box $box, int $height): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);
        $shape->setOffsetX($box->x + $this->geometry->style()->gutter());
        $shape->setWidth($box->width - $this->geometry->style()->gutter());

        $this->runs(
            $shape->getActiveParagraph(),
            $block->runs,
            SlideGeometry::QUOTE_SIZE,
            italic: true,
            font: $this->subtleInk(),
        );

        return $shape;
    }

    private function heading(Slide $slide, Heading $block, Box $box, int $height): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);

        $size = $this->geometry->headingSize($block->level);
        $this->runs(
            $shape->getActiveParagraph(),
            $block->runs,
            $size,
            bold: $this->geometry->style()->headingIsBold(),
            font: $this->headingInk(),
            face: $this->font('heading'),
        );

        return $shape;
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
                if (!isset($cells[$index])) {
                    break;
                }

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

        // The room the block was given may have run out, so the box it is fitted to is
        // clamped before the ratio is read off it: a zero dimension disables the
        // proportional fit and hands the picture the negative room unchanged.
        $width = max($box->width, SlideGeometry::MIN_SIZE);
        $tall = max($height, SlideGeometry::MIN_SIZE);
        $shape->setWidthAndHeight($width, $tall);

        // Centred on both axes, because the slack is only ever on one of them: an image that
        // fills the box's width has a gap above and below and none at the sides. The offsets
        // are floored at the box because `setWidthAndHeight()` rounds the losing dimension
        // *up*, and centring that would push the picture past the block below it.
        $shape->setOffsetX($box->x + max(0, intdiv($width - $shape->getWidth(), 2)))
            ->setOffsetY($box->y + max(0, intdiv($tall - $shape->getHeight(), 2)));

        return $shape;
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
     * {@see TextFrame} puts the gap between its own paragraphs back on top of it, on every
     * paragraph that has another one under it.
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
                ->setSize($run->code ? SlideGeometry::CODE_SIZE : $size)
                ->setName($run->code ? $this->font('mono') : ($face ?? $this->font('body')))
                ->setBold($run->bold || $bold)
                ->setItalic($run->italic || $italic)
                ->setStrikethrough($run->strike ? StyleFont::STRIKE_SINGLE : StyleFont::STRIKE_NONE)
                // The link branch is asked first: `$font ?? …` short-circuited it, so a link
                // inside a heading or a quote was drawn in the heading's own colour and
                // looked like ordinary text that happened to be clickable.
                ->setColor($run->link === null ? $plain : $this->colour('hlink'));

            if ($run->link === null) {
                continue;
            }

            $element->getHyperlink()->setUrl($run->link);
            $element->getFont()->setUnderline(StyleFont::UNDERLINE_SINGLE);
        }
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
        return $this->theme->font($slot) ?? '';
    }
}
