<?php

declare(strict_types=1);

/**
 * Writes the empty deck PowerPoint is handed before it authors the fixture beside it.
 *
 *   php tests/fixtures/powerpoint/build/stub.php <out.pptx> <slides> <cx> <cy>
 *
 * Nothing here survives into the fixture. PowerPoint rewrites every part of the deck on save,
 * so this file only has to be one PowerPoint will open; its job is to carry the right number of
 * slides, and the page size the deck is to be authored at.
 *
 * It exists because PowerPoint's scripting interface cannot create a slide: `make new slide`
 * answers "class slide cannot be created", whatever the location or the properties given. A
 * slide has to exist before PowerPoint opens the file, and everything else the deck ends up
 * with — the design, the layouts, the placeholders, the type — is PowerPoint's own.
 */

require dirname(__DIR__, 4) . '/vendor/autoload.php';
require __DIR__ . '/FixtureFailed.php';

const PRESENTATIONML = 'http://schemas.openxmlformats.org/presentationml/2006/main';
const DRAWINGML = 'http://schemas.openxmlformats.org/drawingml/2006/main';
const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const PACKAGE = 'http://schemas.openxmlformats.org/package/2006/relationships';
const TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

/** The line every part here opens with, before its own content. */
const PROLOGUE = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

$path = $argv[1] ?? null;
$slides = (int) ($argv[2] ?? 4);
$cx = (int) ($argv[3] ?? 12192000);
$cy = (int) ($argv[4] ?? 6858000);

if ($path === null || $slides < 1) {
    fwrite(STDERR, "Usage: stub.php <out.pptx> <slides> <cx> <cy>\n");

    exit(1);
}

write($path, $slides, $cx, $cy);

echo 'wrote ', $path, ' — ', $slides, " empty slides on a {$cx} x {$cy} EMU page\n";

/**
 * @throws PowerPointFixtureFailed When the archive cannot be written.
 */
function write(string $path, int $slides, int $cx, int $cy): void
{
    $files = parts($slides, $cx, $cy);
    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new PowerPointFixtureFailed(sprintf('Unable to write the stub at "%s".', $path));
    }

    foreach ($files as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
}

/**
 * Every part of the stub, by name.
 *
 * The relationships are numbered as they are built rather than assumed, because the one thing
 * that makes a stub unreadable rather than merely wrong is a `sldId` pointing at a relationship
 * that is a slide master — PowerPoint offers to repair such a file and then refuses to open it
 * at all, which is a very different failure from a deck that renders wrongly.
 *
 * @return array<string, string>
 */
