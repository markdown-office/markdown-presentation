<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

/**
 * One thing a slide draws, at a step it appears on.
 *
 * A slide is a list of these and nothing else: the renderer walks it twice — once to
 * measure, once to draw — and both passes read the same list, so what is measured and
 * what is written cannot drift apart.
 *
 * A block is not necessarily a shape. A paragraph and a list are drawn as paragraphs of one
 * {@see \MarkdownPresentation\Render\TextFrame} whatever else the column holds, which is
 * what a reader would build by clicking into a box and typing; a rule, a
 * table, a picture and a code block are shapes of their own, and those are the classes that
 * carry a `name()` for the shape the file holds. A heading and a quote used to be in that list
 * and are not any more: they reach a slide as paragraphs of a {@see \MarkdownPresentation\Render\TextFrame}
 * like a paragraph does, and there is no longer a `name()` on either of them to carry.
*/
interface Block
{
    /**
     * The step this block appears on, counting from one.
     *
     * A slide is written once, holding every block, and a build-up is what the reader clicks
     * through: {@see \MarkdownPresentation\Render\TimingPass} names the shapes each click
     * reveals, so a block's step says which of them it belongs to rather than which slide it
     * was drawn on.
     */
    public function step(): int;
}
