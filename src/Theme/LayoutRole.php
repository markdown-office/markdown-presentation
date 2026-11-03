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
    case ImageText = 'image-text';
    case ImageTitle = 'image-title';
    case ImageSlide = 'image-slide';

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

    /**
     * The layout names the library's own mapping gives each role.
     *
     * The eight Office names that every theme shipping with this library carried in a
     * `layouts.yml` of its own, byte for byte: a theme with no `layouts:` section is
     * drawn with these.
     *
     * The names and the roles have to stay in step — a role here without a name is a role
     * the library cannot draw, and a name here for a role that does not exist is a layout
     * no deck asks for.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            self::Title->value => 'Title Slide',
            self::Content->value => 'Title and Content',
            self::TwoColumn->value => 'Two Content',
            self::Section->value => 'Section Header',
            self::Blank->value => 'Blank',
            self::ImageText->value => 'Picture Left',
            self::ImageTitle->value => 'Picture with Caption',
            self::ImageSlide->value => 'Full Bleed Picture',
        ];
    }

    /**
     * Whether a slide on this role has a title box of its own.
     *
     * `blank` and `image-slide` do not, and for the same reason: a layout whose content is
     * one picture has nowhere to put a headline, and one that drew a title anyway would not
     * be the picture alone. A deck that names a title on either has it drawn as a heading in
     * the body instead, which is {@see \MarkdownPresentation\Render\SlideGeometry::title()}
     * answering null — this is the same fact asked from the other side.
     */
    public function hasTitleBox(): bool
    {
        return !in_array($this, [self::Blank, self::ImageSlide], true);
    }
}
