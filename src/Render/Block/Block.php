<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

/**
 * One thing a slide draws, at a step it appears on.
 *
 * A slide is a list of these and nothing else: the renderer walks it twice — once to
 * measure, once to draw — and both passes read the same list, so what is measured and
 * what is written cannot drift apart.
*/
interface Block
{
    /**
     * The name this block's shape carries in the file.
     *
     * Read off `ppt/slides/slideN.xml`, this is what turns a deck back into the list of
     * blocks it was written from.
     */
    public function name(): string;

    /**
     * The step this block appears on, counting from one.
     *
     * A slide is written once per step up to the highest one it has, each showing every
     * block whose step has arrived. PowerPoint has no "press a key to reveal this", so
     * the build-up becomes the slides a presenter would click through anyway.
     */
    public function step(): int;
}
