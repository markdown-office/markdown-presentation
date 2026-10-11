<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Block;

/**
 * A fenced code block, kept as the lines it was written as.
 *
 * The language is kept because the deck said it, and it travels into the shape's
 * description so a reader unzipping the file can see which blocks named which language
 * even though nothing here colours them.
*/
final class Code implements Block
{
    /**
     * @param list<string> $lines
    */
    public function __construct(
        public readonly array $lines,
        public readonly string $language,
        public readonly int $step,
    ) {
    }

    public function name(): string
    {
        return 'code';
    }

    public function step(): int
    {
        return $this->step;
    }
}
