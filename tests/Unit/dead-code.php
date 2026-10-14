<?php

declare(strict_types=1);

use MarkdownPresentation\Tests\Support\Doctest;

/*
 * Claims that were wrong, kept so they cannot come back.
 *
 * The comment policy in the sibling package bans dividers, restatement, type-only
 * docblocks and counts, and this is what makes the first of those a rule rather than
 * a note. The second half is here for the same reason it is there: a confidently
 * false docblock passes every other check in the suite, because nothing reads prose.
 * Each phrase below was a claim this package could not support — add one when the
 * next is corrected, which is the whole maintenance cost of the list.
 */

/**
 * Every file under the given directories, as paths relative to the project root.
 *
 * @param list<string> $areas
 *
 * @return list<string>
 */
function filesUnder(array $areas): array
{
    $files = [];

    foreach ($areas as $area) {
        $found = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(Doctest::root() . '/' . $area, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($found as $file) {
            if ($file->isFile()) {
                $files[] = $area . '/' . $file->getFilename();
            }
        }
    }

    return $files;
}

/**
 * Where each top-level constant of a test file is declared.
 *
 * Pest includes every test file into one global namespace, so a constant two of them
 * declare is one constant declared twice — which PHP warns about on every run and says
 * will be an error in PHP 9. Nothing about the run says so: PHPUnit turns the warning into
 * a non-zero exit with every test passing and, under pcov, with no coverage table either.
 *
 * @return array<string, list<string>> Constant name to the files declaring it.
 */
function constantDeclarations(): array
{
    $declared = [];

    foreach (array_merge(['tests/Pest.php'], filesUnder(testSuiteDirectories())) as $file) {
        foreach (file(Doctest::root() . '/' . $file) ?: [] as $line) {
            if (preg_match('/^const\s+([A-Za-z_][A-Za-z_0-9]*)\s*=/', $line, $name) === 1) {
                $declared[$name[1]][] = $file;
            }
        }
    }

    ksort($declared);

    return array_filter($declared, static fn (array $files): bool => count($files) > 1);
}

/**
 * The lines of every PHP file under the given directories that `$pattern` matches.
 *
 * One walk for the two tests below that are otherwise the same shape, because a policy
 * checked by two copies of the same loop is one of them.
 *
 * @param list<string> $areas Paths relative to the project root.
 *
 * @return list<string> Each as `file:line` and the line itself.
 */
function linesMatching(array $areas, string $pattern): array
{
    $found = [];

    foreach ($areas as $area) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(Doctest::root() . '/' . $area, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $found[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }
    }

    return $found;
}

/**
 * The directories the suite runs its tests from, read off `phpunit.xml.dist`.
 *
 * Read rather than written down, because a directory added to a suite without being added
 * here would be a file nobody checks — and the exemption the imports test below relies on
 * is about *test files*, so it has to follow the suites rather than a copy of them.
 *
 * @return list<string>
 */
function testSuiteDirectories(): array
{
    $suites = [];

    foreach (simplexml_load_file(Doctest::root() . '/phpunit.xml.dist')->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            $suites[] = (string) $directory;
        }
    }

    return $suites;
}

/**
 * The files holding a phrase, so a claim that has been corrected cannot come back
 * unnoticed.
 *
 * Its own walk rather than {@see linesMatching()} because it labels each hit with the
 * directory it was found in, and because it has to skip this file: the list of phrases
 * below is the phrases themselves, so this file necessarily holds all of them.
 *
 * @return list<string>
 */
function sourcesWithClaim(string $claim): array
{
    $found = [];

    foreach (['src', 'tests', 'tools'] as $area) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(Doctest::root() . '/' . $area, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || !$file->isFile() || $file->getFilename() === basename(__FILE__)) {
                continue;
            }

            if (holdsClaim((string) file_get_contents($file->getPathname()), $claim)) {
                $found[] = $area . '/' . $file->getFilename();
            }
        }
    }

    $pages = pagesWithClaim($claim);
    sort($found);
    sort($pages);

    return array_merge($found, $pages);
}

/**
 * Whether a text holds a phrase, whatever case it is written in.
 *
 * Folding the case is what makes this a guard rather than a lookup: the sentence a claim
 * lives in begins with a capital often enough that a case-sensitive `str_contains()` misses
 * it, and a guard that can be walked past by starting a sentence is not a guard. The
 * README's "A marker closing a list item is not honoured" sat under a ban on "a marker
 * closing a list item" for exactly this reason, through a release of a suite that passed.
 */
function holdsClaim(string $text, string $claim): bool
{
    return str_contains(mb_strtolower($text), mb_strtolower($claim));
}

