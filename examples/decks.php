<?php

declare(strict_types=1);

/*
 * The decks the examples are written as.
 *
 * It is a file of its own because two things need it: the script that writes the decks,
 * and the test that checks what it wrote against the schema. A list of its own in
 * either of them would be a second answer to a question this file answers, and the two
 * would drift — a deck the gallery no longer builds and the gate still checks, or the
 * other way round, which is the worse of the two: a gate that says less than it says.
 *
 * Required rather than included, so that a second caller in the same run does not
 * declare the constants again.
 */

/**
 * The sources this script does not convert, and why.
 *
 * Keyed by the file's stem. A deck is here because converting it is the failure it
 * exists to demonstrate, which is the same reason `markdown-word` does not build its
 * `14-rejected.md`: the build would stop, or — worse — would not stop and the gallery
 * would contain a deck that quietly came out wrong.
 */
const NOT_BUILT = [
    '10-rejected' => 'converting it is the failure it exists to demonstrate; see examples/README.md',
];

/**
 * The sources written more than once, and the one line each copy differs in.
 *
 * A theme is a frontmatter key, so the only way to see the three palettes side by side is
 * to write the same deck three times. Varying it here rather than in three files keeps
 * the three decks identical apart from the one line, which is the point of the
 * comparison.
 */
const VARIANTS = [
    '03-themes' => ['theme' => ['default', 'corporate-dark', 'minimal']],
    '11-type-scale' => ['theme' => ['default', 'dense']],
];
