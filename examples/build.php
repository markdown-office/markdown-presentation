<?php

declare(strict_types=1);

/**
 * Builds the example decks so they can be opened and looked at.
 *
 *   php examples/build.php
 *
 * Markdown sources live in examples/markdown, the `.pptx` files they convert to in
 * examples/out, and a PNG of every slide beside them in examples/out/<deck>/.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/decks.php';

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PresentationRenderer;

/** Where the decks, their PDFs and their previews are written. */
const OUT = __DIR__ . '/out';

/** The extension every deck is written under, and stripped from again for its PDF. */
const DECK = '.pptx';

/** The directory a relative image path in a deck resolves against. */
const SOURCES = __DIR__ . '/markdown';

/**
 * What each built deck is for, for the report at the end and for nothing else.
 */
const DESCRIPTIONS = [
    '01-kitchen-sink' => 'Every construct on one deck: emphasis, lists, a table, a quote, code, two rules and a note.',
    '02-layout-roles' => 'The five layout roles, one slide each, in one deck.',
    '03-themes-default' => 'One deck in the default palette.',
    '03-themes-corporate-dark' => 'The same slides in the dark corporate palette.',
    '03-themes-minimal' => 'The same slides in the minimal palette.',
    '04-frontmatter' => 'One slide per frontmatter key, and what each of them reaches.',
    '05-steps' => 'Build-ups: what `::click` and `:::click` become when a step is a click on one slide.',
    '06-tables' => 'Alignment, markup in cells, a cell taller than its row, and a ragged one.',
    '07-images' => 'A picture per slide, one of them converted on the way in.',
    '08-code' => 'Fenced blocks, the language in the shape description, and a block that does not fit.',
    '09-inline-markup' => 'The eight things a paragraph turns into runs.',
    '11-type-scale-default' => 'One deck at the library\'s own type scale.',
    '11-type-scale-dense' => 'The same slides at thirteen points, with the gaps with them.',
];

$assets = SOURCES . '/assets';

@mkdir(OUT, 0o777, true);
@mkdir($assets, 0o777, true);

assets($assets);

$parser = new DeckParser();
$built = [];
$skipped = [];

/** @var array<string, string> $markdown Every source, read once. */
$markdown = [];

foreach (glob(SOURCES . '/*.md') ?: [] as $path) {
    $markdown[basename($path, '.md')] = (string) file_get_contents($path);
}

ksort($markdown);

$refused = [];

foreach ($markdown as $name => $source) {
    if (isset(NOT_BUILT[$name])) {
        $skipped[] = [$name, NOT_BUILT[$name]];

        continue;
    }

    // A deck this build cannot convert is not a reason to stop: the other twelve are still
    // worth having, and the one that could not be built is named below with the reason rather
    // than left as a file from a previous run that looks like it was built just now.
    try {
        if (!isset(VARIANTS[$name])) {
            $built[] = build($parser, $name, $source, $name);

            continue;
        }

        foreach (VARIANTS[$name]['theme'] as $theme) {
            $stem = sprintf('%s-%s', $name, $theme);
            $built[] = build($parser, $stem, withTheme($source, $theme), $stem);
        }
    } catch (Throwable $e) {
        $refused[] = [$name, $e->getMessage()];
        @unlink(OUT . '/' . $name . DECK);
    }
}

echo "\nBuilt:\n\n";

foreach ($built as [$name, $path]) {
    printf("  %-30s %s\n", basename($path), DESCRIPTIONS[$name] ?? '');
}

if ($skipped !== []) {
    echo "\nDeliberately not built:\n\n";

    foreach ($skipped as [$name, $why]) {
        printf("  %-30s %s\n", $name . '.md', $why);
    }
}

if ($refused !== []) {
    echo "\nThis build could not convert:\n\n";

    foreach ($refused as [$name, $why]) {
        printf("  %-30s %s\n", $name . '.md', $why);
    }

    echo "\n  A gallery missing a deck is not a finished gallery, and one that kept a deck\n";
    echo "  from a previous run would look finished. This exits non-zero.\n";
}

echo "\n";

renderPreviews($built);

// After the previews, so a person watching the run sees what was built before being told what
// was not.
if ($refused !== []) {
    exit(1);
}

/**
 * Convert one source and say what did not fit.
 *
 * The overflow report is here rather than only in a test because a clamped block is the
 * one thing about a built deck that is invisible in it: the deck looks finished and is
 * missing something, and the renderer says so to a caller that asks.
 *
 * @return array{0: string, 1: string} The name and the path written.
 */
