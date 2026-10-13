<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Render\Block\Block;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\Style;

/**
 * Where each role puts its title and its body, and how big the type in each is.
 *
 * Every shipped theme names Office's own layouts and none of them ships a `template.pptx`,
 * so the shape of a slide is decided here rather than read out of a template. What the
 * theme *does* decide is the name each role's layout part carries, and that name travels
 * into the file: see {@see PresentationRenderer}.
 *
 * A theme that *does* ship a template gets its boxes from it instead: {@see self::with()}
 * takes another geometry's answers for the roles a template covers, and
 * {@see \MarkdownPresentation\Render\TemplateGeometry} is what reads them. The type sizes
 * and alignments stay here either way, because a template states them in a master rather
 * than in a layout and adopting a whole typographic system would resize the type of every
 * deck that used one.
 *
 * The numbers are fractions of a 960 × 540 slide, which is the unit {@see Box} holds. The
 * margins, the gutters and the type sizes are the theme's own ({@see Style}); everything
 * else is plain, because a layout that needs more than that is what `template.pptx` is for.
 *
 * The heights here are *budgets*: {@see ShapeWriter} asks {@see TextMetric} how many lines
 * a block actually takes and multiplies by {@see self::lineHeight()}, so what is left here
 * is only what does not depend on the text at all.
 */
final class SlideGeometry
{
    public const WIDTH = 960;
    public const HEIGHT = 540;

    /**
     * The margin left all the way round, in pixels, for a theme that names none.
     *
     * {@see Style::defaults()} is the authority — this is the same number, stated here as
     * well because the box arithmetic below is written against it and a reader should not
     * have to open another class to find out what a deck without a theme looks like.
     */
    public const MARGIN = 40;

    /** The gap that separates two things rather than enclosing them, by default. */
    public const GUTTER = 20;

    /** The size body text is drawn at, by default. */
    public const BODY_SIZE = 16;
    public const CODE_SIZE = 12;
    public const TABLE_SIZE = 12;
    public const QUOTE_SIZE = 15;

    /**
     * The height of one table row, in pixels.
     *
     * PHPPresentation's `Row` defaults to 38 and writes that as `<a:tr h>`, which
     * PowerPoint honours. {@see \MarkdownPresentation\Render\ShapeWriter} sets it on every
     * row it creates so that number is the one on both sides.
     */
    public const TABLE_ROW = 38;

    /**
     * The smallest a shape may be written at.
     *
     * `a:ext` is an `ST_PositiveCoordinate`, so a negative width or height is not a small
     * shape but an invalid one: PowerPoint offers to repair the file, or drops the shape
     * without saying so. A block laid out past the bottom of its box asked for exactly that,
     * because the room left went negative and both the size and the centring carried it on.
     */
    public const MIN_SIZE = 1;

    /**
     * The body insets PHPPresentation writes into every text shape, in pixels.
     *
     * 91440 EMU across and 45720 down, which is 9.6 and 4.8 at the 9525 EMU a pixel is.
     * Rounded up, because what these cost is the width text has left to wrap in, and
     * rounding down would measure against a box wider than the one the reader wraps in.
     */
    public const INSET_X = 10;
    public const INSET_Y = 5;

    /** The narrowest a table column is drawn, whatever its cells ask for. */
    public const TABLE_COLUMN = 80;

    /**
     * The height of the headline band of an `image-title` layout, in pixels.
     *
     * One line of type rather than the two a content slide's title box is given room for.
     * The headline here introduces a picture rather than sitting above a paragraph, and every
     * pixel of the band is a pixel of picture.
     */
    public const HEADLINE = 45;

