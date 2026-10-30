<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Render\Free\FreeShape;
use MarkdownPresentation\Render\Free\FreeSlideNumber;
use MarkdownPresentation\Render\Free\FreeText;
use MarkdownPresentation\Theme\Theme;

/**
 * The faces a theme, a deck and a template's chrome name, and the report of the ones there
 * are no advance widths for.
 *
 * Out of {@see PresentationRenderer} because the three are named in three places that are
 * read at three different times: the theme's faces are known before anything is built, the
 * deck's own once the deck has been read, and the chrome's after the templates have been
 * read — and a walk over the slides never sees the chrome at all.
 */
final class DeckFaces
{
    /**
     * @param FontReport $fonts The report the faces with no table are recorded in.
     */
    public function __construct(
        private readonly FontReport $fonts,
    ) {
    }

    /**
     * The faces the theme named that the library has no table of advance widths for,
     * recorded.
     *
     * The heading and the body faces are the ones that decide how a line wraps, and a face
     * with no table of its own is measured with the reference face's widths — which is what
     * every face was measured with before there were tables of their own. The monospaced
     * face is asked about as well: it is measured by its own advance, and which face it is
     * says exactly how wide its text is.
     *
     * The slot is named rather than the face alone, because the face alone does not say
     * where to change it.
     *
     * The faces the theme's text styles name are asked about here too, because
     * `text-styles.yml` is a file of the theme and a style in it is a face the theme
     * names — a face a word written in that style is drawn in, and therefore measured in.
     * A style that names one of the theme's own slots is that slot's face and is not a
     * finding: the theme has already said it.
     */
    public function theme(Theme $theme, TextMetric $metric): void
    {
        foreach (Theme::FONTS as $slot) {
            $face = $theme->fonts()->get($slot);

            if ($face !== null && !$metric->has($face)) {
                $this->fonts->record($face, sprintf("the theme's %s slot", $slot), $metric->faces());
            }
        }

        foreach ($theme->textStyleFaces() as $name => $face) {
            if (!$metric->has($face)) {
                $this->fonts->record($face, sprintf('the text style "%s"', $name), $metric->faces());
            }
        }
    }

    /**
     * The faces the deck named that the library has no table of advance widths for,
     * recorded.
     *
     * A box the deck placed itself may name a face of its own with `font-family`, and that
     * face is the one its words are drawn in and measured in. It is in the deck rather than
     * in the theme, so it does not exist when the theme is resolved — it exists once the
     * deck has been read, which is before anything is built and therefore before a byte
     * reaches the disk.
     *
     * The box is named rather than the slide it is on, because a deck that writes two boxes
     * of the same kind is two boxes and a reader who is told which one to change can find
     * it; the slide is named as well, because two boxes of the same kind on two slides are
     * two places and one of them is not the one meant.
     *
     * @param array<int, SlideBlocks> $blocks The deck's slides, as they were read.
     */
    public function deck(array $blocks, TextMetric $metric): void
    {
        foreach ($blocks as $index => $slide) {
            foreach ($slide->shapes() as $shape) {
                $face = self::faceOf($shape);

                if ($face !== null && !$metric->has($face)) {
                    $this->fonts->record(
                        $face,
                        sprintf('the "%s" box on slide %d', $shape->name(), $index + 1),
                        $metric->faces(),
                    );
                }
            }
        }
    }

    /**
     * The faces the chrome of a template named that the library has no table of advance
     * widths for, recorded.
     *
     * The chrome of a template is held apart from the shapes of the slides: it is read once
     * for the deck and drawn on every slide that names the template, so a walk over the
     * slides never sees it.
     *
     * One entry for the whole template rather than one for each of the slides that draw it,
     * because the face is named in one place: in the chrome. Twenty-eight slides carrying
     * one wordmark is one name to change, and a report of twenty-eight entries would say so
     * twenty-eight times and hide the fact that there is one thing to do. The place is
     * named instead of the slide, so the reader is sent to the template rather than to one
     * of the slides that happens to draw it.
     *
     * @param array<string, list<FreeShape>> $drawn The chrome every template draws.
     */
    public function chrome(array $drawn, TextMetric $metric): void
    {
        foreach ($drawn as $template => $shapes) {
            foreach ($shapes as $shape) {
                $face = self::faceOf($shape);

                if ($face !== null && !$metric->has($face)) {
                    $this->fonts->record(
                        $face,
                        sprintf('the "%s" box of the template "%s"', $shape->name(), $template),
                        $metric->faces(),
                    );
                }
            }
        }
    }

    /**
     * The face a shape names for the type in it, or null where it names none.
     *
     * Two classes carry a {@see \MarkdownPresentation\Render\Free\TextStyle}: the text a
     * deck wrote and the number the renderer writes. Both are drawn in the face their style
     * names and measured in it, so both are a place a face is named — and a walk that
     * looked at one of them would miss the other.
     */
    private static function faceOf(FreeShape $shape): ?string
    {
        return match (true) {
            $shape instanceof FreeText => $shape->style->face,
            $shape instanceof FreeSlideNumber => $shape->style->face,
            default => null,
        };
    }
}
