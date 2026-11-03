<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use MarkdownPresentation\Render\Free\ShapeKind;
use MarkdownPresentation\Render\SlideGeometry;
use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\AutoShape;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\RichText\Run;
use PhpOffice\PhpPresentation\Shape\RichText\TextElement;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;

/**
 * The free shape a shape the deck placed itself becomes.
 *
 * A shape the deck placed itself is an autoshape, or a text box the library wrote under the
 * name of the directive that draws it. The autoshape is recognised by its own geometry, which
 * is the true inverse: the name is what this library writes, and a deck written by another
 * program has names of its own.
 *
 * What a shape is besides its box and its paint does not travel: the theme slot a colour came
 * from, the shadow it casts, the corner radius of a rounded rectangle, the layer it was drawn
 * on and the anchor of the box.
 */
final class FreeShapeMapper
{
    public function __construct(
        private readonly Options $options,
        private readonly ProseReader $prose,
    ) {
    }

    /**
     * The blocks a placed shape becomes, or nothing where it is not one.
     *
     * @return list<Block>
     */
    public function placed(AbstractShape $shape): array
    {
        if (!$this->options->preserveGeometry) {
            return [];
        }

        $found = $this->kindOf($shape);

        if ($found === null) {
            return [];
        }

        return [Block::shape($this->freeShape($found['shape'], $found['kind']))];
    }

    /**
     * The free shape a shape is, or null where it is not one this library draws.
     *
     * @return ?array{kind: ShapeKind, shape: AutoShape|RichText}
     */
    private function kindOf(AbstractShape $shape): ?array
    {
        // A placeholder is the layout's own, and the layout is not in the deck.
        if ($shape->isPlaceholder()) {
            return null;
        }

        $named = $shape instanceof AutoShape || $shape instanceof RichText
            ? $this->kindByName($shape)
            : null;

        if ($named !== null) {
            return $named;
        }

        return $shape instanceof AutoShape ? $this->kindByGeometry($shape) : null;
    }

    /**
     * The free shape a shape is by the name the library gave it.
     *
     * The name is more precise than the geometry: a circle and an ellipse are the same preset,
     * and only the name says which one the deck wrote.
     *
     * It is also the only way to tell a rectangle at all, because a rectangle is read as a text
     * shape and not as an autoshape — PowerPoint keeps most of its formatted text in one, and a
     * reader that wants the formatting keeps the text shape.
     *
     * @return ?array{kind: ShapeKind, shape: AutoShape|RichText}
     */
    private function kindByName(AutoShape|RichText $shape): ?array
    {
        foreach (ShapeKind::cases() as $kind) {
            if ($kind->value === $shape->getName()) {
                return ['kind' => $kind, 'shape' => $shape];
            }
        }

        return null;
    }

    /**
     * The free shape a shape is by the geometry it was drawn with.
     *
     * A deck written by another program has names of its own, and its geometry is what says
     * what it drew.
     *
     * @return ?array{kind: ShapeKind, shape: AutoShape|RichText}
     */
    private function kindByGeometry(AutoShape $shape): ?array
    {
        foreach (ShapeKind::cases() as $kind) {
            if ($kind->autoShape() === $shape->getType()) {
                return ['kind' => $kind, 'shape' => $shape];
            }
        }

        return null;
    }