/**
 * The pages and the decks, which are read by people rather than executed.
 *
 * The gallery and the decks beside it are here because a deck that describes what it
 * demonstrates has been wrong about it before: the slides are built by a test, and what
 * they *say* about themselves is not.
 *
 * @return list<string>
 */
function pagesWithClaim(string $claim): array
{
    $root = Doctest::root();
    $found = [];

    foreach ([$root . '/README.md', $root . '/CHANGELOG.md', $root . '/CONTRIBUTING.md'] as $page) {
        if (is_file($page) && holdsClaim((string) file_get_contents($page), $claim)) {
            $found[] = basename($page);
        }
    }

    foreach ([
        'examples/' => glob($root . '/examples/README.md') ?: [],
        'examples/markdown/' => glob($root . '/examples/markdown/*.md') ?: [],
    ] as $prefix => $pages) {
        foreach ($pages as $page) {
            if (holdsClaim((string) file_get_contents($page), $claim)) {
                $found[] = $prefix . basename($page);
            }
        }
    }

    return $found;
}

it('does not claim again what the code does not do', function (string $claim) {
    expect(sourcesWithClaim($claim))->toBe([]);
})->with([
    'a thematic break inside a slide can be written as ---' => 'thematic break can be written as `---`',
    'the deck frontmatter is a block of its own' => 'frontmatter is a block of its own',
    'an unknown theme falls back to the default one' => 'falls back to the default theme',
    'a styles.yml value reaches the shapes' => 'styles.yml value is applied',
    'a directive nobody acts on is passed over' => 'an unknown directive is ignored',
    'a directive nobody reads is drawn as the words the deck wrote it' => 'draws a directive it does not read',
    'a v-click marker closing a list item is honoured' => 'a marker closing a list item',
    // The old spellings, as claims rather than as syntax: `CHANGELOG.md` has to be able to
    // name what a deck used to be written in, so a phrase that only says "<!-- v-click"
    // would fire on the history that documents the change rather than on a wrong page.
    'this format still takes an html comment as a step' => 'A step is the comment',
    'a trailing html comment is still the note' => 'an HTML comment that runs to the end of a slide',
    'an image is embedded whatever format it is in' => 'embedded unchanged, whatever format the deck named it in',
    'the package cannot write a pptx' => 'it renders nothing',
    'a deck has no other way to name who made it' => 'the deck has no other way to say who made it',
    'the page behind every slide is always lt1' => 'becomes the page behind every slide',
    'accent1 paints the headings' => 'rules, bullets, headings',
    'a theme that ships a template has its layouts ignored' => 'A theme\'s `template.pptx` is not opened',
    'a theme differs from another only in its colours' => 'Nothing else about it differs',
    'the heading scale runs down to the sixth level' => 'stops shrinking a heading at the sixth level',
    'the roles are the five a deck needs most' => 'The roles are `title`, `content`, `two-column`, `section` and `blank`',
    'a frame carries a gap after every line it wrapped onto' => 'after every line of the frame',
    'a heading is painted in the theme accent and reaches the shape as a literal hex' => 'Everything that reaches a shape reaches',
    'the ceiling story reads as still hanging' => 'hangs the process rather than failing it',
    // A step was a slide, and every page that said so had to be corrected at once. The
    // phrases are what each of them said rather than the idea, and they are pinned to the
    // `::click` spelling the live claims used: `CHANGELOG.md` quotes the old `v-click` message
    // verbatim as history, and a guard that fires on it would be guarding the record of the
    // change rather than the pages it changed.
    'a step is a slide of its own' => 'every step of a build-up is written as a `.pptx` part',
    'a marker writes another copy of the slide' => 'becomes the slides a presenter would click through',
    'a step over the ceiling multiplied into written slides' => 'Every "::click" is written as a slide of its own',
    'a deck of markers asks for more written slides than it has' => 'asks for 800 written slides',
    'a build-up is measured once per step' => 'each revealing everything the one before it had',
    'the two passes over the written package' => 'runs the two passes over it',
    // Columns divided at a `##` until `::column` said otherwise. The phrase is the sentence
    // the README, the gallery and the renderer's own docblock all gave as the reason, and
    // none of them can now: a heading is an ordinary heading. `CHANGELOG.md` has to be able
    // to name what a two-column deck used to be written in, so the phrase is the reasoning
    // rather than the spelling — the entry says the slide divided at its first `##` without
    // claiming that a heading divides anything.
    'a heading is what puts a block in the next column' => 'a writer puts on a column of a slide',
    // Whether a trailing `::column` was refused used to depend on whether a block followed
    // it: the marker at the end of a slide was drawn where the same marker with text after
    // it was a failure, and the test above it said that was deliberate. The renderer measures
    // the layout against the count of markers now, so nothing a deck writes after its last
    // marker changes what happens to it, and no page may argue the other way.
    'whether a trailing ::column is refused depends on what follows it' => 'Refusing here would make the spelling of a column depend on what happens to follow it',
    // A region used to keep its column to itself, which is what the walk's own docblock and a
    // test in the suite both said. A column is where a block is drawn and a region is when it
    // appears, so the marker written inside one now moves what follows the closer — and a page
    // or a comment may not argue that what is inside a region is where the opener was.
    'a region keeps its column to itself' => 'what it holds stays in the column it was written in',
    'a region keeps the column it was written in' => 'what is inside one belongs where the opener was',
    // `column()` kept the buffers flushed by its caller because four of them beside its own
    // arguments is past `php:S107`, which is a number the signature no longer has to keep.
    // The reason it still gives is the one that holds: it is static and the flush is not.
    'a method keeps work out of it because of how many parameters it takes' => 'taking them as well is eight parameters',
    // `SlideBlocks`'s second guard was described as reachable: the walk hands a region the
    // marker count it was given rather than counting from nothing, so the two move together
    // and no deck can leave a block in a column the count has not reached. The guard is for a
    // caller that builds the value, and nothing in a deck reaches it.
    'the region recursion can break the marker count' => 'a walk that reads a region returns a count of its own',
    // `php:S1448` fires above twenty, so the parser was at twenty-one once already and was a
    // finding on new code rather than one still waiting for the next method. The claim was
    // about the block parser of the time, which `BlockTranslator` has now replaced.
    'one more method and the parser is over the ceiling' => 'the next method still breaks that gate',
    // An SVG was carried into the package as an `image/svg+xml` part, on the strength of
    // the format declaring one — a deck that opened with the diagram missing, and nothing
    // in the repository said so. It is drawn now, so a page may not say the file rides
    // along or that it is the one image nothing is done to. The phrase is the reasoning
    // rather than the spelling so `CHANGELOG.md`, which has to be able to name what an
    // SVG used to be written as, is not guarded instead of the live claims.
    'an svg is carried into the package as the file itself' => 'the only one of these three that is not re-encoded',
    'an svg is put in the deck unexamined' => 'written into the package byte for byte',
]);

