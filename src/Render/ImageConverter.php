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
 * So a format is checked here, before anything is built, and the ways of failing are
 * kept apart: a format GD can decode is converted and the deck is written with the
 * picture in it, and a format nothing here can decode is refused. The conversion is
 * visible in the file rather than silent — the part is a PNG, so a deck full of
 * converted photographs is several times the size it was written from.
 *
 * SVG is a third case and not a fourth one. The format declares a part for it, and
 * writing that part is the silent failure this class exists to prevent wearing a
 * different hat: PHPPresentation points one relationship at both the raster and the
 * vector where PowerPoint's own file has two, so the shape is a picture no reader can
 * draw. The file is therefore drawn — {@see SvgRasteriser} — and the part in the package
 * is a PNG, which means the vector is lost rather than carried.
 */
final class ImageConverter
{
    public function __construct(private readonly SvgRasteriser $svg = new SvgRasteriser())
    {
    }

    /**
     * The formats a package may carry, as extensions.
     *
     * Mirrors what `ContentTypes` declares for every package plus what `Drawing\Base64`
     * will accept, minus SVG and the two metafile types. SVG is not missing by accident:
     * the format has a part for it, and writing that part is a deck whose picture does
     * not appear. The metafiles need `phpoffice/wmf`, which is a suggestion rather than a
     * requirement, so a build without it cannot embed them and treating them as carryable
     * would put that back into the same silent failure.
     */
    private const CARRIED = ['gif', 'jpg', 'png'];

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

        if ($format === 'svg') {
            return $this->vector($slide, $written, $path);
        }

        if (in_array($format, self::CARRIED, true)) {
            return null;
        }

        return $this->convert($slide, $written, $path, $format);
    }

    /**
     * The PNG an SVG is drawn as, or the refusal for a build that cannot draw it.
     *
     * The ceiling is {@see self::MAX_PIXELS}, and it is enforced on the *header* rather than on
     * the file's size, because a vector says how big a picture it is by describing one and the
     * picture is what takes the memory. Reading the size is what keeps the ceiling a ceiling:
     * measured after the drawing, a file of a couple of hundred bytes declaring a 16000-square
     * picture has already had its canvas before anything asks whether it may have one.
     *
     * A file whose size cannot be read is refused rather than drawn. That is the strict end of
     * a short line — the same reader that cannot find the header will not find the drawing — and
     * it is taken because the alternative is a draw with no ceiling on it, which is the whole
     * thing this is here to prevent.
     *
     * @throws UnsupportedImageFormat
     */
    private function vector(int $slide, string $written, string $path): ConvertedImage
    {
        if (!$this->svg->available()) {
            throw new UnsupportedImageFormat(sprintf(
                'Slide %d shows "%s", which is SVG. A .pptx holds a vector only beside a raster of it, and this '
                . 'PHP cannot draw one: ext-imagick is not loaded, or the SVG delegate it was built with will not '
                . 'draw a letter. Install ext-imagick, or put a PNG of the drawing in the deck.',
                $slide,
                $written,
            ));
        }

        $measured = $this->svg->measures($path);

        if ($measured === null) {
            throw self::undecodable($slide, $written);
        }

        if ($measured[0] * $measured[1] > self::MAX_PIXELS) {
            throw UnsupportedImageFormat::tooLarge($slide, $written, $measured[0], $measured[1], self::MAX_PIXELS);
        }

        $raster = $this->svg->png($path);

        if ($raster === null) {
            throw self::undecodable($slide, $written);
        }

        return $raster;
    }

    /**
     * A refusal for a file this build will not draw, said once for the two ways of finding out.
     *
     * They are the same fact about the same file — the delegate cannot read it — reached either
     * from its header or from the draw itself, and a reader told "it would not draw" is not
     * misled by being told it after a measurement rather than after a picture.
     */
    private static function undecodable(int $slide, string $written): UnsupportedImageFormat
    {
        return new UnsupportedImageFormat(sprintf(
            'Slide %d shows "%s", which this build\'s SVG delegate would not draw. Convert it to PNG and '
            . 'put that in the deck instead.',
            $slide,
            $written,
        ));
    }

    /**
     * The extension an image is really in, or null when the file is not an image at all.
     *
     * SVG is taken on its extension and never measured, because `getimagesize()` cannot
     * read one that sizes itself by `viewBox` — there are no pixels to count — and only
     * learned to read the ones that do give a `width` and a `height` in PHP 8.5, so on
     * the older builds this package supports a perfectly good SVG answers false. Refusing
     * one as a file that is not an image is the failure the extension is here to avoid.
     * What it does not know is the size of the raster, which is where {@see self::MAX_PIXELS}
     * reaches it instead — see {@see self::vector()}.
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
