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
 * Every shape is named after the kind of block it came from, so the whole mapping can be
 * read off `ppt/slides/slideN.xml` without opening the library.
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
     * How tall a block is, in pixels. `$room` is how much of its box is left, which only an
     * image consults: an image takes what a paragraph and a table would not.
     *
     * `$box` is the column the block is laid out in rather than the block's own box: the
     * width text wraps in is the column's, and a block is measured before it has a box.
     *
     * The union rather than `Block`, because it is exhaustive: a ninth kind of block is not
     * something this method can guess a height for, and naming the eight is what says so at
     * the type rather than at run time.
     *
     * @param Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table $block
     */
    public function height(Block $block, Box $box, int $room): int
    {
        $width = $this->textWidth($box);

        return match (true) {
            $block instanceof Heading => $this->geometry->headingHeight(
                $block->level,
                $this->runLines($block->runs, $width, $this->geometry->headingSize($block->level), bold: true),
            ),
            $block instanceof Code => $this->codeHeight($block, $width),
            $block instanceof ListBlock => $this->listHeight($block, $box),
            $block instanceof Table => $this->tableHeight($block, $box),
            $block instanceof Rule => SlideGeometry::RULE_HEIGHT,
            $block instanceof Image => $room,
            $block instanceof Quote => $this->runLines(
                $block->runs,
                $width - SlideGeometry::GUTTER,
                SlideGeometry::QUOTE_SIZE,
            ) * $this->geometry->lineHeight(SlideGeometry::QUOTE_SIZE),
            $block instanceof Paragraph => $this->runLines($block->runs, $width, SlideGeometry::BODY_SIZE)
                * $this->geometry->lineHeight(SlideGeometry::BODY_SIZE),
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
     * @param list<Run> $runs
     */
    private function runLines(array $runs, int $width, int $size, bool $mono = false, bool $bold = false): int
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

        return $this->metric->lines($pieces, $width);
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

    private function listHeight(ListBlock $block, Box $box): int
    {
        $lines = 0;

        foreach ($block->items as $item) {
            $lines += $this->runLines(
                $item['runs'],
                $this->textWidth($box) - $item['level'] * SlideGeometry::GUTTER,
                SlideGeometry::BODY_SIZE,
            );
        }

        return $lines * $this->geometry->lineHeight(SlideGeometry::BODY_SIZE);
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

        foreach ($block->cells as $row) {
            $lines = 1;

            foreach ($widths as $index => $width) {
                $lines = max($lines, $this->metric->lines(
                    [TextMetric::piece($row[$index] ?? '', SlideGeometry::TABLE_SIZE)],
                    $width,
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
                $widest = max($widest, $this->metric->width([
                    TextMetric::piece($row[$index] ?? '', SlideGeometry::TABLE_SIZE),
                ]));
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
     * The shape a block becomes, added to the slide.
     *
     * @param Box $box    Where the block starts and how much of its box it has left.
     * @param int $height The height {@see self::height()} gave it.
     *
     * @param Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table $block
    */
    public function write(Slide $slide, Block $block, Box $box, int $height): AbstractShape
    {
        $shape = match (true) {
            $block instanceof Code => $this->code($slide, $block, $box, $height),
            $block instanceof Table => $this->table($slide, $block, $box, $height),
            $block instanceof Image => $this->image($slide, $block, $box, $height),
            $block instanceof Rule => $this->rule($slide, $box),
            $block instanceof Heading => $this->heading($slide, $block, $box, $height),
            $block instanceof ListBlock => $this->list($slide, $block, $box, $height),
            $block instanceof Quote => $this->quote($slide, $block, $box, $height),
            $block instanceof Paragraph => $this->paragraph($slide, $block, $box, $height),
        };

        $shape->setName($block->name());

        return $shape;
    }

    private function paragraph(Slide $slide, Paragraph $block, Box $box, int $height): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);
        $this->runs($shape->getActiveParagraph(), $block->runs, SlideGeometry::BODY_SIZE);

        return $shape;
    }

    private function quote(Slide $slide, Quote $block, Box $box, int $height): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);
        $shape->setOffsetX($box->x + SlideGeometry::GUTTER);
        $shape->setWidth($box->width - SlideGeometry::GUTTER);

        $this->runs(
            $shape->getActiveParagraph(),
            $block->runs,
            SlideGeometry::QUOTE_SIZE,
            italic: true,
            font: $this->colour('dk2'),
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
            bold: true,
            font: $this->colour('dk1'),
            face: $this->font('heading'),
        );

        return $shape;
    }

    private function list(Slide $slide, ListBlock $block, Box $box, int $height): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);

        $first = true;

        foreach ($block->items as $item) {
            // A fresh shape already carries one paragraph, and creating another for the
            // first item would leave that empty one in the file as a blank line.
            $paragraph = $first ? $shape->getActiveParagraph() : $shape->createParagraph();
            $first = false;

            $paragraph->setBulletStyle($this->bullet(
                $block->ordered ? Bullet::TYPE_NUMERIC : Bullet::TYPE_BULLET,
            ));

            $paragraph->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $paragraph->getAlignment()->setMarginLeft($item['level'] * SlideGeometry::GUTTER);
            $paragraph->getAlignment()->setIndent(-SlideGeometry::GUTTER);

            $this->runs($paragraph, $item['runs'], SlideGeometry::BODY_SIZE);
        }

        return $shape;
    }

    private function code(Slide $slide, Code $block, Box $box, int $height): RichText
    {
        $shape = $this->textBox($slide);
        $this->place($shape, $box, $height);
        $shape->setDescription($block->language);
        $shape->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->colour('lt2'));

        $first = true;

        foreach ($block->lines as $line) {
            $paragraph = $first ? $shape->getActiveParagraph() : $shape->createParagraph();
            $first = false;

            $paragraph->setBulletStyle($this->bullet(Bullet::TYPE_NONE));
            $paragraph->setSpacingBefore(0);
            $paragraph->setSpacingAfter(0);
            $paragraph->getFont()->setSize(SlideGeometry::CODE_SIZE)
                ->setName($this->font('mono'))
                ->setColor($this->colour('dk2'));

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
                $created->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($this->colour('lt2'));
            }

            $cells = $created->getCells();

            foreach ($row as $index => $text) {
                if (!isset($cells[$index])) {
                    break;
                }

                $cell = $cells[$index];
                $cell->setWidth($columns[$index]);
                $paragraph = $cell->getActiveParagraph();
                $paragraph->getAlignment()->setHorizontal($this->alignment($block->align[$index] ?? 'left'));
                $paragraph->setBulletStyle($this->bullet(Bullet::TYPE_NONE));
                $paragraph->setSpacingBefore(0);
                $paragraph->setSpacingAfter(0);
                $paragraph->getFont()->setSize(SlideGeometry::TABLE_SIZE)
                    ->setName($this->font('body'))
                    ->setBold($number === self::HEADER_ROW)
                    ->setColor($this->colour('dk1'));

                $cell->createTextRun($text);
            }
        }

        return $shape;
    }

    private function image(Slide $slide, Image $block, Box $box, int $height): AbstractDrawingAdapter
    {
        $converted = $block->converted;
        $shape = $converted === null ? $this->fromFile($slide, $block) : $this->fromBytes($slide, $converted);
        $shape->setDescription($block->written);

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
        $shape->setOffsetX($box->x)
            ->setOffsetY($box->y + intdiv(SlideGeometry::RULE_HEIGHT, 2))
            ->setWidth($box->width)
            ->setHeight(2);
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
            bold: true,
            font: $this->colour('dk1'),
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
     * {@see self::height()} decides where the next block starts, and a leading gap the
     * heights do not know about is what puts one shape on top of the one before it.
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

        foreach ($runs as $run) {
            $element = $paragraph->createTextRun($run->text);
            $element->getFont()
                ->setSize($run->code ? SlideGeometry::CODE_SIZE : $size)
                ->setName($run->code ? $this->font('mono') : ($face ?? $this->font('body')))
                ->setBold($run->bold || $bold)
                ->setItalic($run->italic || $italic)
                // The link branch is asked first: `$font ?? …` short-circuited it, so a link
                // inside a heading or a quote was drawn in the heading's `dk1` or the quote's
                // `dk2` and looked like ordinary text that happened to be clickable.
                ->setColor($run->link === null ? ($font ?? $this->colour('dk1')) : $this->colour('hlink'));

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
    */
    private function bullet(string $type): Bullet
    {
        $bullet = new Bullet();
        $bullet->setBulletType($type)
            ->setBulletChar('•')
            ->setBulletNumericStyle(Bullet::NUMERIC_ARABICPERIOD)
            ->setBulletColor($this->colour('accent1'));

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

    private function colour(string $slot): Color
    {
        return new Color(ltrim($this->theme->colour($slot) ?? '#000000', '#'));
    }

    private function font(string $slot): string
    {
        return $this->theme->font($slot) ?? '';
    }
}
