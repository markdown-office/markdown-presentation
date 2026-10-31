<?php

declare(strict_types=1);

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\Chart\Series;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Area;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar3D;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Doughnut;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Line;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Pie;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Pie3D;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Radar;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Scatter;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Writer\PowerPoint2007;
use MarkdownPresentation\Tests\Support\Scratch;

/*
 * The charts, checked against the schema Microsoft's own validator reads.
 *
 * **This library writes no charts.** A deck of Markdown is slides, shapes, text and
 * pictures; there is no chart in it and no way to ask for one. What it does carry is a
 * patch of the library that writes the charts, and the patch is what the charts in
 * this file are for: a patch that is re-rolled without a fix is a patch that has lost
 * it, and nothing else in this repository would notice.
 *
 * So the charts are written here, through the patched library, and the two things
 * that can go wrong with them are checked:
 *
 * - **The file is outside the schema.** A chart is a part of its own with a schema of
 *   its own, and the order of the children of a series is a sequence and not a set. The
 *   validator is the oracle, and it is the same one the decks are checked with.
 * - **The file is valid and carries nothing.** A deck that is missing a data point is
 *   a valid deck: the validator reads what is there and says nothing about what is
 *   not. So the elements themselves are asserted on — every kind of series carries the
 *   fill it was given, and every kind of series carries the fill of the point it was
 *   given.
 */

it('writes charts that are valid OOXML', function () {
    skipWithoutDotnetSdk();

    $decks = writeChartDecks();

    expect($decks)->not->toBe([]);

    [$status, $output] = validateWithOpenXml($decks);

    foreach ($decks as $deck) {
        expect($output)->toContain(basename($deck) . ': 0 error(s)');
    }

    expect($status)->toBe(0, $output);
});

it('writes into every kind of series what the series was given', function () {
    $withoutFill = [];
    $withoutPoint = [];

    foreach (chartTypes() as $name => $type) {
        $deck = writeChartDeck($name, $type);

        $children = seriesChildrenOf($deck);

        // The fill of the series itself. The schema names it right after the title,
        // and a chart that does not carry it draws the series in the colour the
        // application chooses rather than the one that was asked for.
        if (!in_array('c:spPr', $children, true)) {
            $withoutFill[] = $name;
        }

        // The fill of one point of the series. The schema names it after the shared
        // part of the series and before the labels.
        if (!in_array('c:dPt', $children, true)) {
            $withoutPoint[] = $name;
        }
    }

    expect($withoutFill)->toBe([], 'these kinds of series carry no fill of their own: ' . implode(', ', $withoutFill));
    expect($withoutPoint)->toBe([], 'these kinds of series carry no data point: ' . implode(', ', $withoutPoint));
});

it('writes the fill of a data point into the data point', function () {
    foreach (chartTypes() as $name => $type) {
        $deck = writeChartDeck($name, $type);

        expect(pointFillsOf($deck))
            ->not->toBe([], $name . ' writes a data point with no fill in it');
    }
});

it('gives a category axis a tick label skip of its own, and not a major unit', function () {
    // A major unit is the distance between the ticks of a value axis; a category axis
    // has no scale for a unit to be a distance between. The writer used to write one
    // as the number of labels to skip, and the number it wrote was a fraction where
    // the schema wants a count.
    $deck = writeChartDeck('axis', Bar::class, majorUnit: 2.5);

    expect(chartPartOf($deck))
        ->not->toContain('c:tickLblSkip');

    expect(axisOf($deck))
        ->not->toContain('c:majorUnit');
});

it('writes the tick label skip of a category axis as a count', function () {
    $deck = writeChartDeck('axis', Bar::class, tickLabelSkip: 3);

    expect(chartPartOf($deck))
        ->toContain('<c:tickLblSkip val="3"/>');
});

/**
 * Every kind of chart the library writes, and the class that writes it.
 *
 * @return array<string, class-string<\PhpOffice\PhpPresentation\Shape\Chart\Type\AbstractType>>
 */
