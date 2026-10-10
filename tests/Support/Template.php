<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * A `.pptx` written from a description, for a theme that ships a template.
 *
 * The template under `tests/fixtures/themes/templated/` is one of these, and
 * `tests/fixtures/themes/templated/build.php` rebuilds it. The reader under test has to work
 * on a file rather than on something the test wrote moments earlier, and a committed fixture
 * is what makes that true.
 *
 * The XML is written by hand rather than through PHPPresentation, because the point is to be
 * a *foreign* file — one this library did not produce, on a page that is not 960 × 540, with
 * placeholders in an order of the writer's choosing, one that inherits from its master and
 * one that is not a placeholder at all. A template built by the library would agree with the
 * reader about everything that matters.
 *
 * @phpstan-type Shape array{type?: string, idx?: int, x?: int, y?: int, cx?: int, cy?: int}
 */
final class Template
{
    private const PML = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const DML = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** A stock 16:9 PowerPoint page, which is 1280 × 720 of the pixels a `Box` counts in. */
    public const PAGE = ['cx' => 12192000, 'cy' => 6858000];

    /**
     * @param list<array{name: string, shapes: list<Shape>}> $layouts
     * @param list<Shape>                                    $master
     * @param array{cx: int, cy: int}                        $page The page in EMU, or
     *        `['cx' => 0, 'cy' => 0]` for a presentation that does not say how big a slide is.
     * @param bool $layoutHasMaster Whether a layout's relationships name a master. It is there
     *        so a template whose layouts inherit from nothing can be built; PowerPoint itself
     *        requires exactly one.
     *
     * @phpstan-import-type Shape from self
     */
    public static function write(
        string $path,
        array $layouts = [],
        ?array $master = null,
        array $page = self::PAGE,
        bool $layoutHasMaster = true,
    ): string {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(sprintf('Unable to write the template at "%s".', $path));
        }

        $zip->addFromString('ppt/presentation.xml', sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentation xmlns:p="%s" xmlns:a="%s"><p:sldSz cx="%d" cy="%d"/></p:presentation>',
            self::PML,
            self::DML,
            $page['cx'],
            $page['cy'],
        ));

        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', self::part(
            'p:sldMaster',
            self::shapes($master ?? []),
        ));
        $zip->addFromString(
            'ppt/slideMasters/_rels/slideMaster1.xml.rels',
            self::relationships(self::link('rId1', 'theme', '../theme/theme1.xml')),
        );

        foreach ($layouts as $number => $layout) {
            $zip->addFromString(
                sprintf('ppt/slideLayouts/slideLayout%d.xml', $number + 1),
                sprintf(
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<p:sldLayout xmlns:p="%s" xmlns:a="%s"><p:cSld name="%s"><p:spTree>%s</p:spTree></p:cSld></p:sldLayout>',
                    self::PML,
                    self::DML,
                    $layout['name'],
                    self::shapes($layout['shapes']),
                ),
            );
            $zip->addFromString(
                sprintf('ppt/slideLayouts/_rels/slideLayout%d.xml.rels', $number + 1),
                self::relationships($layoutHasMaster
                    ? self::link('rId1', 'slideMaster', '../slideMasters/slideMaster1.xml')
                        . self::link('rId2', 'theme', '../theme/theme1.xml')
                    : self::link('rId1', 'theme', '../theme/theme1.xml')),
            );
        }

        $zip->close();

        return $path;
    }

    private static function link(string $id, string $kind, string $target): string
    {
        return sprintf(
            '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/%s" Target="%s"/>',
            $id,
            $kind,
            $target,
        );
    }

    private static function relationships(string $body): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="%s">%s</Relationships>',
            self::REL,
            $body,
        );
    }

    private static function part(string $element, string $shapes): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<%s xmlns:p="%s" xmlns:a="%s"><p:cSld><p:spTree>%s</p:spTree></p:cSld></%s>',
            $element,
            self::PML,
            self::DML,
            $shapes,
            $element,
        );
    }

    /**
     * One shape per entry, and the three ways a `p:sp` here differs: a placeholder, a shape
     * that is not one, and a placeholder that states no position of its own.
     *
     * @param list<Shape> $shapes
     *
     * @phpstan-import-type Shape from self
     */
    private static function shapes(array $shapes): string
    {
        $written = '';

        foreach ($shapes as $number => $shape) {
            $written .= sprintf(
                '<p:sp><p:nvSpPr><p:cNvPr id="%d" name="Shape %d"/>%s</p:nvSpPr><p:spPr>%s</p:spPr></p:sp>',
                $number + 2,
                $number + 2,
                isset($shape['type'])
                    ? sprintf(
                        '<p:nvPr><p:ph type="%s"%s/></p:nvPr>',
                        $shape['type'],
                        isset($shape['idx']) ? sprintf(' idx="%d"', $shape['idx']) : '',
                    )
                    : '',
                self::transform($shape),
            );
        }

        return $written;
    }

    /**
     * The `a:xfrm`, or nothing at all.
     *
     * Every corner has to be there for one to be written, which is how PowerPoint itself says
     * "this placeholder is wherever the master puts it": no `a:xfrm`, rather than one full of
     * zeroes. All four being zero *is* written, because that is what a template carries when
     * a shape has been dragged off the page and left there.
     *
     * @param Shape $shape
     *
     * @phpstan-import-type Shape from self
     */
    private static function transform(array $shape): string
    {
        if (!isset($shape['x'], $shape['y'], $shape['cx'], $shape['cy'])) {
            return '';
        }

        return sprintf(
            '<a:xfrm><a:off x="%d" y="%d"/><a:ext cx="%d" cy="%d"/></a:xfrm>',
            $shape['x'],
            $shape['y'],
            $shape['cx'],
            $shape['cy'],
        );
    }
}