    /**
     * The air inside a table cell, on the left and on the right.
     *
     * PHPPresentation writes a cell's `a:bodyPr` with no `lIns`/`rIns` at all, and
     * `Cell` carries no inset of its own to set, so a cell has none: the text in it starts
     * exactly at the cell's edge. Two neighbours then touch — a right-aligned cell and the
     * left-aligned one beside it meet with no gap between them and read as one word, and a
     * cell wider than its column shows its neighbour's text through it.
     *
     * The paragraph's own margins are what stands in for the inset, for the same reason a
     * list item's are: `marL` and `marR` move the text inside its box without moving the
     * box, and a right margin holds a right-aligned cell off its neighbour as a left margin
     * holds a left-aligned one off its.
     */
    public const TABLE_PADDING = 6;

    /**
     * The vertical room a rule occupies, the bar centred in it.
     *
     * The room rather than the bar: how much of a column a thematic break takes is a layout
     * decision, and how thick the bar drawn inside it is the theme's
     * ({@see Style::ruleWeight()}).
     */
    public const RULE_HEIGHT = 16;

    /**
     * The gap left between two blocks in a column, by default: the gap after a paragraph.
     *
     * This is the one number a theme sets for the rhythm of a slide, and it is
     * {@see Style::blockGap()} rather than anything here — every other gap is a multiple of
     * it, so a theme that widens the rhythm widens all of it at once. What the multiples are
     * is {@see self::spaceBelow()} and {@see self::spaceAbove()}.
     *
     * In the layout rather than in the shapes: a paragraph is written with
     * `setSpacingBefore(0)` and the last paragraph of a frame with `setSpacingAfter(0)`,
     * because {@see ShapeWriter::height()} decides where the next shape starts and a leading
     * gap it does not know about is what puts one shape on top of the one before it.
     *
     * One gap spent once: between two shapes of a column it is added to the cursor as they
     * are laid out, and between two paragraphs of one frame it is written as the paragraph's
     * own spacing after. A frame measured with the gap *and* written with it is a gap taller
     * than the text it separates.
     */
    public const BLOCK_GAP = 12;

    /**
     * The half-gap a paragraph leaves on either side of itself, as a fraction of the theme's
     * own.
     *
     * Halves rather than the whole gap on one side, because the air between two blocks is
     * {@see self::spaceBelow()} of the one above added to {@see self::spaceAbove()} of the one
     * below — PowerPoint adds a paragraph's spacing before and after rather than taking the
     * larger of the two, so a gap carried entirely on one side reads as a bigger gap above a
     * paragraph than below it, and a column stacked from those is a column whose rhythm
     * alternates. Two halves add back to the number the theme set, so a deck that says
     * nothing about its own rhythm is laid out exactly as it was.
     */
    private const PARAGRAPH_GAP = 0.5;

    /**
     * How much of the paragraph gap a list item leaves on either side of itself.
     *
     * Less, because a list is one block to the deck and several lines of type to the reader,
     * and it reads as a list by the air between its items being smaller than the air between
     * the things around it. {@see \MarkdownPresentation\Render\CommonMark\BlockTranslator}
     * numbers a list's items as paragraphs of the frame, so this is what a reader sees
     * between two of them rather than between two blocks.
     */
    private const LIST_GAP = 0.25;

    /**
     * How much of the paragraph gap a block with an edge of its own leaves on either side.
     *
     * More, and this is the gap that used to read as cramped: the ink of a paragraph stops
     * short of the bottom of its box, by the text inset and by the slack in the last line's
     * leading, while a table's border, a filled code block's panel and a rule's bar are drawn
     * exactly on the edge. Two blocks separated by the same number therefore read as further
     * apart when one of them is a paragraph than when one of them is a table, and the number
     * that suits a paragraph is short for a table.
     */
    private const SOLID_GAP = 0.9;