function build(DeckParser $parser, string $name, string $source, string $stem): array
{
    $path = OUT . '/' . $stem . DECK;

    // The renderer is handed the directory the deck is in, because that is what a relative
    // image path in it resolves against — and an example whose pictures are missing would
    // be an example of nothing.
    $renderer = new PresentationRenderer(SOURCES);

    $renderer->render($parser->parse($source), $path);

    foreach ($renderer->overflows() as $overflow) {
        // No column for a shape the deck placed itself, and saying `column 1` about one would
        // be a number in front of nothing.
        printf(
            "  %-24s clamped: slide %d step %d %s: %s wanted %dpx, %dpx left\n",
            $stem . DECK,
            $overflow['slide'],
            $overflow['step'],
            $overflow['column'] === null
                ? sprintf('placed "%s"', $overflow['block'])
                : sprintf('column %d', $overflow['column'] + 1),
            $overflow['block'],
            $overflow['wanted'],
            $overflow['available'],
        );
    }

    return [$name, $path];
}

/**
 * The same source with another theme named in its frontmatter.
 *
 * The replacement is anchored at the start of a line rather than anywhere in the deck,
 * because a deck can carry a second frontmatter block on a later slide and a search for
 * `theme:` alone would rewrite whichever of them came first.
 */
function withTheme(string $source, string $theme): string
{
    $replaced = preg_replace('/^theme: .*$/m', 'theme: ' . $theme, $source, 1);

    return $replaced === null ? $source : $replaced;
}

/**
 * The pictures the examples use.
 *
 * Written only when they are not already there, so the committed files are what the
 * gallery was looked at with and a checkout that lost one still builds.
 */
function assets(string $directory): void
{
    logo($directory . '/logo.png');
    landscape($directory . '/landscape.webp');

    if (!is_file($directory . '/diagram.svg')) {
        file_put_contents($directory . '/diagram.svg', <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 200" width="320" height="200">
              <rect width="320" height="200" fill="#1E1E2E"/>
              <circle cx="80" cy="60" r="28" fill="#89B4FA"/>
              <rect x="140" y="40" width="140" height="40" rx="6" fill="#A6E3A1"/>
              <path d="M40 120 L160 190 L280 120" fill="none" stroke="#F9E2AF" stroke-width="10"
                    stroke-linecap="round" stroke-linejoin="round"/>
              <text x="160" y="120" font-family="Helvetica, Arial, sans-serif" font-size="22"
                    fill="#CDD6F4" text-anchor="middle">a vector</text>
            </svg>
            SVG);
    }
}

function logo(string $path): void
{
    if (is_file($path)) {
        return;
    }

    $size = 200;
    $image = imagecreatetruecolor($size, $size);

    $background = imagecolorallocate($image, 0x1E, 0x1E, 0x2E);
    $ink = imagecolorallocate($image, 0xCD, 0xD6, 0xF4);
    $accent = imagecolorallocate($image, 0x89, 0xB4, 0xFA);

    imagefilledrectangle($image, 0, 0, $size, $size, $background);
    imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), 150, 150, $accent);
    imagerectangle($image, 10, 10, $size - 11, $size - 11, $ink);
    imagefilledellipse($image, (int) ($size / 2), (int) ($size / 2), 90, 90, $background);

    imagepng($image, $path);
}

/**
 * A wide picture in a format a `.pptx` cannot carry, which is why the examples need it.
 */
function landscape(string $path): void
{
    if (is_file($path)) {
        return;
    }

    $image = imagecreatetruecolor(640, 200);

    $sky = imagecolorallocate($image, 0xCF, 0xE2, 0xF3);

    imagefilledrectangle($image, 0, 0, 640, 200, $sky);

    $hills = [0x7A, 0x99, 0xB8, 0xD7];

    foreach ($hills as $number => $level) {
        $colour = imagecolorallocate($image, $level, $level + 10, $level + 30);

        for ($x = 0; $x < 640; $x++) {
            $top = (int) (100 + $number * 22 - 30 * sin($x / 60 + $number));

            imagefilledrectangle($image, $x, $top, $x, 200, $colour);
        }
    }

    imagewebp($image, $path, 80);
}

/**
 * A PNG of every slide of every deck, so the result can be looked at without PowerPoint.
 *
 * One file per slide rather than one per deck: a deck's whole question is usually whether
 * slide three overflows, which is not answerable from the first slide.
 *
 * @param list<array{0: string, 1: string}> $built
 */
