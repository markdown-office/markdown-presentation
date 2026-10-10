<?php

declare(strict_types=1);

use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Box;
use MarkdownPresentation\Render\Run;
use MarkdownPresentation\Render\ShapeWriter;
use MarkdownPresentation\Theme\Theme;
use PhpOffice\PhpPresentation\Slide;

/**
 * What the writer emits as a shape, asked of the writer directly.
 *
 * {@see \MarkdownPresentation\Render\PresentationRenderer} clamps everything it lays out, so
 * the clamps inside {@see ShapeWriter} are unreachable through a render. These are the tests
 * that hold the writer to its own invariant: a shape is never written at a geometry the
 * format refuses.
 */

it('writes a shape at no less than one pixel in either direction', function (callable $block) {
    $shape = (new ShapeWriter(Theme::defaults()))->write(
        new Slide(),
        $block(),
        new Box(40, 104, 880, -30),
        -30,
    );

    expect($shape->getWidth())->toBeGreaterThan(0)
        ->and($shape->getHeight())->toBeGreaterThan(0);
})->with([
    // Built lazily: a dataset value is made when the file is loaded, and the image fixture
    // belongs to a scratch directory a file earlier in the alphabet owns.
    'a paragraph' => fn (): Paragraph => new Paragraph([new Run('Text.')], 1),
    'an image' => fn (): Image => new Image('dot.png', png(40, 10), null, '', 1),
]);

it('keeps a picture inside the box it is fitted to', function () {
    // `setWidthAndHeight()` rounds the losing dimension up, so a box one pixel tall comes
    // back as a picture a pixel taller than it — and centring that hangs half of it over
    // the block below.
    $writer = new ShapeWriter(Theme::defaults());
    $image = new Image('wide.png', png(40, 10), null, '', 1);

    $shape = $writer->write(new Slide(), $image, new Box(40, 104, 880, 1), 1);

    expect($shape->getOffsetX())->toBeGreaterThanOrEqual(40.0)
        ->and($shape->getOffsetY())->toBeGreaterThanOrEqual(104.0)
        ->and($shape->getOffsetX() + $shape->getWidth())->toBeLessThanOrEqual(920.0)
        ->and($shape->getOffsetY() + $shape->getHeight())->toBeLessThanOrEqual(105.0);
});