function chartTypes(): array
{
    return [
        'area' => Area::class,
        'bar' => Bar::class,
        'bar3d' => Bar3D::class,
        'doughnut' => Doughnut::class,
        'line' => Line::class,
        'pie' => Pie::class,
        'pie3d' => Pie3D::class,
        'radar' => Radar::class,
        'scatter' => Scatter::class,
    ];
}

/**
 * A deck for every kind of chart, and the axis that carries a major unit.
 *
 * @return list<string> The decks written, as paths.
 */
function writeChartDecks(): array
{
    $decks = [];

    foreach (chartTypes() as $name => $type) {
        $decks[] = writeChartDeck($name, $type);
    }

    // The axis of a category, with the major unit that is not its to carry.
    $decks[] = writeChartDeck('axis', Bar::class, majorUnit: 2.5);

    return $decks;
}

/**
 * A deck with one chart of the given kind in it.
 *
 * The series carries a fill of its own and a point of it carries a fill of its own,
 * because those are the two things a chart can be asked for and not be given.
 *
 * @param class-string<\PhpOffice\PhpPresentation\Shape\Chart\Type\AbstractType> $type
 */
function writeChartDeck(string $name, string $type, ?float $majorUnit = null, ?int $tickLabelSkip = null): string
{
    $presentation = new PhpPresentation();
    $slide = $presentation->createSlide();
    $shape = $slide->createChartShape();

    $series = new Series('Downloads', ['A' => 7, 'B' => 5, 'C' => 3]);
    $series->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('FF4F81BD'));
    $series->getDataPointFill(0)->setFillType(Fill::FILL_SOLID);

    $chart = new $type();
    $chart->addSeries($series);
    $shape->getPlotArea()->setType($chart);

    if ($majorUnit !== null) {
        $shape->getPlotArea()->getAxisX()->setMajorUnit($majorUnit);
    }

    if ($tickLabelSkip !== null) {
        $shape->getPlotArea()->getAxisX()->setTickLabelSkip($tickLabelSkip);
    }

    $slide->addShape($shape);

    $path = Scratch::directoryPath('chart') . '/' . $name . '.pptx';

    $writer = new PowerPoint2007($presentation);
    $writer->save($path);

    return $path;
}

/**
 * The chart part of a deck, as its XML.
 */
function chartPartOf(string $deck): string
{
    $archive = new ZipArchive();
    $opened = $archive->open($deck);

    if (true !== $opened) {
        throw new RuntimeException(sprintf('The deck %s could not be opened.', $deck));
    }

    $part = null;

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = (string) $archive->getNameIndex($index);

        if (str_starts_with($name, 'ppt/charts/chart') && str_ends_with($name, '.xml')) {
            $part = (string) $archive->getFromIndex($index);
            break;
        }
    }

    $archive->close();

    if ($part === null) {
        throw new RuntimeException(sprintf('The deck %s has no chart in it.', $deck));
    }

    return $part;
}

/**
 * The children of the series of a chart, by name.
 *
 * @return list<string>
 */
function seriesChildrenOf(string $deck): array
{
    $xpath = new DOMXPath(chartDomOf($deck));
    $xpath->registerNamespace('c', 'http://schemas.openxmlformats.org/drawingml/2006/chart');

    $series = $xpath->query('//c:ser')->item(0);

    if ($series === null) {
        throw new RuntimeException(sprintf('The deck %s has no series in its chart.', $deck));
    }

    $children = [];

    foreach ($series->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $children[] = 'c:' . $child->localName;
        }
    }

    return $children;
}

/**
 * The chart part of a deck, parsed.
 */
function chartDomOf(string $deck): DOMDocument
{
    $dom = new DOMDocument();
    $dom->loadXML(chartPartOf($deck));

    return $dom;
}

/**
 * The fills of the data points of a chart, as XML.
 *
 * @return list<string>
 */
function pointFillsOf(string $deck): array
{
    $xml = chartPartOf($deck);

    preg_match_all('~<c:dPt>(.*?)</c:dPt>~s', $xml, $matches);

    return $matches[1];
}

/**
 * The axes of a chart, as XML.
 */
function axisOf(string $deck): string
{
    $xml = chartPartOf($deck);

    preg_match_all('~<c:(catAx|valAx)>.*?</c:\1>~s', $xml, $matches);

    return implode("\n", $matches[0]);
}
