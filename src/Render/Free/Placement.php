<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Render\Box;
use MarkdownPresentation\Render\SlideGeometry;

/**
 * Where a free-placement directive puts its shape, as a box on the slide and a layer to write
 * it in.
 *
 * Four measurements and two words, and the words are what make the measurements usable: an
 * `anchor` says which corner of the box `left` and `top` name, so `left=50%` can be the middle
 * of the slide rather than the left of it, and `layer` says whether the shape is written
 * before the layout's own shapes or after them.
 *
 * The box lies within the slide, and one that does not is refused with the numbers rather than
 * clamped. Clamping is the failure {@see SlideGeometry::MIN_SIZE} exists to prevent: a shape
 * quietly drawn somewhere other than where it was asked for is a deck that renders, showing
 * something the author did not write, and nothing in the file says so. Full bleed is still
 * reachable, because `left=0 width=100%` is exactly the edge.
 */
final class Placement
{
    /**
     * Each anchor as how it moves the box off the point `left` and `top` name, horizontally
     * and then vertically.
     *
     * `start` is the point at the near edge, `end` at the far one and `centre` halfway —
     * three words rather than nine pairs of letters, because the pairs are what make this
     * unreadable and they say the same thing.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ANCHORS = [
        'nw' => ['start', 'start'],
        'n' => ['centre', 'start'],
        'ne' => ['end', 'start'],
        'e' => ['end', 'centre'],
        'se' => ['end', 'end'],
        's' => ['centre', 'end'],
        'sw' => ['start', 'end'],
        'w' => ['start', 'centre'],
        'center' => ['centre', 'centre'],
    ];

    /** The layer a shape written after the layout's own shapes is in, which is the default. */
    public const FRONT = 'front';

    /** The layer a shape written before them is in, so the flow is drawn on top of it. */
    public const BACK = 'back';

    /** @var list<string> */
    private const LAYERS = [self::BACK, self::FRONT];

    private function __construct(
        public readonly Box $box,
        public readonly string $layer,
    ) {
    }

    /** Whether the shape is written before the layout's own shapes rather than after them. */
    public function isBack(): bool
    {
        return $this->layer === self::BACK;
    }

    /**
     * The anchors, sorted, for a message that has to name them.
     *
     * @return list<string>
     */
    public static function anchors(): array
    {
        $anchors = array_keys(self::ANCHORS);
        sort($anchors);

        return $anchors;
    }

    /**
     * @throws MalformedShape
     */
    public static function read(int $slide, string $name, DirectiveAttributes $attributes, ShapeKind $kind): self
    {
        $anchor = self::anchor($slide, $name, $attributes);
        [$width, $height] = self::sizes($slide, $name, $attributes, $kind);

        $left = Length::pixels($slide, $name, 'left', Length::written($slide, $name, 'left', $attributes, '0'), SlideGeometry::WIDTH);
        $top = Length::pixels($slide, $name, 'top', Length::written($slide, $name, 'top', $attributes, '0'), SlideGeometry::HEIGHT);

        [$across, $down] = self::ANCHORS[$anchor];

        $x = $across === 'start' ? $left : ($across === 'end' ? $left - $width : $left - (int) round($width / 2));
        $y = $down === 'start' ? $top : ($down === 'end' ? $top - $height : $top - (int) round($height / 2));

        self::withinSlide($slide, $name, $left, $top, $width, $height, $x, $y);

        return new self(
            new Box($x, $y, $width, $height),
            self::layer($slide, $name, $attributes),
        );
    }

