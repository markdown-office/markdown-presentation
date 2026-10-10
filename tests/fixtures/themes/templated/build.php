<?php

declare(strict_types=1);

/**
 * Writes tests/fixtures/themes/templated/template.pptx.
 *
 * The template the theme beside it ships. Rebuilt from the description here rather than kept
 * as an opaque binary, so a reader can see which placeholders it carries and why each one is
 * where it is.
 *
 *   php tests/fixtures/themes/templated/build.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use MarkdownPresentation\Tests\Support\Template;

Template::write(__DIR__ . '/template.pptx', [
    [
        'name' => 'Title Slide',
        'shapes' => [
            // A title high on the page and a narrower subtitle under it — further in than
            // this library's own `title` role puts either.
            ['type' => 'ctrTitle', 'x' => 838200, 'y' => 1600200, 'cx' => 10515600, 'cy' => 1325563],
            ['type' => 'subTitle', 'idx' => 1, 'x' => 1676400, 'y' => 3200400, 'cx' => 8382000, 'cy' => 1470025],
        ],
    ],
    [
        'name' => 'Title and Content',
        'shapes' => [
            // Written out of order on purpose: `idx` is what says which column is which, and a
            // reader that took them in the order the file lists them would put them the wrong
            // way round.
            ['type' => 'body', 'idx' => 1, 'x' => 838200, 'y' => 1828800, 'cx' => 10515600, 'cy' => 4114800],
            ['type' => 'title', 'x' => 838200, 'y' => 685800, 'cx' => 10515600, 'cy' => 1000125],
            // A slide number is a placeholder too, and one this library cannot draw in.
            ['type' => 'sldNum', 'idx' => 12, 'x' => 10972800, 'y' => 6350000, 'cx' => 609600, 'cy' => 457200],
        ],
    ],
    [
        'name' => 'Two Content',
        'shapes' => [
            ['type' => 'body', 'idx' => 2, 'x' => 6350000, 'y' => 1828800, 'cx' => 5003800, 'cy' => 4114800],
            ['type' => 'body', 'idx' => 1, 'x' => 838200, 'y' => 1828800, 'cx' => 5003800, 'cy' => 4114800],
            // No box of its own: this is what PowerPoint writes for a layout that does not
            // move the title it inherited, and the reader has to fall back to the master.
            ['type' => 'title'],
        ],
    ],
    // Office's own *Blank*: nothing this library can draw in it, so a role mapped here keeps
    // the geometry the library puts it at.
    ['name' => 'Blank', 'shapes' => []],
], [
    // A rule across the foot of the page — a shape that is not a placeholder, which a real
    // master's page is full of.
    ['x' => 838200, 'y' => 6172200, 'cx' => 10515600, 'cy' => 12700],
    ['type' => 'title', 'x' => 838200, 'y' => 365125, 'cx' => 10515600, 'cy' => 1325563],
]);

echo 'wrote ' . __DIR__ . '/template.pptx', "\n";