it('has no decorative dividers in it', function () {
    // A rule of dashes above a run of methods says what the method names below it
    // already say, in seventy characters, on every read of the file. The policy bans
    // them; this is what makes that a policy rather than a note.
    expect(linesMatching(['src', 'tests', 'tools'], '#^\s*//\s*[-=*_]{4,}#'))->toBe([]);
});

it('imports nothing from the global namespace in a test file', function () {
    // One such line costs the run its coverage report. PCOV discards everything it
    // collected, and PHPUnit then prints no table and exits non-zero with every test
    // passing — a red build in CI and nothing at all locally, unless the report is
    // read rather than the exit code.
    //
    // Only the test suite directories, and that is the whole of the exemption: `src/`
    // and the namespaced files under `tests/Support` need their imports. A test file
    // has no namespace, so the import resolves to the class the bare name already
    // meant — which is the thing that costs the coverage report.
    expect(linesMatching(testSuiteDirectories(), '#^use\s+\w+\s*;#'))->toBe([]);
});

it('declares a constant in one test file at most', function () {
    // The other half of the rule above, and the same failure: a `const` is global exactly as
    // an unqualified name is, so two files declaring one is a redeclaration. This was found
    // by the coverage job rather than by the suite — 757 tests passing and the run exiting
    // 1 — which is the worst way to find out.
    expect(constantDeclarations())->toBe([]);
});

it('says in the code why ShapeWriter is not split', function () {
    // The one class where `php:S1448` is open on purpose, so the reason has to be somewhere
    // a person deciding whether to split it will actually read — which is the class itself,
    // and not this file. A finding that stays red with no argument next to it reads as an
    // oversight, and the next person to see it will either fix it or silence it.
    $doc = (string) file_get_contents(Doctest::root() . '/src/Render/ShapeWriter.php');

    expect($doc)->toContain('php:S1448');

    // And the argument has to be the crossings rather than a count, because a count is wrong
    // the moment one method moves and a name is not. Each of these is read by the measure
    // half of the class and by the draw half.
    foreach (['columns()', 'rowHeights()', 'HEADER_ROW', 'bodySize()'] as $crossing) {
        expect($doc)->toContain($crossing);
    }
});
