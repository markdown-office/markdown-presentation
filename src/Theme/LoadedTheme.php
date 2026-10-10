<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\UnknownLayoutRole;

/**
 * A theme directory, read: the colours and fonts, the Word style slots it
 * overrides, the layout roles it maps, and the template that goes with it.
 *
 * A theme is a directory, and only `theme.yml` is required. The rest are read when
 * they are there and are absent when they are not — except a `styles.yml` that
 * names a slot which does not exist and a `layouts.yml` that maps a role which is
 * not one, which are mistakes rather than absences and are refused.
*/
final class LoadedTheme
{
    /**
     * @param array<string, string> $layouts Role name to the template's own layout name.
     * @param array<string, mixed>  $styles  Word style slot to styleId or inline style array.
    */
    public function __construct(
        private readonly string $directory,
        private readonly Theme $theme,
        private readonly array $styles,
        private readonly array $layouts,
        private readonly ?string $template,
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    /**
     * The theme's Word style slot overrides, in the order the file listed them.
     *
     * @return array<string, mixed>
    */
    public function styles(): array
    {
        return $this->styles;
    }

    /**
     * The roles this theme maps, to the layout names of its template.
     *
     * @return array<string, string>
    */
    public function layouts(): array
    {
        return $this->layouts;
    }

    /**
     * The roles this theme maps, as a list. A message naming them reads better as a
     * list than as a mapping, and the key is the half somebody has to copy into a
     * deck.
     *
     * @return list<string>
    */
    public function roles(): array
    {
        return array_keys($this->layouts);
    }

    /**
     * The template's own name for a role: `two-column` becoming `Two Content`.
     *
     * @throws UnknownLayoutRole When the name is not a role, or when the theme does
     *                            not map it. Both name the roles that would have
     *                            worked, because the fix is in the message.
    */
    public function layout(string $role): string
    {
        if (LayoutRole::tryFrom($role) === null) {
            throw UnknownLayoutRole::notARole($role, LayoutRole::names());
        }

        $layout = $this->layouts[$role] ?? null;

        if ($layout === null) {
            throw UnknownLayoutRole::notMapped($role, $this->theme->name(), $this->roles());
        }

        return $layout;
    }

    /** The path of the theme's `.pptx`, or null when it ships without one. */
    public function template(): ?string
    {
        return $this->template;
    }
}