    /**
     * The free shape, with the box it was given and the colour it was painted.
     */
    private function freeShape(AutoShape|RichText $shape, ShapeKind $kind): Shape
    {
        $box = $this->box($shape);

        // A circle's two sides are one number, and its percentages resolve against the height
        // either way: a circle is the one shape whose width is a fraction of the slide's
        // height rather than of its width, so that `width=14% height=14%` is the square the
        // author wrote rather than 134 by 76.
        $across = $kind->isCircle() ? SlideGeometry::HEIGHT : SlideGeometry::WIDTH;

        $attributes = [
            'left' => $this->percentage($box['x'], SlideGeometry::WIDTH),
            'top' => $this->percentage($box['y'], SlideGeometry::HEIGHT),
            'width' => $this->percentage($box['width'], $across),
            'height' => $this->percentage($box['height'], SlideGeometry::HEIGHT),
        ];

        $fill = $this->fill($shape);

        if ($fill !== null) {
            $attributes['fill'] = $fill;
        }

        $gradient = $this->gradient($shape);

        if ($gradient !== null) {
            $attributes['gradient'] = $gradient['stops'];

            if ($gradient['radial']) {
                $attributes['gradient-radial'] = 'true';
            } else {
                $attributes['gradient-angle'] = (string) $gradient['angle'];
            }
        }

        // A line is a stroke and not a fill, and a rule that comes back with no colour is a
        // rule nobody can see.
        $stroke = $this->stroke($shape);

        if ($stroke !== null) {
            $attributes['stroke'] = $stroke;

            $attributes['stroke-width'] = $this->strokeWidth($shape);
        }

        $rotation = $shape->getRotation();

        if ($rotation !== 0) {
            $attributes['rotate'] = (string) $rotation;
        }

        // A shape that runs off the edge is refused by the renderer unless it says so, and the
        // shape it was read from ran off the edge.
        if ($box['x'] < 0 || $box['y'] < 0 || $box['x'] + $box['width'] > SlideGeometry::WIDTH
            || $box['y'] + $box['height'] > SlideGeometry::HEIGHT) {
            $attributes['bleed'] = 'true';
        }

        // A free text block holds its body; the page number holds the number the renderer
        // wrote into it, which is not the author's to write back.
        $body = $kind === ShapeKind::Content && $shape instanceof RichText
            ? $this->prose->prose(array_values($shape->getParagraphs()))
            : [];

        // The type of the words in a free text block is the block's, not the prose's, and it
        // travels because it is an attribute of the directive rather than of the words in it.
        if ($kind === ShapeKind::Content && $shape instanceof RichText) {
            $attributes += $this->typeOf($shape);
        }

        // A label is the words an ink shape holds. A free text block holds its body
        // instead, and a shape with both is a shape with two places for its words.
        $label = $kind->isContent() ? null : $this->label($shape);

        if ($label !== null) {
            $attributes['label'] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $label) . '"';
        }

