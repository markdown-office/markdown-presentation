<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownPresentation\Exception\MalformedTextStyle;
use MarkdownPresentation\Exception\UnknownTextStyle;
use MarkdownPresentation\Render\Free\Colour;
use MarkdownPresentation\Theme\TextStyle;
use MarkdownPresentation\Theme\Theme;

/**
 * The properties an inline directive carries, read off it and the theme it is read with.
 *
 * Three forms and one grammar: `:accent[brand]` is a style the theme names, `:accent[brand]`
 * with attributes on it is that style with those attributes laid over it, and
 * `:style[brand]` with attributes on it is the attributes and nothing else. `style` is
 * reserved, so a theme may not define a style of that name — see {@see TextStyle::RESERVED}.
 *
 * An attribute overrides the style it is written on, property by property, and a property
 * neither of them sets is not set: the run inherits it from the block it is in. Nothing is
 * reset to a default, which is what makes a style a style rather than a set of defaults.
 *
 * A face a word writes for itself is named with the place it was written in, because a
 * face with no table of advance widths is measured with another face's widths and the
 * reader has to be told where to change the name.
 *
 * @phpstan-type Properties array{colour: ?string, size: ?int, font: ?string, fontWhere: ?string, bold: ?bool, italic: ?bool, underline: ?bool, strike: ?bool}
 */
final class StyleReader
{
    /**
     * The Markdown a label may not hold.
     *
     * A complete construct only. An unbalanced marker is a character rather than an
     * intent, and `2 * 3`, `snake_case` and `a[b]` are ordinary text that a rule refusing
     * every marker would refuse all day.
     *
     * What makes a construct complete is CommonMark's own rule: a delimiter run that opens
     * an emphasis may not be followed by whitespace, and one that closes it may not be
     * preceded by whitespace. So `__ Bakery__` and `** text**` are text rather than
     * emphasis, and a label holding one is drawn as it was written.
     *
     * The underscore is the only one with a rule of its own: CommonMark does not open an
     * emphasis inside a word with an underscore, so `snake_case_name` is text.
     *
     * `~~struck~~` is not among them: it is GFM rather than CommonMark, and a label is
     * plain text, so the tildes are two characters in a word rather than an intent.
     *
     * The Word half keeps these rules in a list of patterns of its own, and the two lists
     * are the same: the halves agree with each other on every label in
     * `tests/fixtures/labels.txt`, which is what the corpus is for. They differ from
     * CommonMark in five of them, and the corpus records which — a run of three or more
     * underscores (`___Bakery___`, `_Bakery__`, `__Bakery_ __`) is emphasis to CommonMark
     * and text here, and a run of one asterisk beside a run of two (`a *Bakery**a`,
     * `a *Bakery_*a`) is text to CommonMark and a construct here. A change to either
     * half's rules has to say what it does about them.
     *
     * @var list<string>
     */
    private const MARKDOWN = [
        '/\*\*[^*\s](?:[^*]*[^*\s])?\*\*/',
        '/(?<![\w])__[^_\s](?:[^_]*[^_\s])?__(?![\w])/',
        '/\*[^*\s](?:[^*]*[^*\s])?\*/',
        '/(?<![\w])_[^\s_](?:[^_]*[^\s_])?_(?![\w])/',
        '/`[^`]+`/',
        '/\[[^\]]*\]\([^)]*\)/',
    ];

    /**
     * The smallest a size of type may be, in points.
     */
    private const MINIMUM_POINTS = 0.5;

    /**
     * The properties the directive carries, with every one of them resolved.
     *
     * @return Properties
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     */
    public function read(DirectiveInline $node, int $slide, Theme $theme): array
    {
        $name = $node->getName();
        $style = $this->base($node, $name, $slide, $theme)->with($this->over($node, $name, $slide, $theme));

        $this->label($node, $name, $slide);

        return [
            'colour' => $style->colour,
            'size' => $style->size,
            'font' => $style->font,
            'fontWhere' => $this->where($node, $slide),
            'bold' => $style->bold,
            'italic' => $style->italic,
            'underline' => $style->underline,
            'strike' => $style->strike,
        ];
    }

