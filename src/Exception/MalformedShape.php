<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A free-placement directive this library reads, written with attributes it cannot use.
 *
 * Separate from {@see UnknownDirective} because the two failures have opposite fixes: a name
 * nobody drew needs a different name, and an attribute nobody read needs a different
 * attribute. One message covering both would send an author to edit the half that was
 * already right.
 *
 * `InvalidInput` for the same reason {@see MalformedFrontmatter} is: the deck's author is the
 * one who can act on it, and every message here names what would have worked instead.
 */
final class MalformedShape extends InvalidInput
{
    /**
     * @param list<string> $attributes The attributes the directive does read.
     */
    public static function unknownAttribute(int $slide, string $name, string $attribute, array $attributes): self
    {
        return new self(sprintf(
            'Slide %d gives "%s" an attribute "%s", which it does not read. The attributes are: %s.',
            $slide,
            $name,
            $attribute,
            implode(', ', $attributes),
        ));
    }

    /**
     * An attribute whose value is not what that attribute holds.
     *
     * `$advice` is a sentence rather than a noun phrase, because what a value has to be is
     * rarely one thing — "a length" says nothing about whether it may be negative or written
     * as a percentage — and a message that names the spelling would be one the author has to
     * read twice before acting on.
     */
    public static function attributeValue(
        int $slide,
        string $name,
        string $attribute,
        string $written,
        string $advice,
    ): self {
        return new self(sprintf(
            'Slide %d gives "%s" a %s of "%s". %s',
            $slide,
            $name,
            $attribute,
            $written,
            self::separator($written) . $advice,
        ));
    }

    /**
     * The sentence that names the separator, where the value says the mistake was the
     * separator rather than the value.
     *
     * A bare value runs to the next space, so a list written with commas is read as one
     * value: `width=40%, height=20%` gives a width of `40%,` and `fill=red,stroke=blue` a
     * fill of `red,stroke=blue`. A message that blames the value sends the author to change
     * a number that was right, and every value this message is about is a length, a colour, a
     * word or a number — none of which may hold a comma.
     */
    private static function separator(string $written): string
    {
        return str_contains($written, ',')
            ? 'Attributes are separated by a space, not a comma. '
            : '';
    }

    /**
     * A flag whose value is neither true nor false.
     *
     * @param string $attribute The attribute the flag is written as.
     */
    public static function flag(int $slide, string $name, string $attribute, string $written): self
    {
        return new self(sprintf(
            'Slide %d gives "%s" an "%s" of "%s". A flag is true or false: %s=true, %s=false.',
            $slide,
            $name,
            $attribute,
            $written,
            $attribute,
            $attribute,
        ));
    }

    /**
     * A box that does not lie within the slide.
     *
     * `$where` is the diagnosis rather than two numbers, for the reason
     * {@see self::attributeValue()} takes a sentence: which edge was crossed and by how much
     * are two different facts depending on which corner the anchor put the box at, and the
     * caller is the only place that knows which it was.
     *
     * @param int $measure {@see \MarkdownPresentation\Render\SlideGeometry::WIDTH} or `HEIGHT`.
     */
    public static function offSlide(int $slide, string $name, string $where, int $measure, string $measureWord): self
    {
        return new self(sprintf(
            'Slide %d places "%s" %s. A free shape lies within the %dpx %s slide: write left=0 width=100%% for '
            . 'one that reaches the edge.',
            $slide,
            $name,
            $where,
            $measure,
            $measureWord,
        ));
    }

    /**
     * A circle given two sizes that are not the same size.
     *
     * Squaring a circle silently would draw something other than the box the deck wrote, and
     * the reader would have no way to tell which of the two numbers was ignored.
     */
    public static function notSquare(int $slide, string $width, string $height): self
    {
        return new self(sprintf(
            'Slide %d gives a "::circle" a width of %s and a height of %s. A circle is as wide as it is tall; '
            . 'give one size or the other.',
            $slide,
            $width,
            $height,
        ));
    }

