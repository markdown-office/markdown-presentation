<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A `::click`, a `::column`, a `:::click` region or the `:::` that closes one, as a node the
 * rest of the walk can read.
 *
 * The line is kept as the deck wrote it, because every refusal here quotes the author's own
 * line: the grammar is captured as a number and a marker, and a message naming `::Click=2`
 * for a deck that wrote `::Click=2` is a message somebody can go and find.
 *
 * A container records whether anything closed it, because CommonMark runs an unclosed
 * container to the end of the input rather than refusing it, and a region that swallowed
 * the rest of a slide is the failure this shape exists to keep named.
 */
final class Marker extends AbstractBlock
{
    private bool $closed = false;

    public function __construct(
        private readonly MarkerKind $kind,
        private readonly ?string $argument,
        private readonly string $written,
    ) {
        parent::__construct();
    }

    public function kind(): MarkerKind
    {
        return $this->kind;
    }

    /**
     * What a writer put after the equals, or null where the marker is the bare form and
     * empty where they wrote `::click=` with nothing after it. The two are not the same
     * marker, and `::click=` is a mistake rather than the marker without a number.
     */
    public function argument(): ?string
    {
        return $this->argument;
    }

    /**
     * The whole line, which is what a refusal quotes.
     */
    public function written(): string
    {
        return $this->written;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * Whether a closing fence was found. Only a region can be closed, and a region that
     * was not is refused rather than read to the end of the slide.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
