<?php

declare(strict_types=1);

/*
 * The decks the examples are written as.
 *
 * A file of its own because two things need it: the script that writes the decks, and
 * the test that checks what it wrote. A list of its own in either of them would be a
 * second answer to a question this file answers, and the two would drift.
 *
 * Required rather than included, so that a second caller in the same run does not
 * declare the constants again.
 */

/**
 * The sources this script does not convert, and why.
 *
 * A deck is here because converting it is the failure it exists to demonstrate.
 */
const NOT_BUILT = [
    '10-rejected' => 'converting it is the failure it exists to demonstrate; see examples/README.md',
];

/**
 * The sources written more than once, and the one line each copy differs in.
 *
 * A theme is a frontmatter key, so the only way to see the three palettes side by side
 * is to write the same deck three times.
 */
const VARIANTS = [
    '03-themes' => ['theme' => ['default', 'corporate-dark', 'minimal']],
    '11-type-scale' => ['theme' => ['default', 'dense']],
];