function parts(int $slides, int $cx, int $cy): array
{
    /**
     * The three namespace declarations every part that draws something carries.
     *
     * Written once because getting one of them wrong is the difference between a deck PowerPoint
     * opens and one it offers to repair, and the three appear on almost every part here.
     */
    $namespaces = ' xmlns:a="' . DRAWINGML . '" xmlns:r="' . RELATIONSHIPS . '" xmlns:p="' . PRESENTATIONML . '"';
    $rels = [];
    $link = static function (string $type, string $target) use (&$rels): string {
        $rels[] = [$type, $target];

        return 'rId' . count($rels);
    };

    $master = $link('slideMaster', 'slideMasters/slideMaster1.xml');
    $ids = '';
    $slides_ = '';

    for ($number = 1; $number <= $slides; $number++) {
        $ids .= sprintf('<p:sldId id="%d" r:id="%s"/>', 255 + $number, $link('slide', "slides/slide{$number}.xml"));
    }

    $link('theme', 'theme/theme1.xml');
    $link('presProps', 'presProps.xml');
    $link('viewProps', 'viewProps.xml');
    $link('tableStyles', 'tableStyles.xml');

    foreach ($rels as $index => [$type, $target]) {
        $slides_ .= sprintf(
            '<Relationship Id="rId%d" Type="%s/%s" Target="%s"/>',
            $index + 1,
            RELATIONSHIPS,
            $type,
            $target,
        );
    }

    $files = [];

    $files['[Content_Types].xml'] = sprintf(
        PROLOGUE . '<Types xmlns="%s">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>%s<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
        . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
        . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
        . '%s'
        . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        . '<Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>'
        . '<Override PartName="/ppt/viewProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.viewProps+xml"/>'
        . '<Override PartName="/ppt/tableStyles.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.tableStyles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>',
        TYPES,
        overrides('/ppt/slideLayouts/slideLayout1.xml', 'slideLayout'),
        overrides('/ppt/slides/slide', 'slide', $slides),
    );

    $files['_rels/.rels'] = sprintf(
        PROLOGUE . '<Relationships xmlns="%s">'
        . '<Relationship Id="rId1" Type="%s/officeDocument" Target="ppt/presentation.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="%s/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>',
        PACKAGE,
        RELATIONSHIPS,
        RELATIONSHIPS,
    );

    $files['docProps/core.xml'] = PROLOGUE
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title></dc:title><dc:creator></dc:creator><cp:lastModifiedBy></cp:lastModifiedBy>'
        . '<cp:revision>1</cp:revision>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">2000-01-01T00:00:00Z</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">2000-01-01T00:00:00Z</dcterms:modified>'
        . '</cp:coreProperties>';

    // Empty on purpose: PowerPoint fills both of these in from the machine it runs on, and this
    // file is thrown away, so there is nothing to gain by pretending otherwise.
    $files['docProps/app.xml'] = PROLOGUE
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
        . '<Application>stub</Application><Slides>' . $slides . '</Slides><Company></Company>'
        . '</Properties>';

    $files['ppt/presentation.xml'] = sprintf(
        PROLOGUE
        . '<p:presentation' . $namespaces . '><p:sldMasterIdLst>'
        . '<p:sldMasterId id="2147483648" r:id="%s"/></p:sldMasterIdLst>'
        . '<p:sldIdLst>%s</p:sldIdLst><p:sldSz cx="%d" cy="%d"/>'
        . '<p:notesSz cx="6858000" cy="9144000"/>'
        . '<p:defaultTextStyle><a:defPPr><a:defRPr lang="en-US"/></a:defPPr></p:defaultTextStyle>'
        . '</p:presentation>',
        $master,
        $ids,
        $cx,
        $cy,
    );

    $files['ppt/_rels/presentation.xml.rels'] = relationships($slides_);

    $tree = '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'
        . '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree>';

    $files['ppt/slideMasters/slideMaster1.xml'] = sprintf(
        PROLOGUE . '<p:sldMaster' . $namespaces . '>'
        . '<p:cSld>%s</p:cSld><p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1"'
        . ' accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6"'
        . ' hlink="hlink" folHlink="folHlink"/><p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/>'
        . '</p:sldLayoutIdLst><p:txStyles><p:titleStyle><a:lvl1pPr><a:defRPr sz="4400"/></a:lvl1pPr></p:titleStyle>'
        . '<p:bodyStyle><a:lvl1pPr><a:defRPr sz="3200"/></a:lvl1pPr></p:bodyStyle>'
        . '<p:otherStyle><a:defPPr/></p:otherStyle></p:txStyles></p:sldMaster>',
        $tree,
    );

    $files['ppt/slideMasters/_rels/slideMaster1.xml.rels'] = relationships(
        relationship(1, 'slideLayout', '../slideLayouts/slideLayout1.xml')
        . relationship(2, 'theme', '../theme/theme1.xml'),
    );

    $files['ppt/slideLayouts/slideLayout1.xml'] = sprintf(
        PROLOGUE . '<p:sldLayout' . $namespaces . ' type="blank" preserve="1">'
        . '<p:cSld name="Stub">%s</p:cSld>'
        . '<p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>',
        $tree,
    );

    $files['ppt/slideLayouts/_rels/slideLayout1.xml.rels'] = relationships(
        relationship(1, 'slideMaster', '../slideMasters/slideMaster1.xml'),
    );

    for ($number = 1; $number <= $slides; $number++) {
        $files["ppt/slides/slide{$number}.xml"] = sprintf(
            PROLOGUE . '<p:sld' . $namespaces . '>'
            . '<p:cSld>%s</p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>',
            $tree,
        );

        $files["ppt/slides/_rels/slide{$number}.xml.rels"] = relationships(
            relationship(1, 'slideLayout', '../slideLayouts/slideLayout1.xml'),
        );
    }

    $files['ppt/presProps.xml'] = PROLOGUE . '<p:presentationPr' . $namespaces . '/>';
    $files['ppt/viewProps.xml'] = PROLOGUE . '<p:viewPr' . $namespaces . '/>';
    $files['ppt/tableStyles.xml'] = PROLOGUE . '<a:tblStyleLst'
        . ' xmlns:a="' . DRAWINGML . '" def="{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}"/>';

    $files['ppt/theme/theme1.xml'] = theme();

    return $files;
}