    /**
     * `fit=cover`, which is a crop rather than a fit.
 *
     * Named on its own because it is the word an author reaches for before any other, and it is
     * the one fit this library cannot express: PHPPresentation writes a picture either
     * proportional or stretched and has nowhere to put the crop rectangle that `cover` is.
 */
public static function coverFit(int $slide, string $name): self
{
    return new self(sprintf(
        'Slide %d fits "%s" by cover, which is a crop rather than a fit: the picture would be filled and the '
        . 'edges cut off. Write fit=contain to keep the whole picture, or fit=stretch to take the box exactly.',
        $slide,
        $name,
    ));
}

/**
     * A label in brackets on a free-placement directive.
     *
     * The RFC lets every directive carry one and it means different things to different
     * people: a title, a caption, an alternative text. A free shape has nowhere to put any of
     * them, and dropping the label would drop what the author wrote.
     */
    public static function label(int $slide, string $written): self
    {
        return new self(sprintf(
            'Slide %d writes "%s", which carries a label. A free shape is drawn where its attributes put it and '
            . 'has no title of its own; write the label as a paragraph inside a ":::content" instead.',
            $slide,
            $written,
        ));
    }

    /**
 * What a free text block may not hold, as the word a flow shape is named by, so the refusal
 * and the shape a column would have drawn say the same thing.
 *
 * @var array<string, string>
 */
private const BODY_NOUNS = [
    'code' => 'a code block',
    'image' => 'a picture',
    'rule' => 'a rule',
    'table' => 'a table',
    'directive' => 'a directive',
    'marker' => 'a step marker',
];

/**
     * A free text block holding something that is not type.
     *
     * One shape per directive is what makes this a refusal rather than a feature — see {@see
     * \MarkdownPresentation\Render\Free\FreeText} — and the message says which shapes would
     * have worked, because the block itself is a thing this library draws and only not there.
     *
     * @param string $block One of {@see self::BODY_NOUNS}.
     */
    public static function body(int $slide, string $written, string $block): self
    {
        return new self(sprintf(
            'Slide %d opens "%s" whose body holds %s. A free text block holds paragraphs, headings, lists and '
            . 'quotes; a table, a code block, a picture and a rule are each a shape of their own.',
            $slide,
            $written,
            self::BODY_NOUNS[$block],
        ));
    }

    /**
     * A free text block with nothing in it.
     *
     * Refused rather than drawn as an empty box: a shape with no text in it is one a reader
     * selects and cannot type anything into, and a `:::content` with no body is a fence that
     * was opened and not written.
     */
    public static function emptyBody(int $slide, string $written): self
    {
        return new self(sprintf(
            'Slide %d opens "%s" with no body. A free text block holds the Markdown between its fences: '
            . ':::content{left=8%%}' . "\n" . 'The words.' . "\n" . ':::',
            $slide,
            $written,
        ));
    }

    /**
     * A label on a directive that has no shape to hold one in, or no body to put one in.
     *
     * The two are one failure with two reasons, and both are the same mistake: putting the same
     * words on the same thing twice. A picture is a `p:pic` with no text body at all, and a
     * placed text block's body *is* its text.
     *
     * @param string $why The reason this directive has nowhere to put one.
     */
    public static function noLabel(int $slide, string $name, string $why): self
    {
        return new self(sprintf(
            'Slide %d gives "%s" a label, which it has nowhere to put: %s. A label goes on one of the ten ink '
            . 'shapes, and a ":::content" box already has a body to write in.',
            $slide,
            $name,
            $why,
        ));
    }

    /**
     * `border-radius` on a shape that has no corners to round.
     */
    public static function radius(int $slide, string $name): self
    {
        return new self(sprintf(
            'Slide %d gives "%s" a border-radius, which only a "::roundrect" has. Write it as "::roundrect".',
            $slide,
            $name,
        ));
    }

    /**
     * A step marker inside a free text block.
     *
     * A marker is where the next block begins and a container is not a column, so a marker
     * inside one would move a block out of a shape it is drawn inside.
     */
    public static function marker(int $slide, string $container, string $marker): self
    {
        return new self(sprintf(
            'Slide %d opens "%s" whose body holds "%s". A free text block is one box on one step, so it holds no '
            . 'step and no column marker.',
            $slide,
            $container,
            $marker,
        ));
    }
}
