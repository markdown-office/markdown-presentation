<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

use MarkdownPresentation\Exception\MalformedTheme;

/**
 * A theme's colours, fonts, type scale and metadata.
 *
 * The colours are DrawingML's twelve: `dk1`, `lt1`, `dk2`, `lt2`, `accent1`…`accent6`,
 * `hlink` and `folHlink`. The spellings an earlier draft of the concept used —
 * `dark1`, `light1`, `dark2`, `light2`, `hyperlink`, `followedHyperlink` — are
 * accepted on input, because an author writing those means them, and are normalised
 * to DrawingML's own names on the way in. Emitting `dark1` would write a colour slot
 * PowerPoint does not have, which is the whole reason for keeping both.
 *
 * Colours and fonts a file does not mention keep Office's defaults, so a theme that
 * names two accents still produces a colour scheme that is complete.
 *
 * `style:` is the third of the three, and the one that decides what a slide looks like
 * rather than what colour it is — see {@see Style}. A theme that names none of it is drawn
 * in the library's own type scale and spacing, which is what every deck was drawn in before
 * a theme could say otherwise.
 *
 * `dark: true` says the page is dark. It renames no colour — `dk1` is still the dark
 * one and `lt1` the light one — it says which of them plays which part, which is what
 * PowerPoint's own dark themes do with the colour map rather than with the palette.
 *
 * How a `theme.yml` is read is {@see ThemeMapping}'s, on the same seam {@see Style} draws
 * from {@see StyleMapping}: this object never sees a file, so it carries none of the bounds
 * one puts on.
 */
final class Theme
{
    public const DK1 = 'dk1';
    public const LT1 = 'lt1';
    public const DK2 = 'dk2';
    public const LT2 = 'lt2';
    public const HLINK = 'hlink';
    public const FOL_HLINK = 'folHlink';

    /**
     * The twelve colour slots, in the order a DrawingML `clrScheme` lists them.
     *
     * This is the list a colour scheme is written from, and {@see ThemeMapping} holds a
     * `theme.yml` to it, so it is one list rather than one per class.
     */
    public const COLOURS = [
        self::DK1,
        self::LT1,
        self::DK2,
        self::LT2,
        'accent1',
        'accent2',
        'accent3',
        'accent4',
        'accent5',
        'accent6',
        self::HLINK,
        self::FOL_HLINK,
    ];

    /** The font slots, in the order a `theme.yml` should list them. */
    public const FONTS = ['heading', 'body', 'mono'];

    /**
     * The four names a colour plays rather than the slot it fills, in the order a message
     * names them.
     *
     * `page` is the colour behind every slide, `ink` the colour body text is drawn in,
     * `subtle` the colour a quote is drawn in and `panel` the colour a code block's own
     * background is filled with. They are here rather than in the renderer because they
     * are the theme's own four parts under other names.
     *
     * @var list<string>
     */
    public const ROLES = ['page', 'ink', 'subtle', 'panel'];

    /**
     * The word a colour is written as when there is none of it.
     */
    public const NONE = 'none';

    /**
     * Whether a colour may be written this way: a role, a theme slot, one of the spellings
     * the concept draft used, `none` for no colour at all, or a hex value.
     *
     * Asked of the theme rather than of the renderer that draws with the colour, because
     * the names are the theme's own and a theme file is checked against them when it is
     * read rather than when a deck happens to use the style that carries the mistake.
     */
    public static function hasColour(string $written): bool
    {
        return in_array($written, self::ROLES, true)
            || in_array($written, self::COLOURS, true)
            || in_array($written, array_keys(ThemeMapping::aliases()), true)
            || $written === self::NONE
            || preg_match('/\A#?[0-9A-Fa-f]{6}\z/', $written) === 1;
    }

