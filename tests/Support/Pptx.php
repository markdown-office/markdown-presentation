<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * A `.pptx` opened up, for asserting on its parts.
 *
 * A test that compared the bytes of two renders would fail about three times in four for the
 * right reasons: a zip stores two-second timestamps in every entry, and `docProps/core.xml`
 * carries a `dcterms:created` and `dcterms:modified` that change with the clock. Two correct
 * renders of the same deck differ, so the parts are what a test reads.
 *
 * Every helper here reads the archive and changes nothing, so a test that fails can be
 * re-run against the same file to find out what was in it.
*/
final class Pptx
{
    private function __construct(
        private readonly string $path,
    ) {
    }

    /**
     * Open a written file, which is a precondition every assertion below shares.
    */
    public static function open(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('No presentation was written to "%s".', $path));
        }

        return new self($path);
    }

    /**
     * Every part in the archive, in the order the zip stores them.
     *
     * @return list<string>
    */
    public function parts(): array
    {
        $zip = $this->handle();
        $parts = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $parts[] = (string) $zip->getNameIndex($index);
        }

        return $parts;
    }

    /**
     * One part's XML, as a string.
     *
     * @throws RuntimeException When the part is not in the file, with the list of what is —
     *                          a test that expected a slide and got none should say which
     *                          slides are there.
    */
    public function part(string $name): string
    {
        $xml = $this->handle()->getFromName($name);

        if ($xml === false) {
            throw new RuntimeException(sprintf(
                'No part "%s" in "%s". The parts are: %s.',
                $name,
                basename($this->path),
                implode(', ', $this->parts()),
            ));
        }

        return $xml;
    }

    /** How many slides the presentation has. */
    public function slideCount(): int
    {
        return count(preg_grep('#^ppt/slides/slide\d+\.xml$#', $this->parts()) ?: []);
    }

    /**
     * The name of every shape on a slide, in the order the file lists them.
     *
     * The shapes are named after the kind of block they came from, which is what makes the
     * deck→slides mapping readable off the file without opening the library: a slide
     * carrying `heading`, `list` and `code` carried those three blocks, in that order.
     *
     * @return list<string>
    */
    public function shapeNames(int $slide = 1): array
    {
        preg_match_all('/<p:cNvPr id="\d+" name="([^"]*)"/', $this->part("ppt/slides/slide{$slide}.xml"), $names);

        // The first belongs to the shape tree the format requires every slide to carry, and
        // is not a shape of the deck.
        return array_values(array_filter($names[1], static fn (string $name): bool => $name !== ''));
    }

    /**
     * Every text run on a slide, in document order.
     *
     * Dotall, because a run's text may itself contain the newlines of a multi-line paragraph.
     *
     * @return list<string>
    */
    public function texts(int $slide = 1): array
    {
        preg_match_all('#<a:t>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</a:t>#s', $this->part("ppt/slides/slide{$slide}.xml"), $texts);

        return $texts[1];
    }

    /** The one-line summary of a text run, so a test can ask what a slide says. */
    public function slideText(int $slide = 1): string
    {
        return implode(' | ', $this->texts($slide));
    }

    /**
     * The colour scheme the theme part carries, as slot name to `#rrggbb`.
     *
     * @return array<string, string>
    */
    public function colourScheme(): array
    {
        $scheme = preg_match(
            '#<a:clrScheme.*?</a:clrScheme>#s',
            $this->part('ppt/theme/theme1.xml'),
            $found,
        ) === 1 ? $found[0] : '';

        preg_match_all(
            '#<a:(dk1|lt1|dk2|lt2|accent\d|hlink|folHlink)>\s*<a:(?:srgbClr val="([0-9A-Fa-f]{6})"|sysClr val="\w+" lastClr="([0-9A-Fa-f]{6})")#',
            $scheme,
            $slots,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        $colours = [];

        foreach ($slots as $slot) {
            // A slot is written either as `srgbClr` or, for dk1 and lt1, as a `sysClr`
            // carrying the real colour in `lastClr`. `PREG_UNMATCHED_AS_NULL` is what makes
            // the one that does not apply null rather than an empty string, which is a
            // colour nobody wrote.
            $colours[$slot[1]] = '#' . strtolower($slot[2] ?? $slot[3] ?? '');
        }

        return $colours;
    }

    /**
     * Whether a colour appears anywhere in the master or any slide.
     *
     * The writer emits hex in the case the `Color` object holds, and a theme's `theme.yml`
     * is upper case while a test reads lower, so the comparison folds case. It is a method
     * rather than something each test repeats because getting that wrong is a test failing
     * against output that is correct.
    */
    public function usesColour(string $hex): bool
    {
        $wanted = ltrim(strtolower($hex), '#');

        foreach ($this->parts() as $part) {
            if (!str_ends_with($part, '.xml')) {
                continue;
            }

            if (!str_contains($part, 'slides/') && !str_contains($part, 'slideMasters/')) {
                continue;
            }

            if (str_contains(strtolower($this->part($part)), $wanted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name each slide layout part carries, keyed by its number.
     *
     * @return array<int, string>
    */
    public function layoutNames(): array
    {
        $names = [];

        foreach ($this->parts() as $part) {
            if (preg_match('#^ppt/slideLayouts/slideLayout(\d+)\.xml$#', $part, $layout) !== 1) {
                continue;
            }

            $names[(int) $layout[1]] = preg_match('#<p:cSld name="([^"]*)"#', $this->part($part), $name) === 1
                ? $name[1]
                : '';
        }

        ksort($names);

        return $names;
    }

    /**
     * Which slide layout a slide is bound to, by the target its rels name.
     *
     * A slide's role is only visible in the file through the layout it points at, so this is
     * what makes "the deck wrote `layout: two-column`" a checkable claim.
    */
    public function layoutOf(int $slide = 1): ?string
    {
        $rels = "ppt/slides/_rels/slide{$slide}.xml.rels";

        if (!in_array($rels, $this->parts(), true)) {
            return null;
        }

        preg_match('#Target="\.\./slideLayouts/slideLayout(\d+)\.xml"#', $this->part($rels), $target);

        return $target[1] ?? null;
    }

    /**
     * The placeholders a layout part carries, and where each one sits.
     *
     * Keyed by what `p:ph` says — `title`, or `body 1` for the body at index 1 — because
     * that is the name a slide's shape has to refer to for the two to be the same
     * placeholder, and the geometry is in the pixels
     * {@see \MarkdownPresentation\Render\SlideGeometry} counts in.
     *
     * @return array<string, array{x: float, y: float, width: float, height: float}>
     */
    public function layoutPlaceholders(int $layout = 1): array
    {
        $found = [];

        foreach (preg_split('#(?=<p:sp>)#', $this->part(sprintf('ppt/slideLayouts/slideLayout%d.xml', $layout))) ?: [] as $shape) {
            // `idx` is absent on a title and `0` means the same thing, so it is only part of
            // the key where the layout wrote it. `PREG_UNMATCHED_AS_NULL` is what makes the
            // one that does not apply null rather than a missing offset.
            if (preg_match('#<p:ph type="([^"]+)"(?: idx="(\d+)")?/>#', $shape, $ph, PREG_UNMATCHED_AS_NULL) !== 1) {
                continue;
            }

            preg_match('#<a:off x="(-?\d+)" y="(-?\d+)"\s*/>\s*<a:ext cx="(\d+)" cy="(\d+)"#', $shape, $offset);

            $found[$ph[2] === null ? $ph[1] : $ph[1] . ' ' . $ph[2]] = [
                'x' => ((int) ($offset[1] ?? 0)) / 9525.0,
                'y' => ((int) ($offset[2] ?? 0)) / 9525.0,
                'width' => ((int) ($offset[3] ?? 0)) / 9525.0,
                'height' => ((int) ($offset[4] ?? 0)) / 9525.0,
            ];
        }

        return $found;
    }

    /**
     * The placeholder a named shape on a slide refers to, as the attributes of its `p:ph`,
     * or null where the shape is not one.
     *
     * By name and not by position, because the shape tree's own group carries a `<p:nvPr/>`
     * too and the first one in the file belongs to no shape.
     */
    public function placeholderOf(string $name, int $slide = 1): ?string
    {
        foreach (preg_split('#(?=<p:sp>|<p:graphicFrame>|<p:pic>)#', $this->part("ppt/slides/slide{$slide}.xml")) ?: [] as $shape) {
            if (preg_match('/<p:cNvPr id="\d+" name="([^"]*)"/', $shape, $found) !== 1 || $found[1] !== $name) {
                continue;
            }

            return preg_match('#<p:ph ([^/]*)/>#', $shape, $ph) === 1 ? $ph[1] : null;
        }

        return null;
    }

    /**
     * The fill colour of the shape named `$name` on a slide, as `#rrggbb`, or null when the
     * shape has no fill.
    */
    public function fillOf(string $name, int $slide = 1): ?string
    {
        foreach ($this->shapes($slide) as $shape) {
            if ($shape['name'] === $name) {
                return $shape['fill'];
            }
        }

        return null;
    }

    /**
     * The size of the type in a shape, in points, or null where the shape has none.
     *
     * The first run, because a shape of several paragraphs is the size of its first one — the
     * question a title's size is asked with is how big the shape reads, and that is set
     * before anything else is put into it. A float, because the writer stores hundredths of
     * a point and 12.5pt is a size a theme can ask for.
     */
    public function fontSize(string $name, int $slide = 1): ?float
    {
        foreach ($this->shapes($slide) as $shape) {
            if ($shape['name'] === $name && $shape['size'] !== null) {
                return $shape['size'];
            }
        }

        return null;
    }

    /**
     * Where a shape sits, in the pixels PHPPresentation writes.
     *
     * @return ?array{x: float, y: float, width: float, height: float}
    */
    public function positionOf(string $name, int $slide = 1): ?array
    {
        foreach ($this->shapes($slide) as $shape) {
            if ($shape['name'] === $name) {
                return [
                    'x' => $shape['x'],
                    'y' => $shape['y'],
                    'width' => $shape['width'],
                    'height' => $shape['height'],
                ];
            }
        }

        return null;
    }

    /**
     * Where every shape of a slide sits, in the order the file lists them.
     *
     * Keyed by position rather than by name because two blocks of the same kind are two
     * entries — a deck with two paragraphs has two shapes both named `paragraph`, and
     * asking for "the" position of a paragraph would answer about the first one.
     *
     * @return list<array{name: string, x: float, y: float, width: float, height: float}>
    */
    public function positions(int $slide = 1): array
    {
        return array_map(
            static fn (array $shape): array => [
                'name' => $shape['name'],
                'x' => $shape['x'],
                'y' => $shape['y'],
                'width' => $shape['width'],
                'height' => $shape['height'],
            ],
            $this->shapes($slide),
        );
    }

    /**
     * Every shape of a slide, in the order the file lists them.
     *
     * A slide carries its own shapes plus the shape tree the format requires, which holds no
     * deck content and is left out. A deck with two blocks of the same kind — two
     * paragraphs — gives two entries here, which is what {@see self::positions()} is for.
     *
     * A pixel is 9525 EMU at the 96 dpi the writer converts at, which is the unit
     * {@see \MarkdownPresentation\Render\SlideGeometry} counts in.
     *
     * @return list<array{name: string, x: float, y: float, width: float, height: float, fill: ?string, size: ?float}>
    */
    private function shapes(int $slide = 1): array
    {
        $xml = $this->part("ppt/slides/slide{$slide}.xml");
        $shapes = [];

        foreach (preg_split('#(?=<p:sp>|<p:graphicFrame>|<p:pic>)#', $xml) ?: [] as $shape) {
            // The split matches `<p:spPr>` as readily as `<p:sp>`, so a fragment that is only
            // a fragment has no name of its own and is skipped rather than borrowing the next
            // shape's.
            if (preg_match('/<p:cNvPr id="\d+" name="([^"]*)"/', $shape, $name) !== 1 || $name[1] === '') {
                continue;
            }

            preg_match('#<a:off x="(-?\d+)" y="(-?\d+)"\s*/>\s*<a:ext cx="(\d+)" cy="(\d+)"#', $shape, $offset);
            preg_match('#<a:solidFill>\s*<a:srgbClr val="([0-9A-Fa-f]{6})"#', $shape, $fill);

            // The writer writes a size in hundredths of a point, as DrawingML does.
            preg_match('#<a:rPr[^>]*\ssz="(\d+)"#', $shape, $size);

            $shapes[] = [
                'name' => $name[1],
                'x' => ((int) ($offset[1] ?? 0)) / 9525.0,
                'y' => ((int) ($offset[2] ?? 0)) / 9525.0,
                'width' => ((int) ($offset[3] ?? 0)) / 9525.0,
                'height' => ((int) ($offset[4] ?? 0)) / 9525.0,
                'fill' => isset($fill[1]) ? '#' . strtolower($fill[1]) : null,
                'size' => isset($size[1]) ? (int) $size[1] / 100 : null,
            ];
        }

        return $shapes;
    }

    private function handle(): ZipArchive
    {
        $zip = new ZipArchive();

        // CHECKCONS because a zip that cannot be read is not a `.pptx` in any sense a test
        // should be asserting about; without it a corrupt file reads as an empty one.
        if ($zip->open($this->path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException(sprintf('"%s" is not a readable zip.', $this->path));
        }

        return $zip;
    }
}