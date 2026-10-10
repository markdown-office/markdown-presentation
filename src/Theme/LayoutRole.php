<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

/**
 * A layout role: the portable name a deck writes, as opposed to the name a
 * particular template happens to give a layout.
 *
 * A deck that says `layout: two-column` is portable; `layout: "Two Content"` is
 * bound to one template's private vocabulary. The roles are an enum because the
 * set is closed and small, and because a name that is not in it is a mistake worth
 * naming rather than a string to pass through.
*/
enum LayoutRole: string
{
    case Title = 'title';
    case Content = 'content';
    case TwoColumn = 'two-column';
    case Section = 'section';
    case Blank = 'blank';

    /**
     * The roles, in the order they are offered, which is the order of a deck:
     * title, then content, then the variants.
     *
     * @return list<string>
    */
    public static function names(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}