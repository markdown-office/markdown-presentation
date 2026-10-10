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
    public function __construct(
        private readonly Theme $theme,
        private readonly SlideGeometry $geometry = new SlideGeometry(),
    ) {
    }

    /**
     *
     * How tall a block is, in pixels. `$room` is how much of its box is left, which only an
     * image consults: an image takes what a paragraph and a table would not.
     *
     * The union rather than `Block`, because it is exhaustive: a ninth kind of block is not
     * something this method can guess a height for, and naming the eight is what says so at
     * the type rather than at run time.
     *
     * @param Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table $block
    */
    public function height(Block $block, int $room): int
    {
        return match (true) {
            $block instanceof Heading => $this->geometry->headingHeight($block->level),
            $block instanceof Code => count($block->lines) * SlideGeometry::CODE_LINE + 2 * SlideGeometry::CODE_PADDING,
            $block instanceof ListBlock => count($block->items) * SlideGeometry::BODY_LINE,
            $block instanceof Table => count($block->cells) * SlideGeometry::TABLE_ROW,
            $block instanceof Rule => SlideGeometry::RULE_HEIGHT,
            $block instanceof Image => $room,
            $block instanceof Quote => $this->lines($block->runs),
            $block instanceof Paragraph => $this->lines($block->runs),
        };
    }

    /**
     *
     * The height of a run of text, in lines.
     *
     * A paragraph with no line break in it is one line and not none, which is what the `max`
     * is for.
     *
     * @param list<Run> $runs
    */
    private function lines(array $runs): int
    {
        return max(1, substr_count(self::text($runs), "\n") + 1) * SlideGeometry::BODY_LINE;
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
        $shape = $slide->createRichTextShape();
        $this->place($shape, $box, $height);
        $this->runs($shape, $block->runs, SlideGeometry::BODY_SIZE);

        return $shape;
    }

    private function quote(Slide $slide, Quote $block, Box $box, int $height): RichText
    {
        $shape = $slide->createRichTextShape();
        $this->place($shape, $box, $height);
        $shape->setOffsetX($box->x + SlideGeometry::GUTTER);
        $shape->setWidth($box->width - SlideGeometry::GUTTER);

        $this->runs($shape, $block->runs, SlideGeometry::QUOTE_SIZE, italic: true, font: $this->colour('dk2'));

        return $shape;
    }

    private function heading(Slide $slide, Heading $block, Box $box, int $height): RichText
    {
        $shape = $slide->createRichTextShape();
        $this->place($shape, $box, $height);

        $size = $this->geometry->headingSize($block->level);
        $this->runs($shape, $block->runs, $size, bold: true, font: $this->colour('dk1'), face: $this->font('heading'));

        return $shape;
    }

    private function list(Slide $slide, ListBlock $block, Box $box, int $height): RichText
    {
        $shape = $slide->createRichTextShape();
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

            $this->runs($shape, $item['runs'], SlideGeometry::BODY_SIZE, into: $paragraph);
        }

        return $shape;
    }

    private function code(Slide $slide, Code $block, Box $box, int $height): RichText
    {
        $shape = $slide->createRichTextShape();
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
        $columns = max(1, count($block->cells[0] ?? []));
        $shape = $slide->createTableShape($columns);
        $this->place($shape, $box, $height);
        $shape->setDescription(implode(',', $block->align));

        foreach ($block->cells as $row) {
            // The height the shape was measured with, set on the row as well: PHPPresentation
            // writes `<a:tr h>` from the row rather than from the shape, so a row left at its
            // own default is drawn at a height the rest of the layout never accounted for.
            $cells = $shape->createRow()->setHeight(SlideGeometry::TABLE_ROW)->getCells();

            foreach ($row as $index => $text) {
                if (!isset($cells[$index])) {
                    break;
                }

                $cell = $cells[$index];
                $cell->setWidth(intdiv($box->width, $columns));
                $paragraph = $cell->getActiveParagraph();
                $paragraph->getAlignment()->setHorizontal($this->alignment($block->align[$index] ?? 'left'));
                $paragraph->setBulletStyle($this->bullet(Bullet::TYPE_NONE));
                $paragraph->setSpacingBefore(0);
                $paragraph->setSpacingAfter(0);
                $paragraph->getFont()->setSize(SlideGeometry::TABLE_SIZE)
                    ->setName($this->font('body'))
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
        $shape->setWidthAndHeight($box->width, $height);

        // Centred on both axes, because the slack is only ever on one of them: an image that
        // fills the box's width has a gap above and below and none at the sides.
        $shape->setOffsetX($box->x + intdiv($box->width - $shape->getWidth(), 2))
            ->setOffsetY($box->y + intdiv($height - $shape->getHeight(), 2));

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
    */
    public function title(Slide $slide, string $text, Box $box, int $size, string $alignment): RichText
    {
        $shape = $slide->createRichTextShape();
        $this->place($shape, $box, $box->height);
        $shape->setName('title');
        $shape->getActiveParagraph()->getAlignment()->setHorizontal($alignment);
        $shape->createTextRun($text)->getFont()
            ->setBold(true)
            ->setSize($size)
            ->setName($this->font('heading'))
            ->setColor($this->colour('dk1'));
        $shape->getActiveParagraph()->setSpacingBefore(0);
        $shape->getActiveParagraph()->setSpacingAfter(0);

        return $shape;
    }

    private function place(AbstractShape $shape, Box $box, int $height): void
    {
        $shape->setOffsetX($box->x)->setOffsetY($box->y)->setWidth($box->width)->setHeight($height);
    }

    /**
     *
     * The runs of one paragraph, in a shape that may already have paragraphs above them.
     *
     * The first paragraph of a shape is the one `getActiveParagraph()` points at, and
     * `createParagraph()` only ever makes a later one, so the caller says which paragraph
     * its runs belong to rather than the writer guessing.
     *
     * Spacing is zeroed here rather than left at PowerPoint's default because
     * {@see self::height()} decides where the next block starts, and a leading gap the
     * heights do not know about is what puts one shape on top of the one before it.
     *
     * @param list<Run> $runs
    */
    private function runs(
        RichText $shape,
        array $runs,
        int $size,
        bool $bold = false,
        bool $italic = false,
        ?TextParagraph $into = null,
        ?Color $font = null,
        ?string $face = null,
    ): void {
        $paragraph = $into ?? $shape->getActiveParagraph();
        $paragraph->setSpacingBefore(0);
        $paragraph->setSpacingAfter(0);

        foreach ($runs as $run) {
            $element = $paragraph->createTextRun($run->text);
            $element->getFont()
                ->setSize($run->code ? SlideGeometry::CODE_SIZE : $size)
                ->setName($run->code ? $this->font('mono') : ($face ?? $this->font('body')))
                ->setBold($run->bold || $bold)
                ->setItalic($run->italic || $italic)
                ->setColor($font ?? $this->colour($run->link === null ? 'dk1' : 'hlink'));

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

    /**
     * @param list<Run> $runs
    */
    private static function text(array $runs): string
    {
        return implode('', array_map(static fn (Run $run): string => $run->text, $runs));
    }
}