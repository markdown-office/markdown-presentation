<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

use MarkdownPresentation\Render\Run;

final class Paragraph implements Block
{
    /**
     * @param list<Run> $runs
    */
    public function __construct(
        public readonly array $runs,
        public readonly int $step,
    ) {
    }

    public function name(): string
    {
        return 'paragraph';
    }

    public function step(): int
    {
        return $this->step;
    }
}