<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * An image that is on disk and that a `.pptx` has no part for.
 *
 * The other two image failures are not this. A path that resolves to nothing is
 * {@see ImageNotFound}, and a file in a format the package can carry is drawn as it
 * is. This is the case where the picture is there and nothing in this build can put
 * it in — and refusing is the whole point: see {@see \MarkdownPresentation\Render\ImageConverter}
 * for why a file this library cannot read would otherwise be written into a deck that
 * then opens with the picture silently absent.
 */
final class UnsupportedImageFormat extends InvalidInput
{
    /**
     * @param string $format What the file turned out to be, as an extension.
     */
    public static function undecodable(int $slide, string $written, string $format): self
    {
        return new self(sprintf(
            'Slide %d shows "%s", which is %s — a format a .pptx carries no part for, and this '
            . 'PHP has no decoder for it either. Convert it to GIF, JPEG, PNG or SVG first.',
            $slide,
            $written,
            strtoupper($format),
        ));
    }

    public static function notAnImage(int $slide, string $written): self
    {
        return new self(sprintf(
            'Slide %d shows "%s", which this PHP cannot read as an image at all — a truncated '
            . 'download, or something that is not one. Convert it to GIF, JPEG, PNG or SVG first.',
            $slide,
            $written,
        ));
    }
}