        return new Shape($kind->value, $attributes, $body);
    }

    /**
     * The box a shape occupies, in the pixels the library counts in.
     *
     * The pixels are the reader's own: PHPPresentation converts the EMU of the file into
     * pixels when it reads a shape, and a pixel is 9525 EMU at the 96 dpi the library
     * converts at, so the two count the same grid.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    private function box(AbstractShape $shape): array
    {
        return [
            'x' => (int) round($shape->getOffsetX()),
            'y' => (int) round($shape->getOffsetY()),
            'width' => (int) round($shape->getWidth()),
            'height' => (int) round($shape->getHeight()),
        ];
    }

    /**
     * A measurement as a percentage of the slide, as the free shapes write it.
     *
     * Four decimals and no more: a shape at a third of the way across a 960-pixel slide is
     * 33.3333%, and that is the nearest a percentage gets to it. A percentage rather than a
     * number of pixels because a shape is placed relative to the slide, and a deck read on a
     * page of another size is the same deck.
     */
    private function percentage(int $pixels, int $of): string
    {
        $percentage = round($pixels / $of * 100, 4);

        $written = rtrim(rtrim(number_format($percentage, 4, '.', ''), '0'), '.');

        return ($written === '' || $written === '-' ? '0' : $written) . '%';
    }

    /**
     * The colour a shape is painted, or null where it is painted with none.
     *
     * The colour a theme slot resolved to and not the slot itself: the slot is in the theme,
     * and the theme is not in the deck. A shape painted with a gradient or a pattern is left
     * without a colour, because one colour is not what it is painted with.
     */
    private function fill(AbstractShape $shape): ?string
    {
        $fill = $shape->getFill();

        if ($fill->getFillType() !== Fill::FILL_SOLID) {
            return null;
        }

        return strtoupper($fill->getStartColor()->getRGB());
    }

    /**
     * The gradient a shape is painted with, or null where it is painted with none.
     *
     * The stops are written as the colours they resolved to, and the angle is written in the
     * degrees CSS counts them in — the format counts its own from the right, ninety degrees
     * out of step.
     *
     * @return null|array{stops: string, radial: bool, angle: int}
     */
    private function gradient(AbstractShape $shape): ?array
    {
        $fill = $shape->getFill();

        if (!in_array($fill->getFillType(), [Fill::FILL_GRADIENT_LINEAR, Fill::FILL_GRADIENT_PATH], true)) {
            return null;
        }

        $stops = [];

        foreach ($fill->getGradientStops() as $stop) {
            $stops[] = '#' . strtoupper($stop->getColor()->getRGB());
        }

        return [
            'stops' => '"' . implode(',', $stops) . '"',
            'radial' => $fill->getFillType() === Fill::FILL_GRADIENT_PATH,
            'angle' => (((int) $fill->getRotation() + 90) % 360),
        ];
    }

    /**
     * The type of the words in a free text block, as the attributes that carry it.
     *
     * A free text block holds Markdown, and the Markdown's own formatting — a word in bold,
     * a word in italic — travels with it. The block's *type* is a different thing: it belongs
     * to the box rather than to the words, and it is an attribute of the directive, so it
     * travels too.
     *
     * @return array<string, string>
     */
    private function typeOf(RichText $shape): array
    {
        $attributes = [];

        // The first paragraph, and only the first: the type of a block is one thing, and a
        // block with paragraphs of different types is a block whose type the file does not
        // say. The loop is the way of saying "the first one" without saying what happens
        // when there is none, which a text shape with no paragraph in it never is.
        foreach (\array_slice(array_values($shape->getParagraphs()), 0, 1) as $paragraph) {
            // The run's font and not the paragraph's: the block's type is written into every
            // run, and the paragraph keeps the type a word would have without one.
            $font = $paragraph->getFont();

            foreach ($paragraph->getRichTextElements() as $element) {
                if ($element instanceof Run) {
                    $font = $element->getFont();

                    break;
                }
            }

            $attributes['color'] = strtoupper($font->getColor()->getRGB());
            $attributes['font-size'] = (string) $font->getSize();

            $align = match ($paragraph->getAlignment()->getHorizontal()) {
                'ctr' => 'center',
                'r' => 'right',
                'just' => 'justify',
                default => 'left',
            };

            if ($align !== 'left') {
                $attributes['align'] = $align;
            }

            $vertical = match ($paragraph->getAlignment()->getVertical()) {
                'ctr' => 'middle',
                'b' => 'bottom',
                default => 'top',
            };

            if ($vertical !== 'top') {
                $attributes['vertical-align'] = $vertical;
            }
        }

        return $attributes;
    }

    /**
     * The colour a shape is stroked in, or null where it is stroked with none.
     *
     * An autoshape carries its stroke on its outline and a text shape on its border, because
     * that is where the format keeps them: the file has one element and the reader gives it
     * to a shape of one kind or the other.
     */
    private function stroke(AutoShape|RichText $shape): ?string
    {
        if ($shape instanceof AutoShape) {
            return $this->colourOf($shape->getOutline()->getFill());
        }

        // A border is born black, and a reader cannot tell a border that was never given a
        // colour from one that was given black: a shape drawn without a stroke and a shape
        // drawn with a black one come back the same. The colour is therefore the signal, and
        // a black stroke is the one that does not travel.
        $colour = $this->colourOf($shape->getBorder()->getColor());

        return $colour === '000000' ? null : $colour;
    }

    /**
     * How wide a shape is stroked, in points, or null where it is not stroked.
     *
     * The width is written in whole pixels and the directive writes it in points, so the
     * number that comes back is a fraction: a stroke of two points is three pixels, and
     * three pixels are two and a quarter points. The file is the same either way.
     */
    private function strokeWidth(AutoShape|RichText $shape): string
    {
        $width = $shape instanceof AutoShape
            ? $shape->getOutline()->getWidth()
            : $shape->getBorder()->getLineWidth();

        $points = round($width / SlideGeometry::PIXELS_PER_POINT, 2);

        return rtrim(rtrim(number_format($points, 2, '.', ''), '0'), '.');
    }

    /**
     * The colour of a fill or of a colour, or null where there is none.
     */
    private function colourOf(Fill|Color|null $paint): ?string
    {
        if ($paint instanceof Color) {
            return strtoupper($paint->getRGB());
        }

        if ($paint === null || $paint->getFillType() !== Fill::FILL_SOLID) {
            return null;
        }

        return strtoupper($paint->getStartColor()->getRGB());
    }

    /**
     * The words an ink shape holds, or null where it holds none.
     *
     * A label is the shape's own text, and it is plain: a label is one run of text, and a
     * free text block holds its words as a body rather than as a label.
     */
    private function label(AbstractShape $shape): ?string
    {
        if ($shape instanceof AutoShape) {
            $text = trim($shape->getText());

            return $text === '' ? null : $text;
        }

        $paragraphs = $shape instanceof RichText ? array_values($shape->getParagraphs()) : [];

        if (count($paragraphs) !== 1) {
            return null;
        }

        $text = '';

        foreach ($paragraphs[0]->getRichTextElements() as $element) {
            if ($element instanceof TextElement) {
                $text .= $element->getText();
            }
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