    /**
     * The air above and below a heading, in ems of the heading's own size.
     *
     * The one gap that is not a fraction of {@see Style::blockGap()}, because a heading is
     * not a bigger paragraph: the air it wants is a function of how big the type is, and a
     * 34-point heading under the same air as a 14-point one is either crowded or marooned.
     * More above than below, because a heading belongs to the text that follows it — which is
     * the same reason markdown-word writes a heading's `space before` at twice its
     * `space after` and `keepNext` on top of that.
     *
     * The two are not halves of one gap but two different ones, so that a reader sees a
     * heading sit closer to the text it introduces than to the text it replaced.
     */
    private const HEADING_ABOVE = 0.7;
    private const HEADING_BELOW = 0.35;

    /**
     * A point is 4/3 of a pixel at the 96 dpi {@see Box} counts in.
     *
     * Public because the gaps are stated in points — `a:spcPts` is a point measure — and both the
     * frame that writes them and the column that spends them as a cursor have to convert, and a
     * conversion one of them rederived is a conversion the two can disagree about.
     */
    public const PIXELS_PER_POINT = 4 / 3;

    /**
     * The boxes a template's own layouts gave the roles, on top of the ones below.
     *
     * @var array<string, array{title?: Box, body?: non-empty-list<Box>}>
     */
    private readonly array $boxes;

    /**
     * @param array<string, array{title?: Box, body?: non-empty-list<Box>}> $boxes Role to the
     *        boxes a template's layout gave it. A role left out keeps the numbers below.
     * @param Style                                                       $style  The numbers
     *        the theme sets: how much of the page the margins take, and how big the type in
     *        what is left is.
     */
    public function __construct(
        array $boxes = [],
        private readonly Style $style = new Style(),
    ) {
        $this->boxes = $boxes;
    }

    /**
     * A copy whose named roles take their boxes from a template.
     *
     * Merged rather than replacing what this already knows, so a template that covers only some
     * of the roles leaves the rest where this class puts them — the same rule
     * `layouts.yml` follows, where a theme that maps two of them is a theme that works.
     *
     * @param array<string, array{title?: Box, body?: non-empty-list<Box>}> $boxes
     */
    public function with(array $boxes): self
    {
        return new self(array_replace($this->boxes, $boxes), $this->style);
    }

    /** The numbers the theme sets: margins, gutters, gaps and the type scale. */
    public function style(): Style
    {
        return $this->style;
    }

    /** The width a title or a body box spans, in pixels: the page less a margin either side. */
    private function bodyWidth(): int
    {
        return self::WIDTH - 2 * $this->style->margin();
    }

    /**
     * A vertical measurement in the theme's own terms.
     *
     * Every band on the page is a multiple of the margin rather than a number of pixels, so
     * widening the margin moves the title, the body and the rule together instead of leaving
     * one of them where it was. The ratios are the ones the layout has always used at a
     * 40-pixel margin.
     */
    private function band(float $margins): int
    {
        return (int) round($this->style->margin() * $margins);
    }

    /**
     * A band as tall as `$lines` lines of type this size takes, and never shorter than the
     * margin-derived band it stands in for.
     *
     * A title box of a fixed number of pixels holds the library's own title size and nothing
     * else: a theme asking for a forty-point title gets a box sized for a twenty-eight-point
     * one, and `a:normAutofit` shrinks the type back down to what fits. Scaling the band with
     * the type is what makes a theme's type scale mean anything on a title rather than only
     * in a body.
     */
    private function headingBand(int $size, int $lines): int
    {
        return max($this->band(1.8), $lines * $this->lineHeight($size));
    }

