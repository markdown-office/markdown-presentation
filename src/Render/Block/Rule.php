<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

/**
 * The `***` a slide can carry. A rule that is not drawn is a line the author wrote going
 * nowhere, and the dialect says `***` is one.
*/
final class Rule implements Block
{
    public function __construct(
        public readonly int $step,
    ) {
    }

    public function name(): string
    {
        return 'rule';
    }

    public function step(): int
    {
        return $this->step;
    }
}
