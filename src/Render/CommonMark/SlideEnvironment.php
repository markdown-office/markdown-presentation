<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use MarkdownOffice\CommonMark\Directive\GenericDirectiveExtension;

/**
 * The parser a slide is read with, and the extensions it is read with it.
 *
 * Four of the five extensions here are not optional, and each one is there because a slide
 * draws something the core dialect alone cannot produce:
 *
 * - {@see CommonMarkCoreExtension} is CommonMark: paragraphs, headings, fences, quotes,
 *   lists, rules, links and code spans.
 * - {@see StrikethroughExtension} is `~~struck~~`, which a paragraph turns into a run of its
 *   own. It is a separate extension because GitHub, not CommonMark, added it.
 * - {@see TableExtension} is the pipe table. It is a separate extension for the same reason,
 *   and its cells carry the alignment the theme's column widths are measured from.
 * - {@see MarkerExtension} is `::click`, `::column` and the fences around a region.
 * - {@see GenericDirectiveExtension} is everything else written as a directive: the `:::`
 *   containers and `::note` a Slidev deck is full of, which are refused by name rather than
 *   drawn as the text of a paragraph.
 */
final class SlideEnvironment
{
    public static function build(): EnvironmentInterface
    {
        $environment = new Environment();

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new MarkerExtension());
        $environment->addExtension(new GenericDirectiveExtension());

        return $environment;
    }
}
