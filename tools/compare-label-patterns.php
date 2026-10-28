<?php

declare(strict_types=1);

/**
 * Compares the label patterns of this half with the Word half's, and the corpus with
 * CommonMark.
 *
 * A label is plain text, so a label holding a complete Markdown construct cannot have it
 * honoured, and each half says so in its own way. What counts as a construct is
 * CommonMark's own question: a delimiter run that opens an emphasis may not be followed by
 * whitespace, and one that closes it may not be preceded by whitespace.
 *
 * The rules live in a list of patterns in each half, and two lists are two answers to one
 * question. So this reads both of them out of their own sources and runs the same corpus
 * of labels through them, and the corpus says what CommonMark makes of each label.
 *
 * The corpus is the record: `tests/fixtures/labels.txt` names every label and what
 * CommonMark reads in it, and the two markers that differ from it — `markdown-only` and
 * `text-only` — are where the halves' rules and CommonMark's part company. They are
 * recorded rather than left to be found again, so a change to either half's rules has to
 * say what it does about them.
 *
 * The corpus is checked against the reference parser here as well, because that parser is
 * this half's dependency and a marker that is wrong is a record that lies.
 *
 *     php tools/compare-label-patterns.php [path/to/markdown-word]
 *
 * The argument is the Word half's checkout; it defaults to the one beside this repository.
 */

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

$corpus = $root . '/tests/fixtures/labels.txt';

if (!is_file($corpus)) {
    fwrite(STDERR, sprintf("No corpus at \"%s\".\n", $corpus));
    exit(2);
}

$word = $argv[1] ?? dirname($root) . '/markdown-word';

$halves = [
    'slides' => $root . '/src/Render/CommonMark/StyleReader.php',
    'word' => $word . '/src/Render/Directive/TextStyle.php',
];

$patterns = [];

foreach ($halves as $half => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, sprintf("No source at \"%s\".\n", $file));
        exit(2);
    }

    $patterns[$half] = patternsFrom($file);
}

/**
 * The patterns a half keeps its labels away from, read out of its own source.
 *
 * @return list<string>
 */
function patternsFrom(string $file): array
{
    $source = (string) file_get_contents($file);

    if (preg_match('/const\s+MARKDOWN\s*=\s*\[(.*?)\]\s*;/s', $source, $match) !== 1) {
        fwrite(STDERR, sprintf("No MARKDOWN list in \"%s\".\n", $file));
        exit(2);
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $found);

    return array_map(
        static fn (string $raw): string => str_replace(["\\\\", "\\'"], ['\\', "'"], $raw),
        $found[1],
    );
}

/**
 * Whether a label holds a construct the patterns name.
 *
 * @param list<string> $patterns
 */
function holds(array $patterns, string $label): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $label) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * The corpus, as the marker and the label.
 *
 * @return list<array{0: string, 1: string}>
 */
function corpusFrom(string $file): array
{
    $labels = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$marker, $label] = array_pad(explode(' ', $line, 2), 2, '');

        $labels[] = [$label, $marker];
    }

    return $labels;
}

/**
 * What a marker says the halves must make of a label.
 *
 * @return list<string>|null The two verdicts, or null where the marker is one this tool
 *                           does not know.
 */
function expected(string $marker): ?array
{
    return match ($marker) {
        'markdown' => ['markdown', 'markdown'],
        'text' => ['text', 'text'],
        'markdown-only' => ['text', 'text'],
        'text-only' => ['markdown', 'markdown'],
        default => null,
    };
}

/**
 * What CommonMark reads in a label: emphasis, code or a link, or nothing.
 */
function commonMarkReads(string $label): bool
{
    $html = (string) (new League\CommonMark\CommonMarkConverter())->convert($label);

    return preg_match('#<(em|strong|code|a)[ >]#', $html) === 1;
}

$labels = corpusFrom($corpus);
$disagreements = [];
$againstTheCorpus = [];
$gaps = [];
$againstCommonMark = [];
$count = 0;

foreach ($labels as [$label, $marker]) {
    $count++;

    $slides = holds($patterns['slides'], $label) ? 'markdown' : 'text';
    $other = holds($patterns['word'], $label) ? 'markdown' : 'text';

    if ($slides !== $other) {
        $disagreements[] = sprintf('%s: slides says %s, word says %s', $label, $slides, $other);
    }

    if (in_array($marker, ['markdown-only', 'text-only'], true)) {
        $gaps[] = sprintf('%s: %s', $label, $marker);
    }

    $want = expected($marker);

    if ($want === null) {
        $againstTheCorpus[] = sprintf('%s: the corpus says %s, which is no marker at all', $label, $marker);

        continue;
    }

    if ($slides !== $want[0]) {
        $againstTheCorpus[] = sprintf(
            '%s: the corpus says %s, the slides half says %s',
            $label,
            $marker,
            $slides,
        );
    }

    if ($other !== $want[1]) {
        $againstTheCorpus[] = sprintf(
            '%s: the corpus says %s, the word half says %s',
            $label,
            $marker,
            $other,
        );
    }
}

foreach ($labels as [$label, $marker]) {
    $reads = commonMarkReads($label);
    $want = in_array($marker, ['markdown', 'markdown-only'], true);

    if ($reads !== $want) {
        $againstCommonMark[] = sprintf(
            '%s: the corpus says %s, CommonMark says %s',
            $label,
            $marker,
            $reads ? 'markdown' : 'text',
        );
    }
}

printf("Corpus: %d labels.\n", $count);
printf("Patterns: slides %d, word %d.\n", count($patterns['slides']), count($patterns['word']));

if ($gaps !== []) {
    printf("\n%d labels are where the halves differ from CommonMark:\n", count($gaps));

    foreach ($gaps as $gap) {
        printf("  %s\n", $gap);
    }
}

if ($disagreements !== []) {
    printf("\nThe halves disagree on %d labels:\n", count($disagreements));

    foreach ($disagreements as $disagreement) {
        printf("  %s\n", $disagreement);
    }
}

if ($againstTheCorpus !== []) {
    printf("\nThe halves disagree with the corpus on %d labels:\n", count($againstTheCorpus));

    foreach ($againstTheCorpus as $disagreement) {
        printf("  %s\n", $disagreement);
    }
}

if ($againstCommonMark !== []) {
    printf("\nThe corpus disagrees with CommonMark on %d labels:\n", count($againstCommonMark));

    foreach ($againstCommonMark as $disagreement) {
        printf("  %s\n", $disagreement);
    }
}

if ($disagreements === [] && $againstTheCorpus === [] && $againstCommonMark === []) {
    printf("\nThe halves agree with each other, with the corpus and with CommonMark on every label.\n");
}

exit($disagreements === [] && $againstTheCorpus === [] && $againstCommonMark === [] ? 0 : 1);
