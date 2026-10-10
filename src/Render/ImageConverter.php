<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use GdImage;
use MarkdownPresentation\Exception\UnsupportedImageFormat;

/**
 * Decides whether an image can go into a `.pptx` as it is, and re-encodes it if not.
 *
 * PHPPresentation's two drawing adapters disagree about this, and the disagreement is
 * the whole reason this class exists. `ContentTypes` hard-codes GIF, JPEG, PNG and SVG
 * as the media types a package may declare, and `Drawing\Base64` throws for anything
 * else — but `Drawing\File`, which is what a path is handed to, declares whatever it
 * finds and writes a part the format has no room for. A WebP deck therefore renders
 * without complaint and opens with the picture missing, which is the one outcome this
 * library refuses to produce anywhere else.
 *
 * So a format is checked here, before anything is built, and the two ways of failing
 * are kept apart: a format GD can decode is converted and the deck is written with the
 * picture in it, and a format nothing here can decode is refused. The conversion is
 * visible in the file rather than silent — the part is a PNG, so a deck full of
 * converted photographs is several times the size it was written from.
 */
final class ImageConverter
{
    /**
     * The formats a package may carry, as extensions.
     *
     * Mirrors what `ContentTypes` declares for every package plus what `Drawing\Base64`
     * will accept, minus the two metafile types: those need `phpoffice/wmf`, which is a
     * suggestion rather than a requirement, so a build without it cannot embed them and
     * treating them as carryable would put that back into the same silent failure.
     */
    private const CARRIED = ['gif', 'jpg', 'png', 'svg'];

    /**
     * The most pixels this render will open, and why the number is that one.
     *
     * A decompression bomb is kilobytes on disk: `getimagesize()` reads a header, so a PNG
     * claiming to be 50000 × 50000 is about a hundred bytes and is ten gigabytes of memory
     * the moment GD decodes it. GD's own ceiling arrives as a fatal error rather than as a
     * refusal, which is the failure a deck should not be able to cause at all.
     *
     * 4096 × 4096 is a 4K picture, far past the 1920 × 1080 a slide is drawn at, so no
     * picture worth embedding is refused and a picture this library will not open can be
     * named with its size in the failure.
     */
    public const MAX_PIXELS = 4096 * 4096;

    /**
     * What a MIME type goes into the package under.
     *
     * A `.jpeg` is a JPEG, so the extension a file happens to be called is not what is
     * decided on — the header is, which is what keeps a WebP named `logo.png` from being
     * declared as a PNG and written out as a picture PowerPoint cannot draw.
     *
     * @var array<string, string>
     */
    private const FROM_MIME = [
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
    ];

    /**
     * The image to embed for a file, or null when the file is already in a format the
     * package carries and goes in as it is.
     *
     * @param string $written The path as the deck wrote it, which is what the failure names.
     *
     * @throws UnsupportedImageFormat
     */
    public function png(int $slide, string $written, string $path): ?ConvertedImage
    {
        $format = self::format($slide, $written, $path);

        if ($format === null) {
            throw UnsupportedImageFormat::notAnImage($slide, $written);
        }

        if (in_array($format, self::CARRIED, true)) {
            return null;
        }

        return $this->convert($slide, $written, $path, $format);
    }

    /**
     * The extension an image is really in, or null when the file is not an image at all.
     *
     * SVG is taken on its extension and never measured, because `getimagesize()` cannot
     * read one that sizes itself by `viewBox` — there are no pixels to count — and only
     * learned to read the ones that do give a `width` and a `height` in PHP 8.5, so on
     * the older builds this package supports a perfectly good SVG answers false. Refusing
     * one as a file that is not an image is the failure the extension is here to avoid.
     *
     * The other half of that is that {@see self::MAX_PIXELS} does not apply to an SVG:
     * nothing here opens one, and nothing here could refuse one by size even if it did.
     * What is in the package is the file, byte for byte — see the README for what that
     * means to whoever opens the deck.
     *
     * Every other carried format *is* a raster, so one of those names that will not
     * measure is a file that is not the image it claims to be — a PDF, a download that
     * never finished — and saying so beats embedding it as a PNG.
     */
    private static function format(int $slide, string $written, string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'svg') {
            return 'svg';
        }

        $size = @getimagesize($path);

        if (!is_array($size)) {
            return null;
        }

        // Out of the values already fetched rather than out of another `getimagesize()`:
        // the width and the height are in `$size` and this is the only place the file is
        // measured, so a bomb is refused before anything opens it.
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw UnsupportedImageFormat::tooLarge($slide, $written, $size[0], $size[1], self::MAX_PIXELS);
        }

        return self::FROM_MIME[strtolower($size['mime'])] ?? $extension;
    }

    /**
     * @throws UnsupportedImageFormat
     */
    private function convert(int $slide, string $written, string $path, string $format): ConvertedImage
    {
        // `image/webp` is `imagecreatefromwebp`, and so on for every other format GD can read,
        // which is why the format name is turned into a decoder rather than a table of pairs
        // carried here.
        $decoder = 'imagecreatefrom' . $format;

        // Whether the format was compiled in is answered by the decoder being here at all: a
        // build without it has no function to call. There is no second `imagetypes()` question
        // to ask, because a build that *has* the function and cannot read the file answers
        // false from `decode()` and lands on the same refusal.
        if (!is_callable($decoder)) {
            throw UnsupportedImageFormat::undecodable($slide, $written, $format);
        }

        $image = self::decode($decoder, $path);

        // The half-downloaded image, which is what this is really for: `getimagesize()` reads
        // only a header, so a file whose payload was cut short measures perfectly and is not
        // an image anybody can draw.
        if ($image === false) {
            throw UnsupportedImageFormat::undecodable($slide, $written, $format);
        }

        // Alpha is kept: a PNG written from an image that has blending on drops it, and a
        // logo with a transparent background comes out with a black one.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);

        ob_start();

        // `imagepng()` answers a bool rather than raising, and nothing between this line and
        // the `ob_get_clean()` below can fail either, so there is no buffer to leak and no
        // `finally` that would have had nothing left to do.
        imagepng($image, null);

        return new ConvertedImage((string) ob_get_clean(), $width, $height);
    }

    /**
     * Decode an image, treating any diagnostic GD raises as part of the answer.
     *
     * A truncated file makes GD warn before it answers false, and a warning nobody asked for
     * is one that escapes into whatever is running the render — `@` does not keep it from a
     * handler that is listening. Answering true says the diagnostic was dealt with, and the
     * refusal is then raised deliberately by the caller, where a deck author can read it.
     */
    private static function decode(callable $decoder, string $path): GdImage|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            $image = $decoder($path);

            return $image instanceof GdImage ? $image : false;
        } finally {
            restore_error_handler();
        }
    }
}