    /**
     * The style the name stands for, before the attributes are laid over it.
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     */
    private function base(DirectiveInline $node, string $name, int $slide, Theme $theme): TextStyle
    {
        if (TextStyle::isReserved($name)) {
            if (count($node->getAttributes()) === 0) {
                throw MalformedTextStyle::noAttributes($slide);
            }

            return new TextStyle();
        }

        $style = $theme->textStyles()->get($name);

        if ($style === null) {
            throw UnknownTextStyle::named($slide, $name, $theme->textStyles()->names());
        }

        return new TextStyle(
            $this->colour($slide, $name, 'color', $style->colour, $theme),
            $style->size,
            $style->font === null ? null : $this->font($slide, $name, 'font', $style->font, $theme),
            $style->bold,
            $style->italic,
            $style->underline,
            $style->strike,
        );
    }

    /**
     * The attributes the directive carries, each of them resolved.
     *
     * Every property is in the result, whether the directive set it or not: a property that
     * is null is one the directive said nothing about, and the style underneath it keeps
     * what it had.
     *
     * @return array{colour: ?string, size: ?int, font: ?string, bold: ?bool, italic: ?bool, underline: ?bool, strike: ?bool}
     *
     * @throws MalformedTextStyle
     */
    private function over(DirectiveInline $node, string $name, int $slide, Theme $theme): array
    {
        $over = [
            'colour' => null,
            'size' => null,
            'font' => null,
            'bold' => null,
            'italic' => null,
            'underline' => null,
            'strike' => null,
        ];

        foreach ($node->getAttributes()->toArray() as $attribute => $value) {
            $attribute = (string) $attribute;

            // The arms are the vocabulary, and an attribute they do not know is refused
            // rather than applied as the last one they do: a property nobody read is a deck
            // that renders and shows something the author did not write.
            //
            // The value is read inside the arms, so an attribute nobody reads is refused by
            // name rather than by the advice a bare attribute gets.
            match ($attribute) {
                'color' => $over['colour'] = $this->colour($slide, $name, 'color', $this->written($slide, $name, $attribute, $value), $theme),
                'size' => $over['size'] = $this->size($slide, $name, $this->written($slide, $name, $attribute, $value)),
                'font' => $over['font'] = $this->font($slide, $name, 'font', $this->written($slide, $name, $attribute, $value), $theme),
                'bold' => $over['bold'] = $this->flag($slide, $name, $attribute, $value),
                'italic' => $over['italic'] = $this->flag($slide, $name, $attribute, $value),
                'underline' => $over['underline'] = $this->flag($slide, $name, $attribute, $value),
                'strike' => $over['strike'] = $this->flag($slide, $name, $attribute, $value),
                TextStyle::UNSUPPORTED => throw MalformedTextStyle::highlight($slide, $name),
                default => throw MalformedTextStyle::attribute($slide, $name, $attribute, TextStyle::ATTRIBUTES),
            };
        }

        return $over;
    }

    /**
     * The label, which is plain text and has to be some.
     *
     * @throws MalformedTextStyle
     */
    private function label(DirectiveInline $node, string $name, int $slide): void
    {
        $label = $node->getLabel();

        if ($label === null || trim($label) === '') {
            throw MalformedTextStyle::noText($slide, $name);
        }

        foreach (self::MARKDOWN as $pattern) {
            if (preg_match($pattern, $label) === 1) {
                throw MalformedTextStyle::label($slide, $name, $label, self::wayOut($name));
            }
        }
    }

    /**
     * The way out of a label that holds Markdown.
     *
     * Two, and they are in two different files: a named style is given its weight in the
     * theme's own `text-styles.yml`, and the raw form carries it as an attribute — the raw
     * form has no theme file, because `style` is the one name a theme may not give a style
     * of its own.
     */
    private static function wayOut(string $name): string
    {
        return TextStyle::isReserved($name)
            ? 'give the style the weight as an attribute ("bold=true")'
            : sprintf('give the style the weight in the theme\'s "text-styles.yml" ("%s: { bold: true }")', $name);
    }