    /**
     * @param array<string, string> $colours Canonical colour slot to `#rrggbb`.
     * @param array<string, string> $fonts   Font slot to family name.
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $description,
        private readonly ?string $author,
        private readonly bool $dark,
        private readonly array $colours,
        private readonly array $fonts,
        private readonly Style $style,
        private readonly TextStyles $textStyles = new TextStyles(),
    ) {
    }

    /**
     * Office's own defaults: black on white, and the accent palette its themes
     * have shipped unchanged since 2013.
     */
    public static function defaults(string $name = 'default'): self
    {
        return new self(
            $name,
            null,
            null,
            false,
            [
                self::DK1 => '#000000',
                self::LT1 => '#FFFFFF',
                self::DK2 => '#44546A',
                self::LT2 => '#E7E6E6',
                'accent1' => '#4472C4',
                'accent2' => '#ED7D31',
                'accent3' => '#A5A5A5',
                'accent4' => '#FFC000',
                'accent5' => '#5B9BD5',
                'accent6' => '#70AD47',
                self::HLINK => '#0563C1',
                self::FOL_HLINK => '#954F72',
            ],
            [
                'heading' => 'Calibri',
                'body' => 'Calibri',
                'mono' => 'Consolas',
            ],
            Style::defaults(),
        );
    }

    /**
     * A theme as a `theme.yml` describes it.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    public static function fromArray(array $data, string $file = 'theme.yml', ?TextStyles $styles = null): self
    {
        return ThemeMapping::read($data, $file, $styles);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function author(): ?string
    {
        return $this->author;
    }

    /**
     * Whether the page this theme paints is dark.
     *
     * The palette does not change either way: `dk1` is the dark colour and `lt1` the
     * light one whichever this says. What changes is which of them paints the page and
     * which paints the type on it.
     */
    public function isDark(): bool
    {
        return $this->dark;
    }

    /*
     * The four parts the master's colour map maps — `bg1`, `tx1`, `bg2`, `tx2` — each
     * resolved to the slot that plays it on this theme.
     *
     * Asked of the theme rather than read off the master because a shape's colour reaches
     * the file as a literal `a:srgbClr` and the map is never consulted for it again: a
     * shape that named `dk1` outright would paint dark type on the page `page()` paints
     * dark. These four are therefore the same fact as the map, which is what keeps the
     * two in step.
     */
    /** The slot the page behind every slide is painted with. */
    public function page(): string
    {
        return $this->dark ? self::DK1 : self::LT1;
    }

    /** The slot body text, a heading, a title and a table cell are drawn in. */
    public function ink(): string
    {
        return $this->dark ? self::LT1 : self::DK1;
    }

    /** The slot a code block and a table's header row are filled with. */
    public function panel(): string
    {
        return $this->dark ? self::DK2 : self::LT2;
    }

    /** The slot a quote and a code block's own text are drawn in. */
    public function subtleInk(): string
    {
        return $this->dark ? self::LT2 : self::DK2;
    }

    /**
     * Every colour, keyed by its DrawingML name and in DrawingML's order.
     *
     * @return array<string, string>
     */
    public function colours(): array
    {
        return $this->colours;
    }

    /**
     * One colour, under either spelling of its name. Null when the theme does not
     * set one, which a theme that went through {@see self::defaults()} never does.
     */
    public function colour(string $slot): ?string
    {
        return $this->colours[ThemeMapping::slot($slot)] ?? null;
    }

    /**
     * Every font, keyed by its slot.
     *
     * @return array<string, string>
     */
    public function fonts(): array
    {
        return $this->fonts;
    }

    /** One font, or null when the theme does not set the slot. */
    public function font(string $slot): ?string
    {
        return $this->fonts[$slot] ?? null;
    }

    /**
     * The numbers this theme sets: its type scale, its spacing rhythm and how it treats a
     * heading.
     *
     * Never null — a theme that names no `style:` gets {@see Style::defaults()}, which is
     * what {@see \MarkdownPresentation\Render\SlideGeometry} has always used. A renderer
     * therefore never has to ask whether a theme had an opinion before it can draw with it.
     */
    public function style(): Style
    {
        return $this->style;
    }

    /**
     * The named text styles this theme carries.
     *
     * Empty for a theme without a `text-styles.yml`, which is most of them: a style is
     * something a deck names, and a deck that names none has said nothing about the words
     * it draws.
     */
    public function textStyles(): TextStyles
    {
        return $this->textStyles;
    }

    /**
     * The theme's `theme.yml` as an array, in DrawingML's spellings.
     *
     * The named text styles are not in here: they are read from a file of their own, and a
     * `theme.yml` that carried them would be a theme with two places to say the same thing.
     *
     * @return array{name: string, description: ?string, author: ?string, dark: bool, colors: array<string, string>, fonts: array<string, string>, style: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'author' => $this->author,
            'dark' => $this->dark,
            'colors' => $this->colours,
            'fonts' => $this->fonts,
            'style' => StyleMapping::write($this->style),
        ];
    }
}
