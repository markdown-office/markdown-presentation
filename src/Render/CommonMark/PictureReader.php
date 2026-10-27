<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\Block\Image as ImageBlock;
use MarkdownPresentation\Render\ConvertedImage;
use MarkdownPresentation\Render\ImageConverter;

/**
 * A picture a deck named, as the block it is drawn from.
 *
 * Its own class because two callers name a picture in two different ways — `![alt](logo.png)`
 * and `::image{src="logo.png"}` — and they must go through the *same* {@see PathScope}, the same
 * conversion and the same refusals. One of them reaching the filesystem on its own would be a
 * picture a deck could point outside the directory it was given, written in a directive rather
 * than in Markdown, which is the one spelling nobody reads the tests for.
 *
 * The two entry points differ only in where the path and the description come from, which is why
 * they are {@see self::node()} and {@see self::path()} rather than one method with two
 * callers each supplying both.
 */
final class PictureReader
{
    public function __construct(
        private readonly ImageConverter $images = new ImageConverter(),
    ) {
    }

    /**
     * The picture an inline `![…](…)` names, described by the label's own words.
     *
     * The description is the label's words with its markup taken out, which is what a screen
     * reader reads. The hand-written walk takes the label as the raw text between the brackets,
     * so a `![*a picture*](x.png)` is described as `a picture` here and as `*a picture*` there.
     *
     * @throws ImageNotFound
     * @throws UnsupportedImageFormat
     */
    public function node(Image $node, SlideState $state, InlineTranslator $inline): ImageBlock
    {
        // Decoded, because that is how a name with a space in it is written: CommonMark has no
        // bare space in a destination, so `my picture.png` is a paragraph and `my%20picture.png`
        // is the picture. league hands the target over encoded, and looking for the encoded
        // spelling on disk is looking for a file nobody has.
        return $this->path(rawurldecode($node->getUrl()), InlineTranslator::text($inline->runs($node, $state->slide, $state->theme)), $state);
    }

    /**
     * The picture a path names, described by whatever the deck called it.
     *
     * @throws ImageNotFound
     * @throws UnsupportedImageFormat
     */
    public function path(string $written, string $alt, SlideState $state): ImageBlock
    {
        if (PathScope::isUrl($written)) {
            throw ImageNotFound::aUrl($state->slide, $written);
        }

        // Where a path is written *from* says nothing about whether it is allowed: what is
        // allowed is where it lands.
        $joined = str_starts_with($written, '/') ? $written : rtrim($state->directory, '/') . '/' . $written;

        if (!is_file($joined)) {
            throw ImageNotFound::at($state->slide, $written, $joined);
        }

        // `realpath()` rather than the path as written, because `..` and a symlink both walk
        // out of a directory that a prefix test says they are inside.
        $resolved = PathScope::canonical($joined);

        if ($resolved === null || !$state->files->holds($resolved)) {
            throw ImageNotFound::outside($state->slide, $written, $resolved ?? $joined, $state->files->roots());
        }

        return new ImageBlock(
            $written,
            $resolved,
            $this->images->png($state->slide, $written, $resolved),
            $alt,
            $state->step,
        );
    }
}
