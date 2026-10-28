<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Render\CommonMark\InlineTranslator;
use MarkdownPresentation\Theme\LayoutRole;
use MarkdownPresentation\Theme\LoadedTheme;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColor;
use PhpOffice\PhpPresentation\Slide\SlideLayout;
use PhpOffice\PhpPresentation\Slide\SlideMaster;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\ColorMap;
use PhpOffice\PhpPresentation\Style\SchemeColor;

/**
 * Everything a slide hangs off before a slide is drawn: the master, its layouts, and the
 * document's own metadata.
 *
 * Together because they are the three answers a `.pptx` gives about a deck rather than about a
 * slide, and they are written once per deck however many slides it has. A renderer that had to
 * reach past this to ask a question about a slide would be asking the wrong class.
 */
final class MasterLayouts
{
    /**
     * The master the slides hang off, its layouts for the roles the deck used, and the deck's
     * own metadata, in that order: the layouts are named by the master's colour scheme, so
     * there is nothing to name them with until the master has it.
     *
     * @param list<LayoutRole> $roles
     *
     * @return array<string, SlideLayout>
     *
     * @throws MalformedFrontmatter
     */
    public static function build(
        PhpPresentation $presentation,
        Deck $deck,
        LoadedTheme $theme,
        array $roles,
        InlineTranslator $inline,
    ): array {
        $presentation->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);

        $master = self::master($presentation);

        self::properties($presentation, $deck, $theme, $inline);

        return self::layouts($master, $theme, $roles);
    }

    /**
     * PHPPresentation's constructor always makes one; the `??` is what happens if it ever
     * stops, and making a master is cheaper than refusing to draw a deck over it.
     */
    private static function master(PhpPresentation $presentation): SlideMaster
    {
        return $presentation->getAllMasterSlides()[0] ?? $presentation->createMasterSlide();
    }

    /**
     * One slide layout per role the deck used, each named the way the theme names it.
     *
     * This is where a {@see LayoutRole} becomes a layout in the file. A deck that writes
     * `layout: two-column` against a theme that maps it to `Two Content` gets slides whose
     * layout part is called `Two Content`, and the deck's portable vocabulary and the
     * template's private one stay apart — which is the whole point of the role.
     *
     * The placeholders that make the part mean something are written afterwards by
     * {@see PlaceholderPass} — PHPPresentation's own placeholder support would take a
     * slide's title shape out of the shape collection's contract, which is why that is a
     * pass over the written package rather than shapes added here.
     *
     * @param list<LayoutRole> $roles
     *
     * @return array<string, SlideLayout>
     */
    private static function layouts(SlideMaster $master, LoadedTheme $theme, array $roles): array
    {
        self::colourScheme($master, $theme->theme());

        $layouts = [];
        $existing = $master->getAllSlideLayouts();

        foreach ($roles as $position => $role) {
            // PHPPresentation's constructor leaves the master holding one empty layout.
            // Reusing it for the first role is what stops a deck with a single role from
            // carrying a second, nameless layout part in its file.
            $layout = $position === 0 && isset($existing[0]) ? $existing[0] : $master->createSlideLayout();
            $layout->setLayoutName($theme->layout($role->value));
            $layouts[$role->value] = $layout;
        }

        return $layouts;
    }

    /**
     * The master: its colour scheme, and the page behind every slide.
     *
     * All twelve slots go in whatever the theme named, and the ones no shape uses go in
     * anyway: DrawingML's `CT_ColorScheme` is a sequence of exactly twelve required
     * elements, so a scheme with five of them is not a scheme with five colours, it is an
     * invalid theme part. `accent2`–`accent6` and `folHlink` are written because the format
     * has no way to leave them out, and because they are what PowerPoint's own colour picker
     * offers for a theme someone edits in the application.
     *
     * What that does *not* do is make a shape refer to a slot by name.
     * {@see \PhpOffice\PhpPresentation\Writer\PowerPoint2007\AbstractDecoratorWriter::writeColor()}
     * writes `a:srgbClr` for every `Color` it is given, `SchemeColor` included, so the accent
     * a bullet or a rule is painted with reaches the file as a literal value. Re-theming the
     * deck in PowerPoint restyles the picker's swatches; it does not restyle the shapes
     * already on the slides.
     *
     * The colour map is written from the theme's own four roles, so a dark theme's map and
     * its shapes cannot disagree about which slot paints what — {@see Theme::page()}
     * and its three neighbours are where the swap lives.
     */
    private static function colourScheme(SlideMaster $master, Theme $theme): void
    {
        foreach (Theme::COLOURS as $slot) {
            $colour = new SchemeColor();
            $colour->setValue($slot);
            $colour->setRGB(ltrim($theme->colour($slot) ?? '#000000', '#'));
            $master->addSchemeColor($colour);
        }

        $master->colorMap
            ->changeColor(ColorMap::COLOR_BG1, $theme->page())
            ->changeColor(ColorMap::COLOR_TX1, $theme->ink())
            ->changeColor(ColorMap::COLOR_BG2, $theme->panel())
            ->changeColor(ColorMap::COLOR_TX2, $theme->subtleInk());

        $background = new BackgroundColor();
        $background->setColor(new Color(ltrim($theme->colour($theme->page()) ?? '#FFFFFF', '#')));
        $master->setBackground($background);
    }

    /**
     * The deck's own metadata, on the properties part every `.docx` and `.pptx` has.
     *
     * @throws MalformedFrontmatter
     */
    private static function properties(
        PhpPresentation $presentation,
        Deck $deck,
        LoadedTheme $theme,
        InlineTranslator $inline,
    ): void {
        $properties = $presentation->getDocumentProperties();

        // The deck's author where it names one, the theme's otherwise. The deck is the more
        // specific of the two — it is the document, and the theme is the template it was
        // written in. A theme that names no author leaves the library's own name there, which
        // is a statement about the file rather than about who made it.
        $author = $deck->frontmatterValue('author');

        $properties->setCreator($author === null
            ? $theme->theme()->author() ?? 'markdown-presentation'
            : self::text($author, 'author'));

        $title = $deck->frontmatterValue('title');

        if ($title !== null) {
            // Stripped, because this part is a string in a metadata field: there is nothing
            // on the other end to interpret `**`, and a title of `Ops **runbook**` is a
            // title of "Ops runbook" to every reader of the file.
            $properties->setTitle($inline->plain(self::text($title, 'title'), 1, $theme->theme()));
        }

        $description = $deck->frontmatterValue('description');

        if ($description !== null) {
            $properties->setDescription(self::text($description, 'description'));
        }
    }

    /**
     * @throws MalformedFrontmatter
     */
    private static function text(mixed $value, string $key): string
    {
        if (!is_string($value)) {
            throw MalformedFrontmatter::wrongType(1, $key, 'a string');
        }

        return $value;
    }
}