    /**
     * @throws MalformedShape
     */
    private static function anchor(int $slide, string $name, DirectiveAttributes $attributes): string
    {
        $anchor = strtolower(Length::written($slide, $name, 'anchor', $attributes, 'nw'));

        if (!isset(self::ANCHORS[$anchor])) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                'anchor',
                $anchor,
                sprintf('An anchor is one of: %s.', implode(', ', self::anchors())),
            );
        }

        return $anchor;
    }

    /**
     * @throws MalformedShape
     */
    private static function layer(int $slide, string $name, DirectiveAttributes $attributes): string
    {
        $layer = strtolower(Length::written($slide, $name, 'layer', $attributes, self::FRONT));

        if (!in_array($layer, self::LAYERS, true)) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                'layer',
                $layer,
                sprintf('A layer is one of: %s.', implode(', ', self::LAYERS)),
            );
        }

        return $layer;
    }

    /**
     * The width and the height, and what a circle does about having two of them.
     *
     * Every size defaults to the whole slide on its own axis, so a full-bleed panel is
     * `::rectangle{fill=panel}` with nothing else on it, and a shape that fills what is left of
     * a position is `left=8% width=50%` rather than a number of pixels.
     *
     * A circle resolves both of its percentages against the height, which is the one decision
     * here that is not a rule. `width=10% height=10%` is the square the author wrote, and the
     * two would not be a square at all if each resolved against its own axis — 96 by 54, an
     * ellipse that reads as a mistake rather than as a circle. One axis makes the spelling
     * mean what it says, and it makes the defaults work: a circle with no size at all is the
     * biggest one the slide's height allows rather than one 420px too wide.
     *
     * @return array{0: int, 1: int}
     *
     * @throws MalformedShape
     */
    private static function sizes(int $slide, string $name, DirectiveAttributes $attributes, ShapeKind $kind): array
    {
        $width = Length::written($slide, $name, 'width', $attributes, '100%');
        $height = Length::written($slide, $name, 'height', $attributes, '100%');

        if (!$kind->isCircle()) {
            return [
                self::side($slide, $name, 'width', $width, self::axis($width, SlideGeometry::WIDTH)),
                self::side($slide, $name, 'height', $height, self::axis($height, SlideGeometry::HEIGHT)),
            ];
        }

        if (!$attributes->has('width') && !$attributes->has('height')) {
            return [SlideGeometry::HEIGHT, SlideGeometry::HEIGHT];
        }

        if (!$attributes->has('width') || !$attributes->has('height')) {
            $across = $attributes->has('width');
            $written = $across ? $width : $height;

            $side = self::side($slide, $name, $across ? 'width' : 'height', $written, self::axis($written, SlideGeometry::HEIGHT));

            return [$side, $side];
        }

        $measured = self::side($slide, $name, 'width', $width, self::axis($width, SlideGeometry::HEIGHT));
        $tall = self::side($slide, $name, 'height', $height, self::axis($height, SlideGeometry::HEIGHT));

        if ($measured !== $tall) {
            throw MalformedShape::notSquare($slide, $width, $height);
        }

        return [$measured, $tall];
    }

    /**
 * The axis a size resolves a percentage against, or null where it may not be one.
 *
 * Only a percentage asks, so a size in pixels is the same number whichever axis it is given —
 * which is what makes this a question about the written text rather than a second measurement.
 *
 * @param int $named The axis this size would resolve a percentage against: the width of the
 *                   slide across, its height down, or the height for a circle either way.
 */
    private static function axis(string $written, int $named): ?int
    {
        return str_contains($written, '%') ? $named : null;
    }

    /**
     * @throws MalformedShape
     */
    private static function side(int $slide, string $name, string $attribute, string $written, ?int $of): int
    {
        $side = Length::pixels($slide, $name, $attribute, $written, $of);

        // `a:ext` is an `ST_PositiveCoordinate`: a zero width is not a thin shape but an
        // invalid one, and PowerPoint offers to repair the file or drops the shape without
        // saying so.
        if ($side < SlideGeometry::MIN_SIZE) {
            throw MalformedShape::attributeValue(
                $slide,
                $name,
                $attribute,
                $written,
                'A size is at least 1px: a shape with no width or no height is one PowerPoint offers to repair the file over.',
            );
        }

        return $side;
    }

    /**
     * @throws MalformedShape
     */
    private static function withinSlide(
        int $slide,
        string $name,
        int $left,
        int $top,
        int $width,
        int $height,
        int $x,
        int $y,
    ): void {
        [$where, $wide] = match (true) {
            $x < 0 => [sprintf('at left %dpx, which is %dpx before the left edge', $left, -$x), true],
            $y < 0 => [sprintf('at top %dpx, which is %dpx above the top edge', $top, -$y), false],
            $x + $width > SlideGeometry::WIDTH => [
                sprintf('at left %dpx with a width of %dpx, which reaches %dpx', $left, $width, $x + $width),
                true,
            ],
            $y + $height > SlideGeometry::HEIGHT => [
                sprintf('at top %dpx with a height of %dpx, which reaches %dpx', $top, $height, $y + $height),
                false,
            ],
            default => ['', true],
        };

        if ($where === '') {
            return;
        }

        throw MalformedShape::offSlide(
            $slide,
            $name,
            $where,
            $wide ? SlideGeometry::WIDTH : SlideGeometry::HEIGHT,
            $wide ? 'wide' : 'tall',
        );
    }
}
