<?php

declare(strict_types=1);

namespace MarkdownPresentation\Exception;

/**
 * An image that is on disk and that a `.pptx` has no part for, or that is larger than
 * this render will open.
 *
 * The other image failures are not this. A path that resolves to nothing, or to something
 * outside the directories the renderer was given, is {@see ImageNotFound}, and a file in a
 * format the package can carry is drawn as it is. This is the case where the picture is
 * there and nothing in this build can put it in, or where opening it is what would hurt —
 * see {@see \MarkdownPresentation\Render\ImageConverter} for why a file this library
 * cannot read would otherwise be written into a deck that then opens with the picture
 * silently absent.
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

    /**
     * @param int $maximum {@see \MarkdownPresentation\Render\ImageConverter::MAX_PIXELS},
     *                     named in the message because a limit a reader cannot picture is a
     *                     limit they cannot argue with either.
     */
    public static function tooLarge(int $slide, string $written, int $width, int $height, int $maximum): self
    {
        return new self(sprintf(
            'Slide %d shows "%s", which measures %d x %d pixels. Decoding it is about %s of memory, and this '
            . 'render opens no picture over %d pixels: scale it down before putting it in a deck.',
            $slide,
            $written,
            $width,
            $height,
            self::gigabytes($width * $height),
            $maximum,
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

    /**
     * What decoding `$pixels` of truecolor would take, in the largest unit that reads.
     *
     * Four bytes to the pixel, which is what `imagecreatetruecolor()` gives. Rounded, because
     * the number is there to put the file's header and the memory it would exhaust on one
     * scale rather than to be exact.
     */
    private static function gigabytes(int $pixels): string
    {
        return sprintf('%.1f GB', $pixels * 4 / 1024 ** 3);
    }
}