    /**
     * The title box of a role, the size of its type in points, and the alignment it uses.
     *
     * Null for a role with no title box — `blank` and `image-slide` — which is
     * {@see LayoutRole::hasTitleBox()} asked from here. A layout that drew a title anyway
     * would not be the layout it is, and the way to put a title on such a slide is to write
     * a heading in its body. That answer is this class's rather than a template's: a
     * template whose `blank` layout happens to carry a title placeholder does not turn the
     * role into one that has a title.
     *
     * @return ?array{box: Box, size: int, alignment: string}
     */
    public function title(LayoutRole $role): ?array
    {
        $margin = $this->style->margin();
        $width = $this->bodyWidth();

        $title = match ($role) {
            LayoutRole::Title => [
                'box' => new Box($margin, $this->band(4.5), $width, $this->band(3)),
                'size' => $this->style->cover(),
                'alignment' => 'l',
            ],
            LayoutRole::Content, LayoutRole::TwoColumn, LayoutRole::ImageText => [
                'box' => new Box($margin, $this->band(0.6), $width, min(
                    $this->headingBand($this->style->title(), 2),
                    $this->titleRoom(),
                )),
                'size' => $this->style->title(),
                'alignment' => 'l',
            ],
            // One line, not a wrapping block: the headline here introduces a picture rather
            // than sitting above a paragraph, and the height it takes is the height of a line
            // of it. {@see self::HEADLINE} is what the body is measured from.
            LayoutRole::ImageTitle => [
                'box' => new Box($margin, $this->band(0.6), $width, self::HEADLINE),
                'size' => $this->style->title(),
                'alignment' => 'l',
            ],
            LayoutRole::Section => [
                'box' => new Box($margin, $this->band(5), $width, $this->band(3.5)),
                'size' => $this->style->section(),
                'alignment' => 'ctr',
            ],
            LayoutRole::Blank, LayoutRole::ImageSlide => null,
        };

        if ($title === null) {
            return null;
        }

        $title['box'] = $this->boxes[$role->value]['title'] ?? $title['box'];

        return $title;
    }

    /**
     * Where the body of every role that draws a title above it starts, in pixels.
     *
     * A band of its own rather than the title's bottom, because the gap under a title is air
     * and air is a rhythm rather than a measurement of the type above it. Stating it as a
     * multiple of the margin is what makes a theme that widens its margin widen the space
     * under the title too, rather than leaving the body where it was and pushing the type
     * into it.
     */
    private function bodyTop(): int
{
    return $this->band(2.6);
}

    /**
     * The room a title has above the body, in pixels: the band {@see self::bodyTop()} leaves,
     * less the air the title itself is drawn with.
     *
     * A clamp rather than the flow markdown-word does, because a slide's body is read in the
     * same place on every slide and a body that moves with the length of the title above it
     * makes a deck jump as it advances. Two boxes is the model; this is what keeps the one
     * above from growing into the one below.
     *
     * It was needed because the two numbers were computed without reference to each other.
     * {@see self::headingBand()} asks for two lines of the role's own title size, which at the
     * library's numbers is 90 pixels, and the band a content slide leaves for it is 80 — so the
     * title box ended ten pixels below the top of the body box before this existed, and a title
     * that wrapped put its second line on the body's first. `normAutofit` cannot repair that:
     * both boxes were written at a size the layout had chosen, and the size was the wrong one.
     *
     * {@see self::headingBand()}'s margin-derived floor is not consulted on the way, or a theme
     * with a wide margin would carry the overlap straight through the clamp: the floor is what
     * the band is when the title is small, not a minimum the title may not go below.
     */
    private function titleRoom(): int
{
    return max(1, $this->bodyTop() - $this->band(0.6) - $this->style->blockGap());
}

