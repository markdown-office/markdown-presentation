<?php

declare(strict_types=1);

namespace MarkdownPresentation\Template;

/**
 * A template the deck wrote: the name it is drawn under and the Markdown that draws it.
 *
 * The body is kept as written rather than read into shapes here, because the shapes a
 * template draws are read against a theme and a directory, and neither is known while the
 * deck is being split.
 */
final class Definition
{
    public function __construct(
        private readonly string $name,
        private readonly string $body,
    ) {
    }

    /** The name a slide names this template by. */
    public function name(): string
    {
        return $this->name;
    }

    /** The Markdown the template draws, which holds chrome and nothing else. */
    public function body(): string
    {
        return $this->body;
    }
}
