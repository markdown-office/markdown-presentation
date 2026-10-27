<?php

declare(strict_types=1);

/*
 * Generates `src/Render/FontMetrics.php`: the advance widths of the faces the shipped
 * themes name, in units per em.
 *
 * The widths come from a table rather than from a font file at run time, so this is
 * where the table is made. Every face is measured through a metric-compatible clone
 * rather than through the face itself: Calibri is Microsoft's and cannot be shipped,
 * Carlito is the same widths under a free licence, and the same is true of Caladea
 * for Cambria, Liberation Sans for Arial, Liberation Sans Narrow for Arial Narrow and
 * Gelasio for Georgia. Inter and Roboto are their own clones of nothing and are
 * taken from the projects that make them.
 *
 * The font files are fetched by checksum, the way `tools/install-phpstan.php` fetches
 * the analyser: the URL and the hash live in this script and nowhere else, so nothing
 * is downloaded without a recorded hash and a download that comes to different bytes
 * fails rather than quietly measuring a different face. The downloads are kept in
 * `tools/fonts/`, which is not in the repository.
 *
 * The TrueType tables are read here rather than by a library, because a font library
 * is a dependency this package does not need to render a deck: four tables are read —
 * `head` for the units per em, `hhea` for how many advance widths there are, `hmtx`
 * for the advance widths themselves and `cmap` for the character a glyph is drawn
 * with — and that is a few hundred lines of PHP.
 *
 * Usage: php tools/generate-font-metrics.php
 */

/**
 * The faces the shipped themes name, the clone each is measured through, and where
 * the clone's two cuts come from.
 *
 * The order is alphabetical and the output follows it, so the file this writes is the
 * same on every machine that runs it.
 *
 * @var array<string, array{clone: string, regular: array{url: string, sha256: string, kind: string, member: ?string}, bold: array{url: string, sha256: string, kind: string, member: ?string}}>
 */