    /**
     * The body boxes of a role, in reading order. `two-column` and `image-text` give two
     * because that is what they are for; every other role gives one.
     *
     * @return non-empty-list<Box>
     */
    public function body(LayoutRole $role): array
    {
        $margin = $this->style->margin();
        $width = $this->bodyWidth();
        $top = $this->bodyTop();

        return $this->boxes[$role->value]['body'] ?? match ($role) {
            LayoutRole::TwoColumn => $this->columns($top, 1, 1),
            // The picture leads and the text follows it, so the wider column goes to the
            // picture: a reader looks at the image first, and text set into the narrower one
            // beside it is text beside a picture rather than a picture beside text.
            //
            // Even rather than the 3:2 a picture would like, though: {@see ShapeWriter} estimates
            // wrapping from a table of Helvetica's widths and a theme's own face is wider, so a
            // column narrow enough to need the estimate to be exactly right is a column where
            // two paragraphs are drawn on top of each other. 5:4 leaves the estimate slack.
            LayoutRole::ImageText => $this->columns($top, 5, 4),
            LayoutRole::Title => [new Box($margin, $this->band(8), $width, $this->band(4.5))],
            LayoutRole::Section => [new Box($margin, $this->band(9), $width, $this->band(3.5))],
            // A blank layout has no title, so its body starts at the margin and gets the whole
            // height — which is the only thing that distinguishes it from a content slide.
            LayoutRole::Blank => [new Box($margin, $margin, $width, self::HEIGHT - 2 * $margin)],
            LayoutRole::Content => [new Box($margin, $top, $width, self::HEIGHT - $margin - $top)],
            // A headline sits in a band of its own, and the picture takes what is left of the
            // page under it — which is the whole of what separates this from a content slide:
            // a shorter headline, and everything below it belongs to the picture.
            LayoutRole::ImageTitle => [$this->below(new Box(
                $margin,
                $this->band(0.6),
                $width,
                self::HEADLINE,
            ))],
            // Full bleed: a slide whose whole content is one picture has no margin to keep, and
            // the gutter that separates two columns has nothing left to separate.
            LayoutRole::ImageSlide => [new Box(0, 0, self::WIDTH, self::HEIGHT)],
        };
    }

    /**
     * The box below a headline: the rest of the page, less the margins either side of it.
     *
     * A second method rather than the arithmetic inline because `image-title` is the only
     * role whose body starts somewhere other than the top of the content body, and the
     * arithmetic is the kind that is worth being able to read in one place.
     */
    private function below(Box $headline): Box
    {
        $top = $headline->y + $headline->height + $this->style->blockGap();

        return new Box($headline->x, $top, $headline->width, self::HEIGHT - $this->style->margin() - $top);
    }

    /**
     * Two columns across the body width, in the ratio `$left`:`$right`, separated by the
     * theme's gutter and both running from under a content slide's title to the bottom margin.
     *
     * The same gutter every other indentation uses, so two columns are as far apart as a
     * bullet is from its text rather than a gap of their own. The right column takes the
     * remainder rather than its own share of the total, because a column ending a pixel short
     * of the margin is a ragged edge nothing on the page explains.
     *
     * @return non-empty-list<Box>
     */
    private function columns(int $top, int $left, int $right): array
    {
        $margin = $this->style->margin();
        $gutter = $this->style->gutter();
        $available = $this->bodyWidth() - $gutter;
        $left = intdiv($available * $left, $left + $right);
        $height = self::HEIGHT - $margin - $top;

        return [
            new Box($margin, $top, $left, $height),
            new Box($margin + $left + $gutter, $top, $available - $left, $height),
        ];
    }

    /**
     * The size of a heading in a slide's body, by its depth.
     *
     * The theme's own scale, whose last entry is the smallest of it: a heading deeper than the
     * scale runs takes that size rather than failing, which is what keeps a document CommonMark
     * accepts from being refused over the depth of one heading in it.
     */
    public function headingSize(int $level): int
    {
        $headings = $this->style->headings();

        return $headings[$level - 1] ?? $headings[count($headings) - 1];
    }

    /**
     * The size a slide's title is drawn at when its frontmatter named a level for it.
     *
     * `1` is the role's own title size and `2` is the size a `##` in that role's body would
     * get, so a title named through the frontmatter reads the same as one written as a
     * heading — which is the whole of what `level:` is for: a title that is not a heading.
     */
    public function titleSize(int $size, int $level): int
    {
        return $level <= 1 ? $size : $this->headingSize($level - 1);
    }

