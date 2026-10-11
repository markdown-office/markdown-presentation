<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Theme\LayoutRole;
use PhpOffice\Common\Drawing as CommonDrawing;
use RuntimeException;
use ZipArchive;

/**
 * Gives every layout part real placeholders, and points each slide's title at them.
 *
 * A layout part with an empty shape tree is a label. *Reset Slide* has nothing to restore,
 * the outline view has no title to read, the layout picker offers five blank rectangles, and
 * no theme can move anything — which is what this pass is for. A deck is written against
 * portable roles, so the layout a slide names has to mean something once it reaches the file.
 *
 * It is a pass over the written package rather than shapes added through PHPPresentation,
 * because the library's own `Shape\Placeholder` is written for a shape that is *only* a
 * placeholder and this library's title is not that:
 *
 * - `AbstractSlide::writeShapeText()` names a placeholder shape `"Placeholder for <type>"`
 *   whatever it was called, so a title would stop being the shape named `title` that every
 *   test and every claim about a deck's shapes reads it by.
 * - The same method writes an *empty* `<a:bodyPr/>` for a placeholder, dropping the
 *   `normAutofit` and the insets {@see ShapeWriter::textBox()} sets. On a slide that hands
 *   the shape back to the reader to resize, which is the one failure a layout cannot recover
 *   from; on a layout an empty one is right, and this writes a layout's own either way.
 *
 * Both sides are written by hand below, and the body a slide's title is drawn in is the one
 * the layout's placeholder is written at — so *Reset* restores the geometry the slide already
 * has and moves nothing. That is the whole claim the file has to support, and it is checked
 * rather than assumed: the two boxes are compared against each other in the tests.
 *
 * What this does not write, deliberately:
 *
 * - Prompt text. PowerPoint shows "Click to add title" of its own accord for a placeholder
 *   that is empty, and a reader inventing English on this library's behalf is a string in a
 *   file nobody asked for.
 * - `idx` on a title. It is `0` when the attribute is absent on both sides, and that is how
 *   Office writes the pair. Body placeholders start at `1`, which is the index a slide's body
 *   would have to name.
 * - `txBox`. {@see ShapeWriter}'s text shapes carry it; a placeholder must not, because it
 *   says the shape is a free text box and takes it out of the layout's inheritance.
 */
final class PlaceholderPass
{
    /** The shape tree's own group takes id 1, so a layout's placeholders start at 2. */
    private const FIRST_ID = 2;

    /** What a slide's title shape is referred by, and what a layout's is written as. */
    private const TITLE = '<p:ph type="title"/>';