$faces = [
    'Arial' => [
        'clone' => 'Liberation Sans',
        'regular' => [
            'url' => 'https://distfiles.macports.org/liberation-fonts/liberation-fonts-ttf-2.1.5.tar.gz',
            'sha256' => '7191c669bf38899f73a2094ed00f7b800553364f90e2637010a69c0e268f25d0',
            'kind' => 'tar',
            'member' => 'liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf',
        ],
        'bold' => [
            'url' => 'https://distfiles.macports.org/liberation-fonts/liberation-fonts-ttf-2.1.5.tar.gz',
            'sha256' => '7191c669bf38899f73a2094ed00f7b800553364f90e2637010a69c0e268f25d0',
            'kind' => 'tar',
            'member' => 'liberation-fonts-ttf-2.1.5/LiberationSans-Bold.ttf',
        ],
    ],
    'Arial Narrow' => [
        'clone' => 'Liberation Sans Narrow',
        'regular' => [
            'url' => 'https://github.com/liberationfonts/liberation-sans-narrow/releases/download/1.07.5/liberation-narrow-fonts-ttf-1.07.5.tar.gz',
            'sha256' => '64948564a34858912b746abc7aa239233c0083115c9386c2079ae92764040b22',
            'kind' => 'tar',
            'member' => 'liberation-narrow-fonts-ttf-1.07.5/LiberationSansNarrow-Regular.ttf',
        ],
        'bold' => [
            'url' => 'https://github.com/liberationfonts/liberation-sans-narrow/releases/download/1.07.5/liberation-narrow-fonts-ttf-1.07.5.tar.gz',
            'sha256' => '64948564a34858912b746abc7aa239233c0083115c9386c2079ae92764040b22',
            'kind' => 'tar',
            'member' => 'liberation-narrow-fonts-ttf-1.07.5/LiberationSansNarrow-Bold.ttf',
        ],
    ],
    'Calibri' => [
        'clone' => 'Carlito',
        'regular' => [
            'url' => 'https://raw.githubusercontent.com/google/fonts/a4c0b21f839d7ac65a7d8b49eb81bddd110774a3/ofl/carlito/Carlito-Regular.ttf',
            'sha256' => 'f6418f708baede9789daef5d458c0f53d2a888af9820e8062934e504fedc6595',
            'kind' => 'file',
            'member' => null,
        ],
        'bold' => [
            'url' => 'https://raw.githubusercontent.com/google/fonts/a4c0b21f839d7ac65a7d8b49eb81bddd110774a3/ofl/carlito/Carlito-Bold.ttf',
            'sha256' => 'bb5d20f79b82599ec72983597437373a80f2d2085fa91fc144fd74e876a594db',
            'kind' => 'file',
            'member' => null,
        ],
    ],
    'Cambria' => [
        'clone' => 'Caladea',
        'regular' => [
            'url' => 'https://raw.githubusercontent.com/google/fonts/a4c0b21f839d7ac65a7d8b49eb81bddd110774a3/ofl/caladea/Caladea-Regular.ttf',
            'sha256' => 'f1e899278b7b4491aba5b6a8253c4b04c050cc59b21865be5c37559a775153cd',
            'kind' => 'file',
            'member' => null,
        ],
        'bold' => [
            'url' => 'https://raw.githubusercontent.com/google/fonts/a4c0b21f839d7ac65a7d8b49eb81bddd110774a3/ofl/caladea/Caladea-Bold.ttf',
            'sha256' => 'ae3cb2dcbc925809dd29d2a44e9802211cab66be541bacbfc9c08c74b27c3742',
            'kind' => 'file',
            'member' => null,
        ],
    ],
    'Georgia' => [
        'clone' => 'Gelasio',
        'regular' => [
            'url' => 'https://raw.githubusercontent.com/SorkinType/Gelasio/7ab20e7e5c42791e603b9ee3201a0b49849cfdb2/fonts/ttf/Gelasio-Regular.ttf',
            'sha256' => '48c797fbe0e07c48a18cb962e7bdfa23f19618327dddf54093265328dc9eb39d',
            'kind' => 'file',
            'member' => null,
        ],
        'bold' => [
            'url' => 'https://raw.githubusercontent.com/SorkinType/Gelasio/7ab20e7e5c42791e603b9ee3201a0b49849cfdb2/fonts/ttf/Gelasio-Bold.ttf',
            'sha256' => 'e0ef3addf1acf35f5c6aef2be00d0a2c01363bf70a5950e16650976051a0c462',
            'kind' => 'file',
            'member' => null,
        ],
    ],
    'Inter' => [
        'clone' => 'Inter',
        'regular' => [
            'url' => 'https://github.com/rsms/inter/releases/download/v4.1/Inter-4.1.zip',
            'sha256' => '9883fdd4a49d4fb66bd8177ba6625ef9a64aa45899767dde3d36aa425756b11e',
            'kind' => 'zip',
            'member' => 'extras/ttf/Inter-Regular.ttf',
        ],
        'bold' => [
            'url' => 'https://github.com/rsms/inter/releases/download/v4.1/Inter-4.1.zip',
            'sha256' => '9883fdd4a49d4fb66bd8177ba6625ef9a64aa45899767dde3d36aa425756b11e',
            'kind' => 'zip',
            'member' => 'extras/ttf/Inter-Bold.ttf',
        ],
    ],
    'Roboto' => [
        'clone' => 'Roboto',
        'regular' => [
            'url' => 'https://github.com/googlefonts/roboto-2/releases/download/v2.138/roboto-android.zip',
            'sha256' => 'c825453253f590cfe62557733e7173f9a421fff103b00f57d33c4ad28ae53baf',
            'kind' => 'zip',
            'member' => 'Roboto-Regular.ttf',
        ],
        'bold' => [
            'url' => 'https://github.com/googlefonts/roboto-2/releases/download/v2.138/roboto-android.zip',
            'sha256' => 'c825453253f590cfe62557733e7173f9a421fff103b00f57d33c4ad28ae53baf',
            'kind' => 'zip',
            'member' => 'Roboto-Bold.ttf',
        ],
    ],
];

/** The characters a table carries: the printable ASCII. */
const CHARACTERS = ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

$cache = __DIR__ . '/fonts';
$target = __DIR__ . '/../src/Render/FontMetrics.php';

$tables = [];
$clones = [];

foreach ($faces as $face => $cuts) {
    $clones[$face] = $cuts['clone'];
    $tables[$face] = [
        'regular' => widthsOf(fontBytes($cuts['regular'], $cache)),
        'bold' => widthsOf(fontBytes($cuts['bold'], $cache)),
    ];
}

file_put_contents($target, generated($tables, $clones));

printf("%d faces written to %s.\n", count($tables), $target);

/**
 * The bytes of a font, fetched by checksum and taken out of its archive.
 *
 * @param array{url: string, sha256: string, kind: string, member: ?string} $cut
 */
