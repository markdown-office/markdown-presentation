<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\MalformedTheme;

/**
 * The named styles a theme carries.
 *
 * A theme without a `text-styles.yml` has none, and every named form is then a name this
 * theme does not have — which is a refusal naming the styles that exist, and there are
 * none.
 *
 * The names are folded, because a style's name is a word to a reader: `:Brand[Bakery]` and
 * `:brand[Bakery]` are the same word written twice. The theme's own spelling is kept
 * beside it, because that is the spelling the author has to change.
 *
 * A name written twice is refused here rather than where the styles are read from,
 * because a theme's styles may also be built in code: the rule is in the styles
 * themselves, so it holds whoever made them.
 */
final class TextStyles
{
    /**
     * The styles, keyed by the name they are found under.
     *
     * @var array<string, TextStyle>
     */
    private array $styles = [];

    /**
     * The name the theme wrote each style under, by the name it is found under.
     *
     * @var array<string, string>
     */
    private array $written = [];

    /**
     * @param array<string, TextStyle> $styles The styles, keyed by the name the theme
     *                                          wrote them under.
     * @param ?string                $file   The file they were read from, or null where
     *                                          they were built in code. A refusal names
     *                                          it, because that is where the author has
     *                                          to look.
     *
     * @throws MalformedTheme
     */
    public function __construct(array $styles = [], ?string $file = null)
    {
        foreach ($styles as $name => $style) {
            $folded = TextStyle::fold((string) $name);

            if (isset($this->written[$folded])) {
                throw MalformedTheme::duplicateStyle($file, (string) $name, $this->written[$folded]);
            }

            $this->styles[$folded] = $style;
            $this->written[$folded] = (string) $name;
        }
    }

    /**
     * The style a name stands for, or null where the theme has no such style.
     */
    public function get(string $name): ?TextStyle
    {
        return $this->styles[TextStyle::fold($name)] ?? null;
    }

    /**
     * The names this theme has, in the order its file listed them and as it spelled them.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values($this->written);
    }
}
