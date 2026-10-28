<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\InvalidInput;
use MarkdownPresentation\Exception\MalformedChrome;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Render\Free\FreeShape;
use MarkdownPresentation\Template\Definition;
use MarkdownPresentation\Theme\Theme;

/**
 * The chrome a template draws: the shapes its body is read into.
 *
 * A template is furniture, so its body holds the free shapes and nothing else. Ordinary
 * Markdown in it is refused rather than drawn, because a template is drawn on every slide
 * that names it and the words of a slide would then be on every one of them — a deck whose
 * title appeared twenty-eight times because the title was written into the furniture.
 *
 * The shapes are read once per template and drawn on every slide that names it, rather than
 * read again for each of them: a picture in a template is a picture the deck pays for once.
 */
final class Chrome
{
    /**
     * @param string $directory The directory a relative picture path in the template
     *                          resolves against, which is the directory the deck is in.
     */
    public function __construct(
        private readonly BlockTranslator $blocks,
        private readonly string $directory,
        private readonly PathScope $files,
    ) {
    }

    /**
     * The shapes a template draws.
     *
     * `$slide` is the slide the mistake would first be seen on: the chrome is read once, and
     * a refusal in it has to name a slide a reader can look at.
     *
     * @return list<FreeShape>
     *
     * @throws MalformedChrome
     */
    public function shapes(Definition $definition, int $slide, Theme $theme): array
    {
        try {
            $blocks = $this->blocks->parse($definition->body(), $slide, $this->directory, $this->files, $theme);
        } catch (InvalidInput $failure) {
            // The name of the template, because the slide the message names is the first
            // one the template is drawn on and the author has to know which template it was.
            throw MalformedChrome::in($definition->name(), $failure->getMessage());
        }

        foreach ($blocks->blocks() as $block) {
            throw MalformedChrome::body($definition->name(), self::written($block));
        }

        if ($blocks->columnMarkers() > 0) {
            throw MalformedChrome::marker($definition->name());
        }

        if ($blocks->shapes() === []) {
            throw MalformedChrome::empty($definition->name());
        }

        return $blocks->shapes();
    }

    /**
     * What a block of ordinary Markdown is called, for a message that has to name it.
     *
     * @param Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table $block
     */
    private static function written(Code|Heading|Image|ListBlock|Paragraph|Quote|Rule|Table $block): string
    {
        return match (true) {
            $block instanceof Heading => 'a heading',
            $block instanceof Paragraph => 'a paragraph',
            $block instanceof ListBlock => 'a list',
            $block instanceof Quote => 'a quotation',
            $block instanceof Code => 'a code block',
            $block instanceof Table => 'a table',
            $block instanceof Rule => 'a rule',
            $block instanceof Image => 'a picture',
        };
    }
}