    /**
     * Rewrites the package in place, and reports how many parts it changed.
     *
     * PHPPresentation numbers the layout parts by the order it finds them in —
     * `ContentTypes::render()` walks `getAllSlideLayouts()` and counts — so the nth role
     * here is `slideLayoutN.xml`. The renderer builds them in that order and never reuses or
     * reorders one, and a test reads the file back to check that the two agree.
     *
     * @param list<LayoutRole> $roles The roles the deck used, in the order their layouts were built.
     *
     * @throws RuntimeException When the presentation cannot be opened for writing, which is
     *         the one failure this can meet: a path that is not a package, or one the
     *         process cannot write.
     */
    public static function applyTo(string $path, SlideGeometry $geometry, array $roles): int
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException(sprintf('Unable to open the presentation at "%s".', $path));
        }

        $changed = 0;

        foreach ($roles as $number => $role) {
            $changed += self::rewrite(
                $zip,
                sprintf('ppt/slideLayouts/slideLayout%d.xml', $number + 1),
                static fn (string $xml): string => self::layoutShapes($xml, $geometry, $role),
            );
        }

        // Read once: `deleteName()` and `addFromString()` keep the count, but a count taken
        // before the loop cannot change under it.
        $parts = $zip->numFiles;

        for ($index = 0; $index < $parts; $index++) {
            $part = (string) $zip->getNameIndex($index);

            if (preg_match('~^ppt/slides/slide\d+\.xml$~', $part) === 1) {
                $changed += self::rewrite($zip, $part, self::titleShape(...));
            }
        }

        $zip->close();

        return $changed;
    }

    /**
     * One part patched, or left alone when it needed nothing — so running this twice is
     * harmless and a deck is written exactly as the writer left it where there is nothing to
     * add.
     */
    private static function rewrite(ZipArchive $zip, string $part, callable $patch): int
    {
        $written = $zip->getFromName($part);

        if ($written === false) {
            return 0;
        }

        $patched = $patch($written);

        if ($patched === $written) {
            return 0;
        }

        $zip->deleteName($part);
        $zip->addFromString($part, $patched);

        return 1;
    }

    /**
     * A layout part with a placeholder for the role's title and one for each of its columns.
     *
     * The `str_contains()` is what makes this idempotent rather than doubling a layout's
     * placeholders, and the part with no shape tree at all is left as it is: a layout this
     * cannot find somewhere to put a shape in is not one this may repair by guessing.
     */
    public static function layoutShapes(string $xml, SlideGeometry $geometry, LayoutRole $role): string
    {
        if (str_contains($xml, '<p:ph ')) {
            return $xml;
        }

        $end = strpos($xml, '</p:spTree>');

        if ($end === false) {
            return $xml;
        }

        $shapes = '';
        $id = self::FIRST_ID;
        $title = $geometry->title($role);

        if ($title !== null) {
            $shapes .= self::shape('Title Placeholder ' . $id, self::TITLE, $id++, $title['box']);
        }

        foreach ($geometry->body($role) as $column => $box) {
            $shapes .= self::shape(
                sprintf('Text Placeholder %d', $id),
                sprintf('<p:ph type="body" idx="%d"/>', $column + 1),
                $id++,
                $box,
            );
        }

        // The shapes go after the last line already in the tree rather than at the position of
        // `</p:spTree>`, which is behind the indentation of that line: inserted there they
        // leave a line of nothing and a `</p:sp></p:spTree>` joined onto one line.
        $break = strrpos(substr($xml, 0, $end), "\n");

        if ($break === false) {
            return $xml;
        }

        return substr($xml, 0, $break + 1) . $shapes . substr($xml, $break + 1);
    }

    /**
     * The slide's own title shape, referred to the layout's title placeholder.
     *
     * Found by the name {@see ShapeWriter::title()} gives it and not by position: the shape
     * tree's group carries a `<p:nvPr/>` of its own, so the first one in the file belongs to
     * no shape at all. A slide with no title shape is returned untouched, which is how a role
     * with no title box — `blank` — reaches the file.
     */
    public static function titleShape(string $xml): string
    {
        $name = strpos($xml, 'name="title"');

        if ($name === false || str_contains($xml, self::TITLE)) {
            return $xml;
        }

        $empty = strpos($xml, '<p:nvPr/>', $name);

        if ($empty === false) {
            return $xml;
        }

        return substr_replace($xml, '<p:nvPr>' . self::TITLE . '</p:nvPr>', $empty, 9);
    }

    /** One placeholder shape, written the way PowerPoint writes one in a layout. */
    private static function shape(string $name, string $placeholder, int $id, Box $box): string
    {
        return sprintf(
            "      <p:sp>\n"
            . "        <p:nvSpPr>\n"
            . '          <p:cNvPr id="%d" name="%s"/>' . "\n"
            . "          <p:cNvSpPr>\n"
            . '            <a:spLocks noGrp="1"/>' . "\n"
            . "          </p:cNvSpPr>\n"
            . "          <p:nvPr>\n"
            . '            %s' . "\n"
            . "          </p:nvPr>\n"
            . "        </p:nvSpPr>\n"
            . "        <p:spPr>\n"
            . "          <a:xfrm>\n"
            . '            <a:off x="%d" y="%d"/>' . "\n"
            . '            <a:ext cx="%d" cy="%d"/>' . "\n"
            . "          </a:xfrm>\n"
            . '          <a:prstGeom prst="rect">' . "\n"
            . "            <a:avLst/>\n"
            . "          </a:prstGeom>\n"
            . "        </p:spPr>\n"
            . "        <p:txBody>\n"
            . "          <a:bodyPr/>\n"
            . "          <a:lstStyle/>\n"
            . "          <a:p>\n"
            . '            <a:endParaRPr lang="en-US"/>' . "\n"
            . "          </a:p>\n"
            . "        </p:txBody>\n"
            . "      </p:sp>\n",
            $id,
            $name,
            $placeholder,
            (int) CommonDrawing::pixelsToEmu($box->x),
            (int) CommonDrawing::pixelsToEmu($box->y),
            (int) CommonDrawing::pixelsToEmu($box->width),
            (int) CommonDrawing::pixelsToEmu($box->height),
        );
    }
}