function fontBytes(array $cut, string $cache): string
{
    $path = fetch($cut['url'], $cut['sha256'], $cache);

    if ($cut['kind'] === 'file') {
        return (string) file_get_contents($path);
    }

    if ($cut['kind'] === 'zip') {
        return zipMember($path, (string) $cut['member']);
    }

    return tarMember($path, (string) $cut['member']);
}

/**
 * The file at `$url`, checked against the hash it is recorded with.
 *
 * The download is kept, so a second run of this tool costs no network: the file is
 * named after the hash it is checked against, which is also what makes two faces out
 * of one archive a single download.
 */
function fetch(string $url, string $sha256, string $cache): string
{
    $path = sprintf('%s/%s', $cache, $sha256);

    if (is_file($path) && hash_file('sha256', $path) === $sha256) {
        return $path;
    }

    $bytes = @file_get_contents($url);

    if ($bytes === false || hash('sha256', $bytes) !== $sha256) {
        fwrite(STDERR, sprintf("%s did not download to the bytes it claims to be.\n", $url));

        exit(1);
    }

    if (!is_dir($cache) && !mkdir($cache, 0o777, true) && !is_dir($cache)) {
        fwrite(STDERR, sprintf("Unable to create %s.\n", $cache));

        exit(1);
    }

    file_put_contents($path, $bytes);

    return $path;
}

/**
 * One member of a zip archive.
 */
function zipMember(string $path, string $member): string
{
    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        fwrite(STDERR, sprintf("%s did not open as an archive.\n", $path));

        exit(1);
    }

    $bytes = $zip->getFromName($member);
    $zip->close();

    if ($bytes === false) {
        fwrite(STDERR, sprintf("%s holds no member called %s.\n", $path, $member));

        exit(1);
    }

    return $bytes;
}

/**
 * One member of a gzipped tar archive.
 *
 * The tar format is read here rather than by an extension: a tar header is a block of
 * 512 bytes with the name in the first hundred of them and the size in octal twelve
 * bytes along, and a member's data is padded out to the next block.
 */
function tarMember(string $path, string $member): string
{
    $archive = gzdecode((string) file_get_contents($path));

    if ($archive === false) {
        fwrite(STDERR, sprintf("%s did not unpack.\n", $path));

        exit(1);
    }

    $length = strlen($archive);
    $offset = 0;

    while ($offset + 512 <= $length) {
        $header = substr($archive, $offset, 512);
        $name = rtrim(substr($header, 0, 100), "\0");
        $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
        $offset += 512;

        if ($name === $member) {
            return substr($archive, $offset, $size);
        }

        // An empty name is the end of the archive: the last two blocks are nothing.
        if ($name === '') {
            break;
        }

        $offset += (int) (ceil($size / 512) * 512);
    }

    fwrite(STDERR, sprintf("%s holds no member called %s.\n", $path, $member));

    exit(1);
}

/**
 * The widths of the printable ASCII in a font, in units per em.
 *
 * @return array<int|string, int>
 */
function widthsOf(string $font): array
{
    $tables = tablesOf($font);
    $units = unitsPerEm($font, $tables);
    $advances = advancesOf($font, $tables);
    $glyphs = glyphsOf($font, $tables);
    $widths = [];

    foreach (str_split(CHARACTERS) as $character) {
        $glyph = $glyphs[ord($character)] ?? null;

        if ($glyph === null) {
            fwrite(STDERR, sprintf("The font draws no glyph for %s.\n", $character));

            exit(1);
        }

        $advance = $advances[$glyph] ?? null;

        if ($advance === null) {
            fwrite(STDERR, sprintf("The font gives no advance width for %s.\n", $character));

            exit(1);
        }

        // The widths are in the font's own units, and a table of them is in units per
        // em, so a font of 2048 units to the em has its widths halved on the way in.
        $widths[$character] = (int) round($advance * 1000 / $units);
    }

    return $widths;
}

/**
 * The tables of a font, by their four-character tag.
 *
 * @return array<string, array{offset: int, length: int}>
 */
function tablesOf(string $font): array
{
    $tables = [];
    $count = u16($font, 4);

    for ($index = 0; $index < $count; ++$index) {
        $at = 12 + $index * 16;
        $tables[substr($font, $at, 4)] = [
            'offset' => u32($font, $at + 8),
            'length' => u32($font, $at + 12),
        ];
    }

    return $tables;
}

/**
 * The units to the em of a font, which is what its advance widths are counted in.
 *
 * @param array<string, array{offset: int, length: int}> $tables
 */
function unitsPerEm(string $font, array $tables): int
{
    $head = $tables['head'] ?? null;

    if ($head === null) {
        fwrite(STDERR, "The font has no head table.\n");

        exit(1);
    }

    return u16($font, $head['offset'] + 18);
}

