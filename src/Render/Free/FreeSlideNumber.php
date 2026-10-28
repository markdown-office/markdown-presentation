<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Run;
use MarkdownPresentation\Render\TextFrame;

/**
 * The number of the slide a shape is drawn on.
 *
 * A shape rather than a piece of text the deck writes, because it takes the same placement
 * attributes as every other shape, it is in the layer order with them, and it needs no
 * string templating anywhere in the library: the renderer knows which slide it is drawing
 * and how many slides the deck has.
 *
 * Its own class because its text is not known when it is read: the chrome of a template is
 * read once for the deck and drawn on every slide that names it, and the number is the one
 * thing about it that is different on each of them.
 */
final class FreeSlideNumber extends FreeShape
{
    /**
     * @param bool $of Whether the number is written with the number of the deck after it.
     */
    private function __construct(
        ShapeKind $kind,
        Placement $placement,
        int $step,
        Paint $paint,
        public readonly TextStyle $style,
        public readonly bool $of,
        ?Shadow $shadow,
    ) {
        parent::__construct($kind, $placement, $step, $paint, '', $shadow);
    }

    public static function at(
        ShapeKind $kind,
        Placement $placement,
        int $step,
        Paint $paint,
        TextStyle $style,
        bool $of,
        ?Shadow $shadow,
    ): self {
        return new self($kind, $placement, $step, $paint, $style, $of, $shadow);
    }

    /**
     * The shape as it is drawn on a slide, with the number filled in.
     *
     * The text is a plain run, so the box's own type is the default for it: the colour, the
     * size and the face the attributes named are the ones it is drawn in.
     */
    public function numbered(int $number, int $slides): FreeText
    {
        $text = $this->of ? sprintf('%d / %d', $number, $slides) : (string) $number;

        return FreeText::at(
            ShapeKind::SlideNumber,
            $this->placement,
            $this->step,
            $this->paint,
            new TextFrame([new Paragraph([new Run($text)], $this->step)]),
            $this->style,
            $this->shadow,
        );
    }
}
