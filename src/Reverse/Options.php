<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

/**
 * Switches for reading a `.pptx` back into a deck.
 *
 * The points where a `.pptx` and a deck genuinely do not agree, and nothing else:
 * a deck is text and a `.pptx` is a package of positioned boxes, and everything
 * this library has to decide about that difference is a switch here.
 */
final class Options
{
    /**
     * The deepest a list may nest.
     *
     * A list in a deck is a list of items, and an item may hold a list of its
     * own, and nothing says how deep that goes. Reading one is a walk that
     * recurses once per level, so a deck whose items nest without end takes
     * the stack with it: the walk does not return an error, it runs out of
     * stack and the process dies on a signal.
     *
     * Thirty-two is far past any list a person writes, and the ceiling is
     * checked where the nesting is read rather than after it, so a deck that
     * goes past it is refused rather than fatal.
     */
    public const MAX_NESTING = 32;

    /**
     * @param string|null $mediaDirectory A directory the pictures are taken out
     *        into. A relative one is resolved against the directory of the
     *        Markdown the pictures are written beside, because the reference to
     *        them in that Markdown is relative to it; an absolute one is used as
     *        it stands. Null leaves the pictures out of the deck altogether,
     *        which is the only honest answer: a deck that refers to a picture
     *        with no file behind it is a deck with a broken image in it.
     * @param int         $maxPartBytes The largest one part of the package may be
     *        once decompressed. A `.pptx` is somebody else's file, and a zip says
     *        nothing about how much room its contents will take up.
     * @param int         $maxEntries The largest number of parts the package may
     *        have, read from its central directory and costing no decompression.
     */
    public function __construct(
        public readonly ?string $mediaDirectory = 'media',
        public readonly string $lineEnding = "\n",
        public readonly int $maxPartBytes = 268435456,
        public readonly int $maxEntries = 4096,
    ) {
    }

    public function withMediaDirectory(?string $directory): self
    {
        return new self($directory, $this->lineEnding, $this->maxPartBytes, $this->maxEntries);
    }

    public function withLineEnding(string $ending): self
    {
        return new self($this->mediaDirectory, $ending, $this->maxPartBytes, $this->maxEntries);
    }

    public function withMaxPartBytes(int $bytes): self
    {
        return new self($this->mediaDirectory, $this->lineEnding, $bytes, $this->maxEntries);
    }

    public function withMaxEntries(int $entries): self
    {
        return new self($this->mediaDirectory, $this->lineEnding, $this->maxPartBytes, $entries);
    }
}
