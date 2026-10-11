<?php

declare(strict_types=1);

namespace MarkdownPresentation\Document;

/**
 * One slide of a deck: its text, its own frontmatter, and the speaker note a
 * trailing HTML comment carries.
 *
 * The place the slide occupies in the source is kept as well, because a
 * diagnostic that can name a line of the deck is worth more than one that cannot.
*/
final class Slide
{
    /**
     * @param array<string, mixed> $frontmatter
    */
    public function __construct(
        private readonly int $index,
        private readonly string $raw,
        private readonly string $content,
        private readonly array $frontmatter,
        private readonly ?string $title,
        private readonly ?int $level,
        private readonly ?string $note,
        private readonly int $start,
        private readonly int $end,
    ) {
    }

    /** Zero-based, as the deck reads it. */
    public function index(): int
    {
        return $this->index;
    }
        /** The slide's own lines, frontmatter and all. */
    public function raw(): string
    {
        return $this->raw;
    }

    /** The text, with the frontmatter and the speaker note taken off it. */
    public function content(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
    */
    public function frontmatter(): array
    {
        return $this->frontmatter;
    }

    /**
     * One key of this slide's frontmatter, or `$default` when it is not there.
    */
    public function frontmatterValue(string $key, mixed $default = null): mixed
    {
        return $this->frontmatter[$key] ?? $default;
    }

    /** The frontmatter's title, else its first heading. Null when it has neither. */
    public function title(): ?string
    {
        return $this->title;
    }
        /**
     * How deep the title reads: the depth of the heading it became, or the `level:`
     * the frontmatter named, which is the one that wins where both are there.
     *
     * Not what the renderer sizes a title box from, and deliberately so: this is also
     * the depth a heading that happened to become the title was written at, and a
     * title box drawn at that size would shrink every slide opening on a `##`.
     */
    public function level(): ?int
    {
        return $this->level;
    }
        /** The speaker note a trailing HTML comment carries, if there is one. */
    public function note(): ?string
    {
        return $this->note;
    }

    /** The zero-based line the slide starts on. */
    public function start(): int
    {
        return $this->start;
    }

    /** The zero-based line the slide ends on, exclusive. */
    public function end(): int
    {
        return $this->end;
    }
}