    /**
     * The height of one line of type this size, in pixels.
     *
     * One method rather than a constant per block: a paragraph, a heading, a quote and a
     * table cell are each one line of type, and the four constants this replaced were four
     * places for the same number to be wrong in. The theme's leading is the multiple, because
     * the space between two lines is as much a theme's decision as the size of them.
     */
    public function lineHeight(int $size): int
    {
        return (int) round($size * self::PIXELS_PER_POINT * $this->style->leading());
    }

    /**
     * The air left above a block, in points.
     *
     * Points rather than pixels because this is also what `a:spcBef` is written in, and the gap
     * between two paragraphs of one frame and the gap between two shapes of a column are one
     * number spent in two places — spent by PowerPoint inside a frame and by the cursor outside
     * one. {@see ShapeWriter} measures with this and writes with this, which is what keeps the
     * two from disagreeing by the gap.
     *
     * A frame takes the air of the block at whichever end is facing the gap: the paragraph above
     * a frame that opens with a heading has to clear the heading, and the paragraph below one
     * that closes with a list has to clear the list.
     *
     * {@see \MarkdownPresentation\Render\Block\Block} rather than one type per block, because
     * the answer is a property of the kind of thing and the kinds are the ones that already
     * exist — a tenth kind of block is not a new arm here but a new match in the geometry.
     */
    public function spaceAbove(Block|TextFrame $block): float
{
    return match (true) {
        $block instanceof TextFrame => $this->spaceAbove($block->leading()),
        $block instanceof Heading => self::HEADING_ABOVE * $this->headingSize($block->level),
        $block instanceof ListBlock => $this->block() * self::LIST_GAP,
        $block instanceof Quote => $this->half(),
        $block instanceof Table, $block instanceof Code,
        $block instanceof Rule, $block instanceof Image => $this->solid(),
        default => $this->half(),
    };
}

    /**
     * The air left below a block, in points. The same answer as {@see self::spaceBelow()} read
     * the other way round the pair: what is written as this paragraph's `a:spcAft` inside a
     * frame, and what the cursor adds after the shape outside one.
     */
    public function spaceBelow(Block|TextFrame $block): float
{
    return match (true) {
        $block instanceof TextFrame => $this->spaceBelow($block->trailing()),
        $block instanceof Heading => self::HEADING_BELOW * $this->headingSize($block->level),
        $block instanceof ListBlock => $this->block() * self::LIST_GAP,
        $block instanceof Quote => $this->half(),
        $block instanceof Table, $block instanceof Code,
        $block instanceof Rule, $block instanceof Image => $this->solid(),
        default => $this->half(),
    };
}

    /**
     * The gap after a paragraph, in points, which is the one the theme sets.
     */
    private function block(): float
{
    return $this->style->blockGap() / self::PIXELS_PER_POINT;
}

    /**
     * The air between two blocks, in pixels.
     *
     * {@see self::spaceBelow()} and {@see self::spaceAbove()} added and converted, so that the
     * gap between two shapes of a column and the gap between two paragraphs of a frame are one
     * number rather than two that agree today: the frame writes the two halves as `a:spcAft` and
     * `a:spcBef` and the column spends this on the cursor, and a gap measured as one and drawn
     * as the other is a gap of the wrong size in whichever of the two is not derived from here.
     *
     * A float rather than an int because the halves are points — `a:spcPts` is a point measure —
     * and a theme's block gap divided by four thirds is not a whole number of pixels.
     */
    public function gapBetween(Block|TextFrame $above, Block|TextFrame $below): float
{
    return ($this->spaceBelow($above) + $this->spaceAbove($below)) * self::PIXELS_PER_POINT;
}

    /**
     * What a paragraph leaves on one side of itself, in points.
     */
    private function half(): float
{
    return $this->block() * self::PARAGRAPH_GAP;
}

    /**
     * What a block with an edge of its own leaves on one side of itself, in points.
     */
    private function solid(): float
{
    return $this->block() * self::SOLID_GAP;
}

}