/**
 * The advance width of every glyph of a font, in the font's own units.
 *
 * The last advance width is shared by every glyph after the ones `hhea` counts, so a
 * font with fewer advance widths than glyphs gives them all the last one.
 *
 * @param array<string, array{offset: int, length: int}> $tables
 *
 * @return array<int, int>
 */
function advancesOf(string $font, array $tables): array
{
    $hhea = $tables['hhea'] ?? null;
    $hmtx = $tables['hmtx'] ?? null;
    $maxp = $tables['maxp'] ?? null;

    if ($hhea === null || $hmtx === null || $maxp === null) {
        fwrite(STDERR, "The font has no horizontal metrics.\n");

        exit(1);
    }

    $count = u16($font, $hhea['offset'] + 34);
    $advances = [];

    for ($glyph = 0; $glyph < $count; ++$glyph) {
        $advances[$glyph] = u16($font, $hmtx['offset'] + $glyph * 4);
    }

    $glyphs = u16($font, $maxp['offset'] + 4);

    for ($glyph = $count; $glyph < $glyphs; ++$glyph) {
        $advances[$glyph] = $advances[$count - 1];
    }

    return $advances;
}

/**
 * The glyph every character is drawn with.
 *
 * The `cmap` table holds several maps and the widest one is used: a font with a
 * Unicode map and a Windows one gives the same answer through either, and a font
 * with only a Macintosh one is read through that.
 *
 * @param array<string, array{offset: int, length: int}> $tables
 *
 * @return array<int, int>
 */
function glyphsOf(string $font, array $tables): array
{
    $cmap = $tables['cmap'] ?? null;

    if ($cmap === null) {
        fwrite(STDERR, "The font has no character map.\n");

        exit(1);
    }

    $base = $cmap['offset'];
    $count = u16($font, $base + 2);
    $chosen = 0;
    $best = -1;

    for ($index = 0; $index < $count; ++$index) {
        $at = $base + 4 + $index * 8;
        $platform = u16($font, $at);
        $encoding = u16($font, $at + 2);
        $score = match (true) {
            $platform === 3 && $encoding === 10 => 4,
            $platform === 3 && $encoding === 1 => 3,
            $platform === 0 => 2,
            default => 0,
        };

        if ($score > $best) {
            $best = $score;
            $chosen = $base + u32($font, $at + 4);
        }
    }

    return match (u16($font, $chosen)) {
        4 => glyphsOfFormatFour($font, $chosen),
        12 => glyphsOfFormatTwelve($font, $chosen),
        default => [],
    };
}

/**
 * The glyphs of a character map in the format of 1993: the characters are grouped
 * into segments, each of which says where its characters start and which glyph they
 * are drawn with.
 *
 * @return array<int, int>
 */
function glyphsOfFormatFour(string $font, int $base): array
{
    $segments = intdiv(u16($font, $base + 6), 2);
    $ends = $base + 14;
    $starts = $ends + $segments * 2 + 2;
    $deltas = $starts + $segments * 2;
    $ranges = $deltas + $segments * 2;
    $glyphs = [];

    for ($segment = 0; $segment < $segments; ++$segment) {
        $last = u16($font, $ends + $segment * 2);
        $first = u16($font, $starts + $segment * 2);

        if ($first > $last) {
            continue;
        }

        $delta = u16($font, $deltas + $segment * 2);
        $range = u16($font, $ranges + $segment * 2);

        for ($character = $first; $character <= $last && $character <= 0xFFFF; ++$character) {
            if ($range === 0) {
                $glyphs[$character] = ($character + $delta) & 0xFFFF;

                continue;
            }

            // A range that is not zero points into the glyphs that follow the segments,
            // and the pointer is from the range itself rather than from the table.
            $at = $ranges + $segment * 2 + $range + ($character - $first) * 2;
            $glyph = u16($font, $at);

            if ($glyph !== 0) {
                $glyphs[$character] = ($glyph + $delta) & 0xFFFF;
            }
        }
    }

    return $glyphs;
}

/**
 * The glyphs of a character map in the format of 2000: the characters are grouped
 * into runs, each of which says where it starts and which glyph its first character
 * is drawn with.
 *
 * @return array<int, int>
 */
