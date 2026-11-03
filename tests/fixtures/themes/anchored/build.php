<?php

declare(strict_types=1);

/**
 * Writes tests/fixtures/themes/anchored/template.pptx.
 *
 * The template the theme beside it ships. Rebuilt from the description here rather than kept
 * as an opaque binary, so a reader can see which placeholders it carries and why each one is
 * where it is.
 *
 *   php tests/fixtures/themes/anchored/build.php
 */

require_once __DIR__ . '/../../../../vendor/autoload.php';

use MarkdownPresentation\Tests\Support\Template;

Template::write(__DIR__ . '/template.pptx', [
    [
        'name' => 'Title and Content',
        'shapes' => [
            // The title, anchored in the **middle** of its box.
            //
            // The anchor is the text's and not the box's, and it is what a designer uses to
            // say where the words sit inside the box they drew. A reader that took the
            // placeholder's geometry and dropped its anchor drew every title at the top of
            // whatever box it was given, and PowerPoint fell back to the top — which is what
            // an anchor it is not told means.
            //
            // The box is deliberately taller than the type in it, so that the anchor is
            // visible: a title at the top of a tall box and a title in the middle of it are
            // a long way apart.
            ['type' => 'title', 'x' => 838200, 'y' => 1219200, 'cx' => 7315200, 'cy' => 2743200, 'anchor' => 'ctr'],
            ['type' => 'body', 'idx' => 1, 'x' => 838200, 'y' => 4343400, 'cx' => 7315200, 'cy' => 1828800],
        ],
    ],
]);

echo 'wrote ' . __DIR__ . '/template.pptx', "\n";
