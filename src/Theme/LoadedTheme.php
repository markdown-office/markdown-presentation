<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\UnknownLayoutRole;

/**
 * A theme read: the colours and fonts, the layout roles it maps, and the template that
 * goes with it.
 *
 * A theme is one file, and only `theme.yml` is required. The master beside it is read
 * when it is there and is absent when it is not, and a `layouts:` section that names a
 * role which is not one is refused when the theme is read, while a role the section does
 * not map is refused when a deck asks for it.
*/
final class LoadedTheme
{
    /**
     * @param array<string, string> $layouts Role name to the template's own layout name.
    */
    public function __construct(
        private readonly string $name,
        private readonly string $directory,
        private readonly Theme $theme,
        private readonly array $layouts,
        private readonly ?Master $template,
    ) {
    }

    /**
     * The name the theme was resolved by.
     *
     * The name a deck's `theme:` names and not the theme's own title: a theme directory is
     * called `pitch` whatever its `theme.yml` says it is called, and a deck that says
     * `theme: pitch` means the directory called `pitch`.
     *
     * It is here because the deck has to be able to say which theme it was drawn in — the
     * name travels into the `.pptx` and comes back out of it, so that a deck read back into
     * Markdown can be drawn again in the theme it was drawn in.
    */
    public function name(): string
    {
        return $this->name;
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

    /** The theme's master, or null when it ships without one. */
    public function template(): ?Master
    {
        return $this->template;
    }
}
