<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

/**
 * The named styles a theme carries, keyed by the name a deck writes them under.
 *
 * A theme without a `text-styles.yml` has none, and every named form is then a name this
 * theme does not have — which is a refusal naming the styles that exist, and there are
 * none.
 */
final class TextStyles
{
    /**
     * @param array<string, TextStyle> $styles
     */
    public function __construct(private readonly array $styles = [])
    {
    }

    /**
     * The style a name stands for, or null where the theme has no such style.
     */
    public function get(string $name): ?TextStyle
    {
        return $this->styles[$name] ?? null;
    }

    /**
     * The names this theme has, in the order its file listed them.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->styles);
    }
}