/**
 * A theme small enough to read and complete enough for PowerPoint: a colour scheme missing any
 * one of its twelve colours is not a theme PowerPoint will open the deck with.
 */
function theme(): string
{
    $colour = static fn (string $name, string $value): string => sprintf(
        '<a:%s><a:srgbClr val="%s"/></a:%s>',
        $name,
        $value,
        $name,
    );

    $fill = '<a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>';

    $line = '<a:lnStyleLst>'
        . '<a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
        . '<a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
        . '<a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'
        . '</a:lnStyleLst>';

    $effect = '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle>'
        . '<a:effectStyle><a:effectLst/></a:effectStyle>'
        . '<a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>';

    $background = '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'
        . '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>';

    return sprintf(
        PROLOGUE . '<a:theme xmlns:a="%s" name="Stub">'
        . '<a:themeElements><a:clrScheme name="Stub">%s</a:clrScheme>'
        . '<a:fontScheme name="Stub"><a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/>'
        . '<a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/>'
        . '<a:cs typeface=""/></a:minorFont></a:fontScheme>'
        . '<a:fmtScheme name="Stub">%s%s%s%s</a:fmtScheme></a:themeElements></a:theme>',
        DRAWINGML,
        implode('', [
            $colour('dk1', '000000'),
            $colour('lt1', 'FFFFFF'),
            $colour('dk2', '44546A'),
            $colour('lt2', 'E7E6E6'),
            $colour('accent1', '4472C4'),
            $colour('accent2', 'ED7D31'),
            $colour('accent3', 'A5A5A5'),
            $colour('accent4', 'FFC000'),
            $colour('accent5', '5B9BD5'),
            $colour('accent6', '70AD47'),
            $colour('hlink', '0563C1'),
            $colour('folHlink', '954F72'),
        ]),
        $fill,
        $line,
        $effect,
        $background,
    );
}

/**
 * The content type override for one part, or for `$count` of them.
 *
 * `sprintf` reads exactly as many arguments as the format has placeholders and ignores the rest,
 * so the count of a variable run of overrides has to be spelled out: a theme written with nine
 * of its twelve colours is one PowerPoint will not open the deck with, and the part that says so
 * is a colour scheme that looks complete.
 */
function overrides(string $prefix, string $kind, int $count = 1): string
{
    $written = '';

    for ($number = 1; $number <= $count; $number++) {
        $part = $count === 1 ? $prefix . '.xml' : $prefix . $number . '.xml';
        $written .= sprintf(
            '<Override PartName="%s" ContentType="application/vnd.openxmlformats-officedocument.presentationml.%s+xml"/>',
            $part,
            $kind,
        );
    }

    return $written;
}

function relationship(int $id, string $type, string $target): string
{
    return sprintf(
        '<Relationship Id="rId%d" Type="%s/%s" Target="%s"/>',
        $id,
        RELATIONSHIPS,
        $type,
        $target,
    );
}

function relationships(string $body): string
{
    return sprintf(
        PROLOGUE . '<Relationships xmlns="%s">%s</Relationships>',
        PACKAGE,
        $body,
    );
}