<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use PhpOffice\PhpPresentation\Shape\AutoShape;

/**
 * The twelve free-placement directives: what each one draws and what it may be written with.
 *
 * A name per shape rather than one `::shape{t=…}`, because a name is the word a model reaches
 * for and an attribute is a spelling it has to be told. Everything this format does not draw
 * is refused by name, so the twelve here are the whole of the vocabulary — which is only
 * affordable at twelve rather than the hundred and fifty `AutoShape::TYPE_*` holds, and is
 * the reason the rest of them are refused rather than exposed.
 *
 * {@see self::attributes()} is what keeps the refusal honest in the other direction: an
 * attribute a directive does not read is refused rather than ignored, so `fill` on a picture
 * and `padding` on a rectangle are both failures that name what would have worked. An ignored
 * attribute is the one mistake in this vocabulary that leaves no trace at all.
 *
 * {@see self::autoShape()} is where the two meet, and the mapping is deliberately partial:
 * PHPPresentation's autoshapes are written with an outline of one colour and one width
 * ({@see AutoShape::getOutline()}), so a dash or a double line is not a shape this library
 * draws.
 */
enum ShapeKind: string
{
    case Rectangle = 'rectangle';
    case RoundRect = 'roundrect';
    case Ellipse = 'ellipse';
    case Circle = 'circle';
    case Triangle = 'triangle';
    case Diamond = 'diamond';
    case Chevron = 'chevron';
    case Hexagon = 'hexagon';
    case Arrow = 'arrow';
    case Line = 'line';
    case Image = 'image';
    case Content = 'content';

    /** Where a shape sits, how it is turned and when it is drawn, beside the layout's. */
    private const PLACEMENT = ['left', 'top', 'width', 'height', 'anchor', 'layer', 'rotate'];

    /** What a shape is painted with. */
    private const PAINT = ['fill', 'alpha', 'stroke', 'stroke-width'];

    /**
     * The shadow a shape is drawn with, where the shape is a box to cast one.
     *
     * On every directive that draws a box, which is all twelve: a label lives inside an ink
     * shape and so takes the shape's shadow, and a picture is a `p:pic` with an effect list of
     * its own. Five attributes rather than one shorthand — see {@see Shadow}.
     */
    private const SHADOW = [
        'shadow-color',
        'shadow-distance',
        'shadow-blur',
        'shadow-direction',
        'shadow-alpha',
    ];

    /** How the type inside a free text block is set. */
    private const TYPE = ['color', 'font-size', 'font-family', 'align', 'vertical-align', 'padding'];

    /**
     * The shape's own text — one plain run inside the shape rather than a box over it.
     *
     * On the ink shapes and nowhere else. `::image` is a `p:pic`, which has no text body at
     * all, and `:::content` is a box whose body is Markdown, so a label on either would be a
     * second way to put the same words in the same place.
     */
    private const LABEL = ['label'];

    /** What a picture is taken from and how it is fitted to its box. */
    private const PICTURE = ['src', 'alt', 'fit'];

    /**
     * The autoshape this is drawn as, or null where it is not drawn as one.
     *
     * `line` is a rectangle because that is what a rule has always been: a connector is a
     * separate shape class with its own geometry, and a hairline drawn as a thin rectangle is
     * the same mark with none of the second set of coordinates to get wrong.
     */
    public function autoShape(): ?string
    {
        return match ($this) {
            self::Rectangle, self::Line => AutoShape::TYPE_RECTANGLE,
            self::RoundRect => AutoShape::TYPE_ROUNDED_RECTANGLE,
            self::Ellipse, self::Circle => AutoShape::TYPE_OVAL,
            self::Triangle => AutoShape::TYPE_ISOSCELES_TRIANGLE,
            self::Diamond => AutoShape::TYPE_DIAMOND,
            self::Chevron => AutoShape::TYPE_CHEVRON,
            self::Hexagon => AutoShape::TYPE_HEXAGON,
            self::Arrow => AutoShape::TYPE_RIGHT_ARROW,
            self::Image, self::Content => null,
        };
    }

    /**
     * Every attribute this directive reads, sorted, for a message that has to name them.
     *
     * Sorted rather than in the order they are read, because the order they are read in is an
     * accident of the walk and an author reading a refusal wants the list, not a tour of the
     * code that produced it.
     *
     * @return list<string>
     */
    public function attributes(): array
    {
        $attributes = match ($this) {
            self::Image => [...self::PLACEMENT, ...self::PICTURE],
            self::Content => [...self::PLACEMENT, ...self::PAINT, ...self::TYPE],
            self::RoundRect => [...self::PLACEMENT, ...self::PAINT, ...self::LABEL, 'border-radius'],
            default => [...self::PLACEMENT, ...self::PAINT, ...self::LABEL],
        };

        $attributes = [...$attributes, ...self::SHADOW];

        sort($attributes);

        return $attributes;
    }

    /**
     * Whether the directive is a `:::` container rather than a `::` leaf.
     *
     * Only the text block is, and {@see \MarkdownPresentation\Render\CommonMark\MarkerStartParser}
     * is what stands aside for it: the two facts are one decision made in two places, and a
     * container read off this and not off that is a container the marker parser took.
     */
    public function isContainer(): bool
    {
        return $this === self::Content;
    }

    /**
     * Whether the shape insists on being as wide as it is tall.
     *
     * An ellipse is drawn in the box it is given, so a square one is a circle and nothing has
     * to be refused. `circle` is the name that says so, and it is an ellipse that refuses two
     * sizes that disagree rather than picking one — see
     * {@see \MarkdownPresentation\Render\Free\Placement} for which one.
     */
    public function isCircle(): bool
    {
        return $this === self::Circle;
    }

    /**
     * Whether the shape is type rather than ink, and so is measured as words.
     */
    public function isContent(): bool
    {
        return $this === self::Content;
    }
}
