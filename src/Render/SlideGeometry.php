<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Theme\LayoutRole;

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
 * The numbers are fractions of a 960 × 540 slide, which is the unit {@see Box} holds. They
 * are deliberately plain — a 40 pixel margin all the way round and everything left over for
 * content — because a layout that needs more than that is what `template.pptx` is for.
 *
 * The heights here are *budgets*: {@see ShapeWriter} asks {@see TextMetric} how many lines
 * a block actually takes and multiplies by {@see self::lineHeight()}, so what is left here
 * is only what does not depend on the text at all.
*/
final class SlideGeometry
{
    public const WIDTH = 960;
    public const HEIGHT = 540;
    public const MARGIN = 40;
    public const GUTTER = 20;

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

    /** The vertical room a rule occupies, the two-pixel bar centred in it. */
    public const RULE_HEIGHT = 16;

    /**
     * The gap left between two blocks in a column.
     *
     * In the layout rather than in the shapes: every paragraph is written with
     * `setSpacingBefore(0)` and `setSpacingAfter(0)`, because {@see ShapeWriter::height()}
     * decides where the next block starts and a leading gap it does not know about is what
     * puts one shape on top of the one before it. A gap the heights do know about is the
     * only kind that survives.
     */
    public const BLOCK_GAP = 12;

    private const BODY_WIDTH = self::WIDTH - 2 * self::MARGIN;

    /** A point is 4/3 of a pixel at the 96 dpi {@see Box} counts in. */
    private const PIXELS_PER_POINT = 4 / 3;

    /** The multiple of a line's own height that one line of text is drawn at. */
    private const LINE_SPACING = 1.2;

    /** A heading one level deeper than a content slide's title, down to the sixth. */
    private const HEADING_SIZES = [22, 20, 18, 16, 14];

    /**
     * The boxes a template's own layouts gave the roles, on top of the ones below.
     *
     * @var array<string, array{title?: Box, body?: non-empty-list<Box>}>
     */
    private readonly array $boxes;

    /**
     * @param array<string, array{title?: Box, body?: non-empty-list<Box>}> $boxes Role to the
     *        boxes a template's layout gave it. A role left out keeps the numbers below.
     */
    public function __construct(array $boxes = [])
    {
        $this->boxes = $boxes;
    }

    /**
     * A copy whose named roles take their boxes from a template.
     *
     * Merged rather than replacing what this already knows, so a template that covers two of
     * the five roles leaves the other three where this class puts them — the same rule
     * `layouts.yml` follows, where a theme mapping two of the five is a theme that works.
     *
     * @param array<string, array{title?: Box, body?: non-empty-list<Box>}> $boxes
     */
    public function with(array $boxes): self
    {
        return new self(array_replace($this->boxes, $boxes));
    }

    /**
     * The title box of a role, the size of its type in points, and the alignment it uses.
     *
     * Null for `blank`, which has no title: a blank layout that drew one would not be blank,
     * and the way to put a title on such a slide is to write a heading in its body. That
     * answer is this class's rather than a template's — a template whose `blank` layout
     * happens to carry a title placeholder does not turn the role into one that has a title.
     *
     * @return ?array{box: Box, size: int, alignment: string}
    */
    public function title(LayoutRole $role): ?array
    {
        $title = match ($role) {
            LayoutRole::Title => ['box' => new Box(self::MARGIN, 180, self::BODY_WIDTH, 120), 'size' => 40, 'alignment' => 'l'],
            LayoutRole::Content, LayoutRole::TwoColumn => ['box' => new Box(self::MARGIN, 24, self::BODY_WIDTH, 72), 'size' => 28, 'alignment' => 'l'],
            LayoutRole::Section => ['box' => new Box(self::MARGIN, 200, self::BODY_WIDTH, 140), 'size' => 44, 'alignment' => 'ctr'],
            LayoutRole::Blank => null,
        };

        if ($title === null) {
            return null;
        }

        $title['box'] = $this->boxes[$role->value]['title'] ?? $title['box'];

        return $title;
    }

    /**
     * The body boxes of a role, in reading order. `two-column` gives two because that is what
     * it is for; every other role gives one.
     *
     * @return non-empty-list<Box>
    */
    public function body(LayoutRole $role): array
    {
        return $this->boxes[$role->value]['body'] ?? match ($role) {
            LayoutRole::TwoColumn => [
                new Box(self::MARGIN, 104, intdiv(self::BODY_WIDTH - self::GUTTER, 2), 396),
                new Box(self::MARGIN + intdiv(self::BODY_WIDTH + self::GUTTER, 2), 104, intdiv(self::BODY_WIDTH - self::GUTTER, 2), 396),
            ],
            LayoutRole::Title => [new Box(self::MARGIN, 320, self::BODY_WIDTH, 180)],
            LayoutRole::Section => [new Box(self::MARGIN, 360, self::BODY_WIDTH, 140)],
            // A blank layout has no title, so its body starts at the margin and gets the whole
            // height — which is the only thing that distinguishes it from a content slide.
            LayoutRole::Blank => [new Box(self::MARGIN, self::MARGIN, self::BODY_WIDTH, self::HEIGHT - 2 * self::MARGIN)],
            LayoutRole::Content => [new Box(self::MARGIN, 104, self::BODY_WIDTH, 396)],
        };
    }

    /**
     * The size of a heading in a slide's body, by its depth.
     *
     * The sixth is the last entry, so a heading deeper than the Markdown has levels for
     * would take the smallest size there is rather than fail.
    */
    public function headingSize(int $level): int
    {
        return self::HEADING_SIZES[$level - 1] ?? self::HEADING_SIZES[count(self::HEADING_SIZES) - 1];
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
     * places for the same number to be wrong in.
     */
    public function lineHeight(int $size): int
    {
        return (int) round($size * self::PIXELS_PER_POINT * self::LINE_SPACING);
    }

    /**
     * The height a heading of this size takes for this many lines, in pixels.
     */
    public function headingHeight(int $level, int $lines): int
    {
        return $lines * $this->lineHeight($this->headingSize($level));
    }
}
