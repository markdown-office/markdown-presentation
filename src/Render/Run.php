<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

/**
 * A stretch of text inside a paragraph, with what it is emphasised as.
 *
 * Emphasis is per run rather than per paragraph because a paragraph that says
 * `**one** of three` has to survive: dropping the bold would leave the slide saying
 * something its author did not.
*/
final class Run
{
    /**
     * @param ?string $link Where the run goes when it is clicked, or null when it is text.
    */
    public function __construct(
        public readonly string $text,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly bool $code = false,
        public readonly ?string $link = null,
        public readonly bool $strike = false,
    ) {
    }
}