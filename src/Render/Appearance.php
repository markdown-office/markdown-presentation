<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Theme\Theme;

/**
 * How one slide is drawn: the theme it is drawn in, the background it is drawn on, and the
 * two writers that put its shapes there.
 *
 * They are one object rather than four parameters on every method that draws a slide, for
 * the same reason {@see Writes} is one: they are one slide's own, they are built together,
 * and they do not vary within the slide. A slide that names the theme's values for itself
 * has all four of them different from the deck's, and they travel together because a block's
 * colours are resolved against the theme its slide is drawn in and its shapes are drawn in
 * the same theme.
 *
 * The pictures are the deck's whichever theme the slide is drawn in — {@see ShapeWriter}
 * keeps the storage of them — so a template's picture stays one media part for the whole deck.
 */
final class Appearance
{
    public function __construct(
        private readonly Theme $theme,
        private readonly ?Background $background,
        private readonly ShapeWriter $writer,
        private readonly SlideShapes $shapes,
    ) {
    }

    /**
     * The theme the slide is drawn in.
     */
    public function theme(): Theme
    {
        return $this->theme;
    }

    /**
     * The background the slide is drawn on, or null where the master keeps it.
     */
    public function background(): ?Background
    {
        return $this->background;
    }

    /**
     * The writer the slide's shapes are drawn with.
     */
    public function writer(): ShapeWriter
    {
        return $this->writer;
    }

    /**
     * The place the slide's blocks are laid out in.
     */
    public function shapes(): SlideShapes
    {
        return $this->shapes;
    }
}
