<?php

declare(strict_types=1);

use MarkdownPresentation\Tests\Support\Doctest;

/*
 * Claims that were wrong, kept so they cannot come back.
 /**
  * The comment policy in the sibling package bans dividers, restatement, type-only
  * docblocks and counts, and this is what makes the first of those a rule rather than
  * a note. The second half is here for the same reason it is there: a confidently
  * false docblock passes every other check in the suite, because nothing reads prose.
  * Each phrase below was a claim this package could not support — add one when the
  * next is corrected, which is the whole maintenance cost of the list.
 */

/**
 * The files holding a phrase, so a claim that has been corrected cannot come back
 * unnoticed.
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

            if (str_contains((string) file_get_contents($file->getPathname()), $claim)) {
                $found[] = $area . '/' . $file->getFilename();
            }
        }
    }

    foreach (['README.md', 'CHANGELOG.md'] as $page) {
        if (str_contains((string) file_get_contents(Doctest::root() . '/' . $page), $claim)) {
            $found[] = $page;
        }
    }

    sort($found);

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
    'a v-click marker closing a list item is honoured' => 'a marker closing a list item',
    'an image is embedded whatever format it is in' => 'embedded unchanged, whatever format the deck named it in',
    'the package cannot write a pptx' => 'it renders nothing',
]);

it('has no decorative dividers in it', function () {
    // A rule of dashes above a run of methods says what the method names below it
    // already say, in seventy characters, on every read of the file. The policy bans
    // them; this is what makes that a policy rather than a note.
    $dividers = [];

    foreach (['src', 'tests', 'tools'] as $area) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(Doctest::root() . '/' . $area, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || !$file->isFile()) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('#^\s*//\s*[-=*_]{4,}#', $line) === 1) {
                    $dividers[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }
    }

    expect($dividers)->toBe([]);
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
    $root = Doctest::root();
    $imports = [];

    foreach (simplexml_load_file($root . '/phpunit.xml.dist')->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match('#^use\s+[A-Za-z_][A-Za-z0-9_]*\s*;#', $line) === 1) {
                        $imports[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                    }
                }
            }
        }
    }

    expect($imports)->toBe([]);
});
