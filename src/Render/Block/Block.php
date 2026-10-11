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
 * what a reader would build by clicking into a box and typing; a heading, a quote, a rule, a
 * table, a picture and a code block are shapes of their own, and those are the classes that
 * carry a `name()` for the shape the file holds.
*/
interface Block
{
    /**
     * The step this block appears on, counting from one.
     *
     * A slide is written once per step up to the highest one it has, each showing every
     * block whose step has arrived. PowerPoint has no "press a key to reveal this", so
     * the build-up becomes the slides a presenter would click through anyway.
     */
    public function step(): int;
}
