<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Slide\Slide as PowerPointSlide;

/**
 * A deck, read back out of a package.
 *
 * The slides in the order the package gives them, which is the order they
 * were put in and not the order their parts are named in: a deck whose slides
 * are `slide1.xml`, `slide2.xml` and `slide10.xml` is not a deck of eleven
 * slides with three of them missing.
 */
final class DeckReader
{
    public function __construct(private readonly Options $options)
    {
    }

    /**
     * The slides of a deck.
     *
     * @return list<Slide>
     */
    public function read(PhpPresentation $deck): array
    {
        $reader = new SlideReader(new ShapeMapper($this->options));
        $slides = [];

        foreach ($deck->getAllSlides() as $index => $slide) {
            $slides[] = $reader->read($slide, $index);
        }

        return $slides;
    }
}
