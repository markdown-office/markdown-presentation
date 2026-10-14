<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use Imagick;
use ImagickPixel;
use Throwable;

/**
 * Draws an SVG, because a `.pptx` written from the file will not.
 *
 * The format does have a part for a vector and `ContentTypes` declares it, and writing
 * that part is what produced a deck PowerPoint cannot show. Its own anatomy is a raster
 * in `a:blip` with an `asvg:svgBlip` extension on the same element pointing at a second
 * relationship; PHPPresentation writes one relationship and points both the raster and
 * the vector at it, so the shape ends up a picture the reader has no way to draw. No
 * schema check sees it — the part and the relationship are well-formed, and only opening
 * the deck shows the gap.
 *
 * GD has no entry point for a vector in any PHP version, so Imagick is the only
 * rasteriser here and it is optional. What it produces is what reaches the deck: the
 * drawing rather than the document that describes it, and a `<text>` is in the picture
 * only as far as this build's delegate draws one — see {@see self::available()}.
 *
 * Not `final`, and that is the seam rather than an oversight. A build with Imagick and a
 * build without it have to be asked the same question in one run, and a subclass that
 * answers {@see self::available()} the other way is what lets the suite do that.
 */
class SvgRasteriser
{
    /**
     * Whether this PHP draws an SVG well enough to be worth trying.
     *
     * Loaded is not enough, and neither is the format being registered: a build with no
     * SVG delegate registers `SVG` anyway and hands the file to ImageMagick's own
     * renderer, which draws the shapes and drops every `<text>` in it. That build is the
     * worst of the three outcomes — it does not fail, it produces a picture that is
     * missing its words — so the question is not whether the format is there but whether
     * drawing this puts ink on the page.
     *
     * The probe draws a letter, because a letter is the part a fallback renderer loses.
     * A rectangle would pass on a build that cannot draw a glyph, which is the build this
     * exists to catch.
     *
     * Worked out once and kept: what a build can draw is a property of the process, not
     * of the file in front of it, and a deck of two hundred diagrams pays for one probe.
     */
    public function available(): bool
    {
        return $this->answer ??= \extension_loaded('imagick')
            && \class_exists(Imagick::class)
            && $this->drawsAGlyph();
    }

    private ?bool $answer = null;

    /**
     * The PNG an SVG becomes, or null when this build will not draw this file.
     *
     * A null is the file rather than the build: a caller that has already asked
     * {@see self::available()} knows a build that cannot draw has been dealt with, and
     * what is left is markup this delegate will not read.
     */
    public function png(string $path): ?ConvertedImage
    {
        $image = new Imagick();

        try {
            // Transparent rather than white, because an SVG is a drawing over whatever the
            // slide is and a PNG flattened onto white paints a box a diagram never had.
            $image->setBackgroundColor(new ImagickPixel('transparent'));
            $image->readImage($path);

            // `png32` keeps an alpha channel; the default `png` quantises it away and an
            // icon drawn over a coloured panel comes out with a halo round it.
            $image->setImageFormat('png32');

            return new ConvertedImage($image->getImageBlob(), $image->getImageWidth(), $image->getImageHeight());
        } catch (Throwable) {
            return null;
        } finally {
            $image->clear();
        }
    }

    /**
     * `protected` rather than private for the same reason the class is not final: `available()`
     * is the only caller, and on a build that cannot draw an SVG this method has one outcome to
     * give, so a test that can only reach it here can never ask what the other one is.
     */
    protected function drawsAGlyph(?string $drawing = null): bool
    {
        $image = new Imagick();

        try {
            $image->setBackgroundColor(new ImagickPixel('transparent'));
            $image->readImageBlob($drawing ?? self::PROBE);
            $image->setImageFormat('png32');

            return $this->drewAnything($image);
        } catch (Throwable) {
            return false;
        } finally {
            $image->clear();
        }
    }

    /**
     * Whether the drawing put any ink on the page.
     *
     * A question about the image rather than about the build, which is what makes it askable
     * on a build whose delegate cannot draw: the answer here is the same on every build, and
     * the build-dependent half of {@see self::drawsAGlyph()} is what feeds it.
     *
     * Alpha rather than colour, because the difference between a glyph drawn and a glyph lost
     * is not a colour this method could name — a black `W` on transparent and no `W` at all
     * produce images with the same colours in them.
     *
     * {@see self::PROBE} on transparent is the case this exists for: a renderer that drops
     * every `<text>` returns an image that is entirely transparent, and one that cannot read
     * the file at all never gets this far.
     */
    protected function drewAnything(Imagick $image): bool
    {
        foreach ($image->getImageHistogram() as $colour) {
            if ($colour->getColorValue(Imagick::COLOR_ALPHA) > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * An SVG whose only content is a letter.
     *
     * Nothing but `<text>`, so a render that comes back empty is one that lost the text
     * rather than one that happened to draw a white shape on white.
     */
    private const PROBE = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="20" viewBox="0 0 40 20">'
        . '<text x="2" y="15" font-size="14" fill="#000000">W</text>'
        . '</svg>';
}