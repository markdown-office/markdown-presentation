<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\Slide;

/**
 * A deck as HTML, for the README's examples.
 *
 * The library renders no HTML — that is the PowerPoint driver's job and it is not
 * written yet — so this is a projection of what a split produced, and it exists so
 * that a page can show the result of running an example rather than describing it.
 * Every value in it is read straight off the deck; nothing is decided here, which
 * is what makes the README claim a claim about the splitter.
*/
final class DeckHtml
{
    public static function render(Deck $deck): string
    {
        $slides = '';

        foreach ($deck->slides() as $slide) {
            $slides .= self::slide($slide);
        }

        return "<ol class=\"deck\">\n" . $slides . "</ol>\n";
    }

    private static function slide(Slide $slide): string
    {
        $frontmatter = $slide->frontmatter();
        $note = $slide->note();

        return sprintf(
            "<li class=\"slide\" data-index=\"%d\">\n%s%s%s</li>\n",
            $slide->index(),
            $frontmatter === [] ? '' : self::frontmatter($frontmatter),
            '<pre>' . self::escape($slide->content()) . "</pre>\n",
            $note === null ? '' : '<aside class="note">' . self::escape($note) . "</aside>\n",
        );
    }

    /**
     * @param array<string, mixed> $frontmatter
    */
    private static function frontmatter(array $frontmatter): string
    {
        $rows = '';

        foreach ($frontmatter as $key => $value) {
            $rows .= sprintf(
                "<dt>%s</dt><dd>%s</dd>\n",
                self::escape((string) $key),
                self::escape(is_string($value) ? $value : (json_encode($value) ?: '')),
            );
        }

        return "<dl class=\"frontmatter\">\n" . $rows . "</dl>\n";
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
