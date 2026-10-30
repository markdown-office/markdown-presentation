<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\MalformedTemplate;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\LoadedTheme;
use MarkdownPresentation\Theme\ThemeResolver;

/**
 * The theme a deck is drawn in, and what the deck asks of it.
 *
 * Out of {@see PresentationRenderer} because a theme is a thing the deck is drawn in rather
 * than a step of the walk that draws it: the theme is resolved, the role of each slide is
 * read, and the boxes the theme's own template gives those roles are taken — and none of
 * that is the renderer's own work.
 */
final class DeckTheme
{
    /** The theme a deck that names none gets. */
    public const DEFAULT_THEME = 'default';

    /**
     * The theme {@see self::resolve()} resolved, kept so that the name is resolved once.
     *
     * Null for a deck that names its own theme, which is the ordinary case.
     */
    private ?LoadedTheme $resolved = null;

    /**
     * @param ThemeResolver $themes The resolver the themes are looked up in. It is the
     *                               resolver that already looks beside the deck: the
     *                               renderer hands it the deck's directory before it
     *                               gets here.
     */
    public function __construct(
        private readonly ThemeResolver $themes,
    ) {
    }

    /**
     * The theme named in code, resolved now and kept.
     *
     * Resolved when it is named rather than when the deck is read, so that a typo is
     * reported before the deck is read at all.
     *
     * @throws ThemeNotFound When the theme is not one that resolves.
     */
    public function resolve(string $name): void
    {
        $this->resolved = $this->themes->resolve($name);
    }

    /**
     * The theme the deck is drawn in.
     *
     * A theme named in code answers for the deck, so the frontmatter's `theme:` is not
     * read — but it is checked, because a value of the wrong type is a mistake in the deck
     * whatever theme the deck is drawn in.
     *
     * @throws ThemeNotFound
     * @throws MalformedFrontmatter
     */
    public function load(Deck $deck): LoadedTheme
    {
        $name = $deck->frontmatterValue('theme', self::DEFAULT_THEME);

        if (!is_string($name) || trim($name) === '') {
            throw MalformedFrontmatter::wrongType(1, 'theme', 'a theme name');
        }

        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->themes->resolve(trim($name));
    }

    /**
     * The role of each of the deck's slides, by index.
     *
     * Resolved before anything is built so that a role the theme does not map fails while
     * there is still no file on disk to clean up.
     *
     * @return array<int, LayoutRole>
     *
     * @throws MalformedFrontmatter
     * @throws UnknownLayoutRole
     */
    public function roles(Deck $deck, LoadedTheme $theme): array
    {
        $roles = [];

        foreach ($deck->slides() as $slide) {
            $named = $slide->frontmatterValue('layout', LayoutRole::Content->value);

            if (!is_string($named) || trim($named) === '') {
                throw MalformedFrontmatter::wrongType(
                    $slide->index() + 1,
                    'layout',
                    sprintf('a layout role — one of: %s', implode(', ', LayoutRole::names())),
                );
            }

            $role = trim($named);

            if (LayoutRole::tryFrom($role) === null) {
                throw UnknownLayoutRole::notARoleOn($slide->index() + 1, $role, LayoutRole::names());
            }

            // Asked of the theme rather than checked here, because a role the theme does not
            // map is the theme's to fix and its message already says so.
            $theme->layout($role);

            $roles[$slide->index()] = LayoutRole::from($role);
        }

        return $roles;
    }

    /**
     * The roles the deck's slides use, in first-seen order and each of them once.
     *
     * Only the roles that are used are built. A theme that maps some of them would
     * otherwise fail on the ones its deck never asked for, which is not the same failure as a
     * deck that asks for one it did not map.
     *
     * Compared on the role's value rather than with `array_unique`, which casts each element
     * to a string and an enum cannot be cast to one.
     *
     * @param array<int, LayoutRole> $roles
     *
     * @return list<LayoutRole>
     */
    public function usedRoles(array $roles): array
    {
        $used = [];

        foreach ($roles as $role) {
            if (!in_array($role->value, array_column($used, 'value'), true)) {
                $used[] = $role;
            }
        }

        return $used;
    }

    /**
     * The boxes a theme's own `template.pptx` gives the roles the deck uses.
     *
     * A theme ships one or ships none, and this is where that decides anything: the roles a
     * template covers are drawn in the template's geometry and the rest keep
     * {@see SlideGeometry}'s, which is the precedence — a template fills in what it has and
     * says nothing about what it does not, the same rule `layouts.yml` follows. Read before
     * anything is built, so a template that cannot be read fails while there is still no file
     * on disk to clean up.
     *
     * Keyed by the layout *name* rather than by the role, because that is what the template
     * has: two roles a theme maps onto one name are one layout, and take the same boxes.
     *
     * @param list<LayoutRole> $roles
     *
     * @return array<string, array{title?: Box, body?: non-empty-list<Box>}>
     *
     * @throws MalformedTemplate
     */
    public function template(LoadedTheme $theme, array $roles): array
    {
        $master = $theme->template();

        if ($master === null) {
            return [];
        }

        // The name the master is known by, rather than the path it is in: a master the
        // library staged is a file of its own making under a name of its own, and a reader
        // told about that cannot tell which template it was.
        $layouts = (new TemplateGeometry($master->path(), name: $master->name()))->boxes();
        $boxes = [];

        foreach ($roles as $role) {
            $name = $theme->layout($role->value);

            if (isset($layouts[$name])) {
                $boxes[$role->value] = $layouts[$name];
            }
        }

        return $boxes;
    }
}
