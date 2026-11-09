<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownPresentation\Exception\MalformedShape;
use PhpOffice\PhpPresentation\Shape\AutoShape;

/**
 * The shape a picture is drawn in.
 *
 * A picture is drawn in a rectangle, and a picture can be drawn in another shape instead — the
 * mask a picture is fitted into, which is what PowerPoint writes when a picture is cropped to a
 * shape. The shape is the picture's own and not a frame drawn around it: the picture is written
 * as a `p:pic` whose geometry is the shape, so the mask is part of the picture rather than
 * something drawn over it.
 *
 * The names are the library's own, as the names of the ink shapes are, and each one is a preset
 * the format names: {@see AutoShape} holds them all, and the file writes the name rather than a
 * drawing of it. The presets that are not offered are refused by name rather than passed on — a
 * picture in a shape nobody can read the name of is a picture in a shape the author cannot ask
 * for again.
 */
enum Mask: string
{
    case Circle = 'circle';
    case Ellipse = 'ellipse';
    case RoundRect = 'roundrect';
    case Triangle = 'triangle';
    case Diamond = 'diamond';
    case Hexagon = 'hexagon';
    case Chevron = 'chevron';
    case Arrow = 'arrow';
    case Star = 'star';
    case Heart = 'heart';
    case Teardrop = 'teardrop';
    case Cloud = 'cloud';
    case Moon = 'moon';
    case Donut = 'donut';
    case Frame = 'frame';
    case Plaque = 'plaque';

    /**
     * The shape the picture is drawn in, as the format names it.
     *
     * The names of {@see AutoShape}, because the file writes the name of a preset rather than a
     * drawing of it. A circle and an ellipse are the same preset: the picture's own proportions
     * decide which of the two it looks like, and the shape is drawn in the box the picture is
     * drawn in.
     */
    public function prst(): string
    {
        return match ($this) {
            self::Circle, self::Ellipse => AutoShape::TYPE_OVAL,
            self::RoundRect => AutoShape::TYPE_ROUNDED_RECTANGLE,
            self::Triangle => AutoShape::TYPE_ISOSCELES_TRIANGLE,
            self::Diamond => AutoShape::TYPE_DIAMOND,
            self::Hexagon => AutoShape::TYPE_HEXAGON,
            self::Chevron => AutoShape::TYPE_CHEVRON,
            self::Arrow => AutoShape::TYPE_RIGHT_ARROW,
            self::Star => AutoShape::TYPE_5_POINT_STAR,
            self::Heart => AutoShape::TYPE_HEART,
            self::Teardrop => AutoShape::TYPE_TEAR,
            self::Cloud => AutoShape::TYPE_CLOUD,
            self::Moon => AutoShape::TYPE_MOON,
            self::Donut => AutoShape::TYPE_DONUT,
            self::Frame => AutoShape::TYPE_FRAME,
            self::Plaque => AutoShape::TYPE_PLAQUE,
        };
    }

    /**
     * The mask the deck asked for, or null where it asked for none.
     *
     * @throws MalformedShape
     */
    public static function read(int $slide, string $name, DirectiveAttributes $attributes): ?self
    {
        $written = Length::written($slide, $name, 'mask', $attributes, '');

        if ($written === '') {
            return null;
        }

        $mask = self::tryFrom(strtolower(trim($written)));

        if ($mask !== null) {
            return $mask;
        }

        throw MalformedShape::attributeValue(
            $slide,
            $name,
            'mask',
            $written,
            sprintf('A mask is one of: %s.', implode(', ', self::names())),
        );
    }

    /**
     * Every mask this format offers, sorted, for a message that has to name them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_map(static fn (self $mask): string => $mask->value, self::cases());
        sort($names);

        return $names;
    }
}