function renderPreviews(array $built): void
{
    $soffice = locate([
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/lib/libreoffice/program/soffice',
    ]);

    if ($soffice === null) {
        echo "LibreOffice was not found, so no slide previews were produced.\n";

        return;
    }

    if (locate(['python3']) === null) {
        echo "python3 was not found, so no slide previews were produced.\n";

        return;
    }

    $python = (string) locate(['python3']);
    $rendered = 0;
    $slides = 0;
    $failures = [];

    foreach ($built as [, $path]) {
        $directory = preg_replace('/\.[^.]+$/', '', $path);
        $slidesOfDeck = renderDeck($soffice, $python, $path, $directory);

        if ($slidesOfDeck === 0) {
            $failures[] = basename($path);

            continue;
        }

        $rendered++;
        $slides += $slidesOfDeck;
    }

    printf("Rendered %d preview image(s) from %d deck(s).\n", $slides, $rendered);

    foreach ($failures as $name) {
        printf("No preview for %s: see the note above for why.\n", $name);
    }
}

/**
 * One deck to PDF and then one PNG per page, and how many pages it had.
 *
 * A private LibreOffice profile per deck, because a second `soffice` started while one is
 * already running hands the request to that one and returns without converting anything —
 * which looks exactly like a conversion that failed.
 */
function renderDeck(string $soffice, string $python, string $path, string $directory): int
{
    @mkdir($directory, 0o777, true);

    // The profile is a few hundred files of LibreOffice's own state and belongs in the
    // temporary directory rather than beside the slide images it has nothing to do with.
    //
    // The hash is there to give each deck its own directory in one run, not to verify
    // anything — the pid already keeps two runs apart, and nothing reads this back. So it is
    // xxh3, which is what it does, rather than md5, which said it was a digest.
    $scratch = sys_get_temp_dir() . '/mdpres-' . getmypid() . '-' . hash('xxh3', $path);
    @mkdir($scratch, 0o700, true);

    try {
        exec(sprintf(
            '%s -env:UserInstallation=%s --headless --convert-to pdf --outdir %s %s 2>&1',
            escapeshellcmd($soffice),
            escapeshellarg('file://' . $scratch . '/profile'),
            escapeshellarg($directory),
            escapeshellarg($path),
        ), $output, $status);

        $pdf = $directory . '/' . basename($path, DECK) . '.pdf';

        if ($status !== 0 || !is_file($pdf)) {
            printf("  %-30s no PDF: %s\n", basename($path), trim(implode(' ', $output)));

            return 0;
        }

        return renderPages($python, $directory, $pdf, $scratch);
    } finally {
        removeTree($scratch);
    }
}

/**
 * A PNG of every page of a PDF, whichever of the two tools this machine has.
 */
function renderPages(string $python, string $directory, string $pdf, string $scratch): int
{
    $script = $scratch . '/pages.py';

    file_put_contents($script, <<<'PYTHON'
        import pathlib, sys
        try:
            import pymupdf
        except ImportError:
            try:
                import fitz as pymupdf
            except ImportError:
                print("no-pymupdf")
                raise SystemExit(2)
        out = pathlib.Path(sys.argv[1])
        with pymupdf.open(sys.argv[2]) as document:
            for number, page in enumerate(document, 1):
                page.get_pixmap(dpi=110).save(out / f"slide-{number:02d}.png")
        print(f"{document.page_count}")
        PYTHON);

    exec(sprintf(
        '%s %s %s %s 2>&1',
        escapeshellcmd($python),
        escapeshellarg($script),
        escapeshellarg($directory),
        escapeshellarg($pdf),
    ), $output, $status);

    $pages = glob($directory . '/slide-*.png') ?: [];

    if ($pages === []) {
        // ImageMagick reads a PDF page at a time when Ghostscript is behind it, which is the
        // other tool that is usually on the machine. One image per page comes out of the
        // `%02d` in the output name rather than out of an option.
        exec(sprintf(
            'magick -density 110 -background white %s -alpha remove -quality 90 %s 2>&1',
            escapeshellarg($pdf),
            escapeshellarg($directory . '/slide-%02d.png'),
        ), $fallback, $fallbackStatus);

        $pages = glob($directory . '/slide-*.png') ?: [];
    }

    if ($pages === []) {
        printf("  %-30s no pages: %s\n", basename($pdf), trim(implode(' ', $output)));

        return 0;
    }

    return count($pages);
}

/**
 * Deletes a directory and everything under it, which is how LibreOffice's profile goes away.
 */
function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($path);
}

/**
 * The first of these that is an executable this machine can run, or null.
 *
 * A path is tested directly and a bare name is looked up on `PATH`, so the same list can
 * hold the two macOS and Linux installations of LibreOffice and the shell lookup of
 * `python3`, which is the one whose location is not fixed.
 *
 * @param list<string> $candidates
 */
function locate(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (str_starts_with($candidate, '/')) {
            if (is_executable($candidate)) {
                return $candidate;
            }

            continue;
        }

        exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($candidate)), $found, $status);

        if ($status === 0 && $found !== []) {
            return $found[0];
        }
    }

    return null;
}
