<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * A layout role that is not one of the portable roles, a role the theme does not
 * map, or a `layouts:` section that does not describe role mappings at all.
 *
 * The first two are told apart because they are fixed differently — one by spelling
 * the role differently, one by editing the theme — and each message names what
 * would have worked.
*/
final class UnknownLayoutRole extends InvalidInput
{
    /**
     * @param list<string> $roles
    */
    public static function notARole(string $role, array $roles): self
    {
        return new self(sprintf(
            '"%s" is not a layout role. The roles are: %s.',
            $role,
            implode(', ', $roles),
        ));
    }

    /**
     * The same refusal as {@see self::notARole()}, from a deck rather than from a theme.
     *
     * A separate message because the two are fixed in different places and a deck's failure
     * has to name the slide — `notARole` is for a theme's own file, where the file is the
     * place to look, and its caller already knows which one.
     *
     * @param list<string> $roles
    */
    public static function notARoleOn(int $slide, string $role, array $roles): self
    {
        return new self(sprintf(
            'Slide %d names the layout "%s", which is not a layout role. The roles are: %s.',
            $slide,
            $role,
            implode(', ', $roles),
        ));
    }

    /**
     * @param list<string> $mapped
    */
    public static function notMapped(string $role, string $theme, array $mapped): self
    {
        return new self(sprintf(
            'The theme "%s" maps no "%s" layout. It maps: %s.',
            $theme,
            $role,
            $mapped === [] ? '(none — its "layouts" section maps no role)' : implode(', ', $mapped),
        ));
    }

    public static function notALayoutName(string $file, string $role): self
    {
        return new self(sprintf('In "%s": the "%s" role is not a layout name.', $file, $role));
    }

    public static function notTheMappings(string $file): self
    {
        return new self(sprintf('In "%s": "layouts" is not a mapping of roles to layout names.', $file));
    }
}
