<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

use MarkdownPresentation\Render\Run;

final class Heading implements Block
{
    /**
     * @param list<Run> $runs
    */
    public function __construct(
        public readonly array $runs,
        public readonly int $level,
        public readonly int $step,
    ) {
    }

    public function step(): int
    {
        return $this->step;
    }
}