function glyphsOfFormatTwelve(string $font, int $base): array
{
    $groups = u32($font, $base + 12);
    $glyphs = [];

    for ($group = 0; $group < $groups; ++$group) {
        $at = $base + 16 + $group * 12;
        $first = u32($font, $at);
        $last = u32($font, $at + 4);
        $glyph = u32($font, $at + 8);

        for ($character = $first; $character <= $last; ++$character) {
            $glyphs[$character] = $glyph + ($character - $first);
        }
    }

    return $glyphs;
}

/**
 * The unsigned 16-bit number at `$offset`.
 */
function u16(string $bytes, int $offset): int
{
    return (ord($bytes[$offset]) << 8) | ord($bytes[$offset + 1]);
}

/**
 * The unsigned 32-bit number at `$offset`.
 */
function u32(string $bytes, int $offset): int
{
    return (ord($bytes[$offset]) << 24)
        | (ord($bytes[$offset + 1]) << 16)
        | (ord($bytes[$offset + 2]) << 8)
        | ord($bytes[$offset + 3]);
}

/**
 * The class the tables are written into.
 *
 * @param array<string, array{regular: array<int|string, int>, bold: array<int|string, int>}> $tables
 * @param array<string, string> $clones The clone each face is measured through.
 */
function generated(array $tables, array $clones): string
{
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = 'declare(strict_types=1);';
    $lines[] = '';
    $lines[] = 'namespace MarkdownPresentation\Render;';
    $lines[] = '';
    $lines[] = '/**';
    $lines[] = ' * The advance widths of the faces the shipped themes name, in units per em.';
    $lines[] = ' *';
    $lines[] = ' * Generated by `tools/generate-font-metrics.php` from the metric-compatible clones';
    $lines[] = ' * of each face, which are the same widths under a free licence. The file is';
    $lines[] = ' * written rather than edited, and the tool that writes it fetches every font by';
    $lines[] = ' * checksum.';
    $lines[] = ' *';
    $lines[] = ' * | Face | Clone |';
    $lines[] = ' * | --- | --- |';

    foreach ($clones as $face => $clone) {
        $lines[] = sprintf(' * | %s | %s |', $face, $clone);
    }

    $lines[] = ' *';
    $lines[] = ' * @phpstan-type Cuts array{regular: array<int|string, int>, bold: array<int|string, int>}';
    $lines[] = ' */';
    $lines[] = 'final class FontMetrics';
    $lines[] = '{';
    $lines[] = '    /**';
    $lines[] = '     * Every face with a table, by the name a theme writes it under.';
    $lines[] = '     *';
    $lines[] = '     * The keys are characters, and a digit is a key PHP keeps as a number: the table';
    $lines[] = '     * for `0` is under the key `0`, and a lookup by either finds it.';
    $lines[] = '     *';
    $lines[] = '     * @var array<string, Cuts>';
    $lines[] = '     */';
    $lines[] = '    public const TABLES = [';

    foreach ($tables as $face => $cuts) {
        $lines[] = sprintf('        %s => [', quoted($face));

        foreach (['regular', 'bold'] as $weight) {
            $lines[] = sprintf('            %s => [', quoted($weight));
            $lines = array_merge($lines, widths($cuts[$weight], 16));
            $lines[] = '            ],';
        }

        $lines[] = '        ],';
    }

    $lines[] = '    ];';
    $lines[] = '';
    $lines[] = '    /**';
    $lines[] = '     * The face a theme naming one with no table is measured with.';
    $lines[] = '     *';
    $lines[] = '     * Its widths are the ones every face was measured with before there were tables';
    $lines[] = '     * of their own, so a deck naming an unusual face is laid out as it was before';
    $lines[] = '     * rather than as something else.';
    $lines[] = '     */';
    $lines[] = "    public const REFERENCE = 'Arial';";
    $lines[] = '}';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * The widths of a table, as the lines of an array.
 *
 * @param array<int|string, int> $widths
 *
 * @return list<string>
 */
function widths(array $widths, int $indent): array
{
    $lines = [];
    $entries = [];

    foreach ($widths as $character => $width) {
        // A digit is a key PHP keeps as a number, so it is written as a string and read
        // back as one: `'0' => 556` is the key `0` in the array and the character `0` in
        // the table, and a lookup by either finds it.
        $entries[] = sprintf('%s => %d', quoted((string) $character), $width);
    }

    foreach (array_chunk($entries, 8) as $chunk) {
        $lines[] = sprintf(
            '%s%s,',
            str_repeat(' ', $indent),
            implode(', ', $chunk),
        );
    }

    return $lines;
}

/**
 * A string as PHP writes it.
 */
function quoted(string $value): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
}
