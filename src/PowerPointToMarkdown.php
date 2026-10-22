<?php

declare(strict_types=1);

namespace MarkdownPresentation;

use MarkdownPresentation\Exception\FileNotWritable;
use MarkdownPresentation\Exception\NothingToConvert;
use MarkdownPresentation\Exception\UnreadableDeck;
use MarkdownPresentation\Reverse\Block;
use MarkdownPresentation\Reverse\DeckReader;
use MarkdownPresentation\Reverse\DeckWriter;
use MarkdownPresentation\Reverse\Options;
use MarkdownPresentation\Reverse\Package;
use MarkdownPresentation\Reverse\Slide;
use PhpOffice\PhpPresentation\PhpPresentation;

/**
 * Converts a PowerPoint deck back into Markdown — the inverse of
 * {@see \MarkdownPresentation\Render\PresentationRenderer}, so a deck can be
 * taken apart, edited as text, and put back together in another design.
 *
 * The result is a deck markdown: the format
 * {@see \MarkdownPresentation\Document\DeckParser} reads, which is the format
 * this library writes decks in. So a deck that goes out of here can go straight
 * back in through {@see \MarkdownPresentation\Render\PresentationRenderer}, with
 * a different theme and a different layout.
 *
 * What the round trip keeps and what it does not is written down in the
 * README, and the short of it is this: the text and the structure travel, the
 * design does not. A `.pptx` records what is on a slide, not how it was drawn —
 * the type sizes, the colours and the geometry are in the theme and the
 * master, and neither travels to the new deck. What comes back is the words,
 * the order they were in, and the pictures.
 */
final class PowerPointToMarkdown
{
    /**
     * @param string|null $source The deck to convert: a path, or the bytes of
     *        one. Null leaves the choice to {@see self::convert()}.
     */
    public function __construct(
        private readonly ?string $source = null,
        private readonly Options $options = new Options(),
    ) {
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * The deck, as Markdown.
     *
     * @throws NothingToConvert when no source was given.
     * @throws UnreadableDeck when the source is not a deck this can read.
     * @throws FileNotWritable when there is a target and it cannot be written.
     */
    public function convert(?string $target = null): string
    {
        if ($this->source === null) {
            throw NothingToConvert::noSource();
        }

        $package = Package::open($this->source, $this->options);

        try {
            return $this->write($package, $target);
        } finally {
            $package->close();
        }
    }

    /**
     * The deck, written to a file.
     *
     * @throws FileNotWritable when the target cannot be written.
     */
    public function save(string $target): void
    {
        $this->convert($target);
    }

    /**
     * The deck, as Markdown, from the bytes of one.
     *
     * @throws UnreadableDeck when the bytes are not a deck this can read.
     */
    public function toMarkdown(string $bytes): string
    {
        $package = Package::fromString($bytes, $this->options);

        try {
            return $this->write($package, null);
        } finally {
            $package->close();
        }
    }

    /**
     * The slides of a deck, for callers that want to look at them rather than
     * serialise them.
     *
     * @return list<Slide>
     * @throws UnreadableDeck when the source is not a deck this can read.
     */
    public function read(string $path): array
    {
        $package = Package::open($path, $this->options);

        try {
            return (new DeckReader($this->options))->read($package->deck());
        } finally {
            $package->close();
        }
    }

    private function write(Package $package, ?string $target): string
    {
        $deck = $package->deck();
        $slides = (new DeckReader($this->options))->read($deck);

        $markdown = (new DeckWriter($this->options))->write($slides, $deck->getDocumentProperties());

        $this->writeMedia($slides);

        if ($target !== null) {
            self::writeTo($target, $markdown);
        }

        return $markdown;
    }

    /**
     * Write every picture the deck holds into the media directory.
     *
     * A picture that is already there is left alone: the deck did not get to
     * overwrite a file that was written first, which is what keeps a second
     * conversion of the same deck from replacing what the first wrote.
     *
     * @param list<Slide> $slides
     *
     * @throws FileNotWritable when the directory or a file in it cannot be written.
     */
    private function writeMedia(array $slides): void
    {
        $directory = $this->options->mediaDirectory;

        if ($directory === null) {
            return;
        }

        if (! is_dir($directory) && ! self::makeDirectory($directory)) {
            throw FileNotWritable::cannotCreateMediaDirectory($directory);
        }

        foreach ($this->pictures($slides) as $name => $contents) {
            $target = $directory . '/' . $name;

            if (file_exists($target)) {
                continue;
            }

            self::writeTo($target, $contents);
        }
    }

    /**
     * The pictures of a deck, by the name they are written under.
     *
     * @param list<Slide> $slides
     *
     * @return array<string, string>
     */
    private function pictures(array $slides): array
    {
        $pictures = [];

        foreach ($slides as $slide) {
            foreach ($slide->blocks as $block) {
                $image = $block->image;

                if (! $block->is(Block::IMAGE) || $image === null) {
                    continue;
                }

                $pictures[$image->target] = $image->contents;
            }
        }

        return $pictures;
    }

    /**
     * Put bytes in a file, through a staged copy.
     *
     * The file is moved into place rather than opened where it lies, because
     * `file_put_contents()` opens an existing name with `O_TRUNC` and a name can
     * be a hard link — the caller naming `alias.md` for a deck that is
     * `original.md`, where the two are one inode, would empty the file still
     * being written. Moving replaces the name and leaves the inode alone.
     *
     * The staged copy is a file in the target's own directory, the only one
     * `rename()` can move within a single filesystem.
     *
     * @throws FileNotWritable when the target cannot be written.
     */
    private static function writeTo(string $target, string $contents): void
    {
        $directory = \dirname($target);

        // A path given to a converter rarely has its directory made for it, and
        // a rename does not create one.
        if (! is_dir($directory) && ! self::makeDirectory($directory)) {
            throw FileNotWritable::cannotCreateDirectory($directory);
        }

        // The bytes go into a file beside the target and the file is moved into
        // place, so that a target which is a hard link to something else is
        // emptied only when the move succeeds. The staging file is beside the
        // target because a rename across a filesystem boundary fails.
        $staged = $directory . '/.mdpresentation-' . bin2hex(random_bytes(8));

        $written = @file_put_contents($staged, $contents);

        if ($written !== false && ! is_dir($target) && @rename($staged, $target)) {
            return;
        }

        // The write failed, or the move did, and the staged file is still there.
        @unlink($staged);

        throw FileNotWritable::cannotWrite($target);
    }

    /**
     * Whether a directory was made, and whether it could be.
     *
     * `mkdir()` is asked only about a path that could be one. A path that is
     * already there as something else cannot be made into a directory, and
     * asking produces a warning that says nothing the caller does not already
     * know — and a warning nobody can act on is noise in a test run.
     *
     * The parent is looked at first, because a path whose parent is a file
     * cannot be made either, and `mkdir()` would fail on it rather than on the
     * path itself.
     */
    private static function makeDirectory(string $directory): bool
    {
        if (file_exists($directory)) {
            return false;
        }

        $parent = \dirname($directory);

        if ($parent !== $directory && ! is_dir($parent) && ! self::makeDirectory($parent)) {
            return false;
        }

        return @mkdir($directory, 0o777, true) && is_dir($directory);
    }
}