    /**
     * The value an attribute carries, as a string.
     *
     * A bare attribute asks for the attribute rather than for its value, which is a thing
     * only a flag can be: `{bold}` is a word set in bold. A bare attribute here is a
     * mistake; a bare flag is read by {@see self::flag()}.
     *
     * @param string|true $value
     *
     * @throws MalformedTextStyle
     */
    private function written(int $slide, string $name, string $attribute, string|true $value): string
    {
        if ($value !== true) {
            return $value;
        }

        throw MalformedTextStyle::value(
            $slide,
            $name,
            $attribute,
            $attribute,
            sprintf('A bare attribute asks for the attribute rather than for its value: write %s=…', $attribute),
        );
    }

    /**
     * The ink, as a hex value, or null where the style or the attribute names none.
     *
     * The same vocabulary a shape's own colour is written in, because a run's ink and a
     * shape's fill are the same question.
     *
     * @throws MalformedTextStyle
     */
    private function colour(int $slide, string $name, string $attribute, ?string $written, Theme $theme): ?string
    {
        if ($written === null) {
            return null;
        }

        $written = strtolower(trim($written));

        if (Colour::isNone($written)) {
            return null;
        }

        $colour = Colour::resolve($written, $theme);

        if ($colour === null) {
            throw MalformedTextStyle::value($slide, $name, $attribute, $written, Theme::colourAdvice());
        }

        return '#' . $colour->getRGB();
    }

    /**
     * The size of the type, in points.
     *
     * @throws MalformedTextStyle
     */
    private function size(int $slide, string $name, string $written): int
    {
        $trimmed = trim($written);

        if (preg_match('/\A(\d+(?:\.\d+)?)(?:pt)?\z/i', $trimmed, $match) !== 1) {
            throw MalformedTextStyle::value(
                $slide,
                $name,
                'size',
                $written,
                'A size of type is a number of points: size=18, size=18pt.',
            );
        }

        if ((float) $match[1] < self::MINIMUM_POINTS) {
            throw MalformedTextStyle::value(
                $slide,
                $name,
                'size',
                $written,
                sprintf('A size of type is at least half a point: size=%d.', (int) self::MINIMUM_POINTS),
            );
        }

        return (int) round((float) $match[1]);
    }

    /**
     * The family, as a theme's own font or as a literal.
     *
     * A slot the theme has not set falls through to being read as a literal, which is the
     * outcome rather than a refusal: `font=Inter` is a real answer whether or not the
     * theme names it.
     *
     * @throws MalformedTextStyle
     */
    private function font(int $slide, string $name, string $attribute, string $written, Theme $theme): string
    {
        $literal = trim(trim($written), "\"'");

        if ($literal === '') {
            throw MalformedTextStyle::value($slide, $name, $attribute, $written, 'A font is a family name.');
        }

        return $theme->fonts()->face($literal);
    }

    /**
     * Where a face the word itself names was named, or null where the word names none.
     *
     * The place rather than the face, because the face alone does not say where to change
     * it: a face with no table of advance widths is measured with the reference face's
     * widths, and the reader has to be told which word of the slide to change.
     *
     * Null where the face came from the theme's own style rather than from the word: a face
     * the theme names is the theme's own to change, and a word's own face is the deck's.
     */
    private function where(DirectiveInline $node, int $slide): ?string
    {
        return $node->getAttributes()->has('font')
            ? sprintf('a font attribute on slide %d', $slide)
            : null;
    }

    /**
     * A flag, which is either set or not.
     *
     * @param string|true $written True where the attribute was written bare.
     *
     * @throws MalformedTextStyle
     */
    private function flag(int $slide, string $name, string $attribute, string|true $written): bool
    {
        if ($written === true) {
            return true;
        }

        $lower = strtolower(trim($written));

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        throw MalformedTextStyle::value($slide, $name, $attribute, $written, 'A flag is true or false.');
    }
}
