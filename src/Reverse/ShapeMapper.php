<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\Drawing\Base64;
use PhpOffice\PhpPresentation\Shape\Drawing\Gd;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\RichText\Paragraph;
use PhpOffice\PhpPresentation\Shape\Table;

/**
 * A shape read out of a slide, turned into the blocks a deck is written in.
 *
 * The shape of a slide is a set of boxes, and the shape of a deck is a
 * sequence of blocks. This is where the first becomes the second.
 *
 * Two things decide what a shape becomes, and only two:
 *
 * - **What kind of shape it is.** A text box is prose, a table is a table, a
 *   picture is a picture.
 * - **What it says.** A run's bold, italic and strike travel; its size, its
 *   colour and its face do not, because the design of the new deck is not the
 *   design of the old one — and because the label of a text style is plain
 *   text with no escape in it, so a word whose text carries a bracket cannot be
 *   written back as a style at all.
 *
 * A shape the deck placed itself is a third kind, and it is written back as the
 * free shape it is when {@see Options::$preserveGeometry} says to. That is the
 * inverse of the renderer: the box it was given, the colour it was painted and
 * the words in it travel, and the rest of what a shape is does not.
 *
 * The two halves of the work are held by two others: {@see ProseReader} reads the
 * words of a shape, and {@see FreeShapeMapper} reads the shape a deck placed itself.
 */
final class ShapeMapper
{
    /**
     * The picture formats a deck may hold, and the extension each keeps.
     *
     * A `.pptx` holds a vector beside a raster of it, and the raster is the one a
     * reader can show, so the raster is what comes out.
     */
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'tiff', 'webp', 'svg'];

    private readonly ProseReader $reader;

    private readonly FreeShapeMapper $free;

    public function __construct(private readonly Options $options)
    {
        $this->reader = new ProseReader();
        $this->free = new FreeShapeMapper($options, $this->reader);
    }

    /**
     * The blocks a shape becomes.
     *
     * @param int   $slide   The slide's number, counting from one, which names
     *        the pictures written out of it.
     * @param int   $image   How many shapes this slide has already given, which
     *        names the pictures so that they do not collide. It counts the
     *        shapes and not the pictures.
     * @param bool  $outline Whether the shape is a body placeholder, whose
     *        paragraphs are an outline whether or not the slide says so.
     *
     * @return list<Block>
     */
    public function blocks(AbstractShape $shape, int $slide, int $image, bool $outline = false): array
    {
        // A free shape is asked about first, because a free text block is a text box and
        // would otherwise be read as the prose it happens to hold.
        $placed = $this->free->placed($shape);

        if ($placed !== []) {
            return $placed;
        }

        return match (true) {
            $shape instanceof Table => $this->table($shape),
            $shape instanceof Gd || $shape instanceof Base64 => $this->picture($shape, $slide, $image),
            $shape instanceof RichText => $this->reader->prose(
                array_values($shape->getParagraphs()),
                $outline,
                $this->linkOf($shape),
            ),
            default => [],
        };
    }

    /**
     * The blocks a shape's paragraphs become.
     *
     * @param list<Paragraph> $paragraphs
     * @param bool            $outline Whether the shape is a body placeholder,
     *        whose paragraphs are an outline whether or not the slide says so.
     * @param string          $url The address the shape's text is a link to, or
     *        an empty string when it is not one.
     *
     * @return list<Block>
     */
    public function prose(array $paragraphs, bool $outline = false, string $url = ''): array
    {
        return $this->reader->prose($paragraphs, $outline, $url);
    }

    /**
     * The address a shape's text is a link to, or an empty string when it is
     * not one.
     *
     * A link to another slide of the same deck is not an address, and a deck
     * has no way to say "go to slide three", so those are left out rather than
     * written as a link to a file that is not there.
     */
    private function linkOf(RichText $shape): string
    {
        if (! $shape->hasHyperlink()) {
            return '';
        }

        $hyperlink = $shape->getHyperlink();

        if ($hyperlink->isInternal()) {
            return '';
        }

        return $hyperlink->getUrl();
    }

    /**
     * The blocks a table becomes.
     *
     * @return list<Block>
     */
    private function table(Table $shape): array
    {
        $rows = [];

        foreach ($shape->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cells[] = Block::cell($this->reader->prose(array_values($cell->getParagraphs())));
            }

            $rows[] = Block::row($cells);
        }

        if ($rows === []) {
            return [];
        }

        return [Block::table($rows)];
    }

    /**
     * The blocks a picture becomes.
     *
     * The picture carries the path it was read from — a `zip://` URL into the
     * package — and the bytes behind it are the ones the file holds, so the
     * picture that is written out is the picture that was in the deck rather
     * than an encoding of it made again.
     *
     * @return list<Block>
     */
    private function picture(Gd|Base64 $shape, int $slide, int $image): array
    {
        if ($this->options->mediaDirectory === null) {
            return [];
        }

        $readable = $this->readablePicture($shape);

        if ($readable === null) {
            return [];
        }

        [$name, $contents] = $readable;

        return [Block::image(new Image(
            $shape->getDescription(),
            sprintf('slide-%d-image-%d.%s', $slide, $image, pathinfo($name, PATHINFO_EXTENSION)),
            $contents,
        ))];
    }

    /**
     * The bytes of a picture, or nothing when it cannot be read.
     *
     * A picture that cannot be read is left out: a deck with an image reference
     * to nothing is a deck with a broken image in it, which is worse than a
     * deck without the image.
     *
     * @return array{0: string, 1: string}|null The name and the bytes of it.
     */
    private function readablePicture(Gd|Base64 $shape): ?array
    {
        $path = $shape->getPath();

        if ($path === '') {
            return null;
        }

        $name = basename($path);
        $contents = @file_get_contents($path);

        if ($contents === false || !$this->isImage($name, $contents)) {
            return null;
        }

        return [$name, $contents];
    }

    /**
     * Whether a name and the bytes behind it are an image.
     *
     * The extension is checked first because it is free, and because the name
     * is the half that has to end up on disk. A leading dot is refused along
     * with it: `.htaccess` is an "extension" in the loose sense that a split on
     * a dot produces, and it is a configuration file rather than a picture.
     *
     * The bytes are checked because the name is chosen by the deck, and an
     * extension is a claim rather than a fact. `getimagesizefromstring()` knows
     * the raster formats and says so by returning false for everything else —
     * and for a vector, which is why one is recognised by its own markup.
     */
    private function isImage(string $name, string $bytes): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (str_starts_with($name, '.') || !in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        if ($extension === 'svg') {
            return str_contains($bytes, '<svg');
        }

        return @getimagesizefromstring($bytes) !== false;
    }
}
