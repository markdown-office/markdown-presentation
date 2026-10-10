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
 * The numbers are fractions of a 960 × 540 slide, which is the unit {@see Box} holds. They
 * are deliberately plain — a 40 pixel margin all the way round and everything left over for
 * content — because a layout that needs more than that is what `template.pptx` is for.
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

    /** The height of one line of body text, in pixels. */
    public const BODY_LINE = 26;

    /** The height of one line of code, which is smaller and tighter than body text. */
    public const CODE_LINE = 18;

    /** The height of one table row, including the space either side of its text. */
    public const TABLE_ROW = 26;

    /** What a rule costs, in height, once the gap above it is counted. */
    public const RULE_HEIGHT = 16;

    /** The padding a code block carries inside its fill. */
    public const CODE_PADDING = 12;

    private const BODY_WIDTH = self::WIDTH - 2 * self::MARGIN;

    /** A heading one level deeper than a content slide's title, down to the sixth. */
    private const HEADING_SIZES = [22, 20, 18, 16, 14];

    /**
     * The title box of a role, the size of its type in points, and the alignment it uses.
     *
     * Null for `blank`, which has no title: a blank layout that drew one would not be blank,
     * and the way to put a title on such a slide is to write a heading in its body.
     *
     * @return ?array{box: Box, size: int, alignment: string}
    */
    public function title(LayoutRole $role): ?array
    {
        return match ($role) {
            LayoutRole::Title => ['box' => new Box(self::MARGIN, 180, self::BODY_WIDTH, 120), 'size' => 40, 'alignment' => 'l'],
            LayoutRole::Content, LayoutRole::TwoColumn => ['box' => new Box(self::MARGIN, 24, self::BODY_WIDTH, 72), 'size' => 28, 'alignment' => 'l'],
            LayoutRole::Section => ['box' => new Box(self::MARGIN, 200, self::BODY_WIDTH, 140), 'size' => 44, 'alignment' => 'ctr'],
            LayoutRole::Blank => null,
        };
    }

    /**
     * The body boxes of a role, in reading order. `two-column` gives two because that is what
     * it is for; every other role gives one.
     *
     * @return non-empty-list<Box>
    */
    public function body(LayoutRole $role): array
    {
        return match ($role) {
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
     * The height a heading of this size needs, in pixels.
    */
    public function headingHeight(int $level): int
    {
        return intdiv($this->headingSize($level) * 8, 6) + 10;
    }
}