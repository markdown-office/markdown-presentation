<?php

declare(strict_types=1);

/*
 * Fetches the ECMA-376 schemas into `tools/ooxml-schemas/` and checks them against a checksum, so
 * a deck is validated against the schemas this repository pinned rather than against whatever a
 * machine happens to have.
 *
 * They are deliberately not a dependency, for the same reason PHPStan is not one: nothing here
 * builds with them, and a library does not carry what only one local tool needs.
 *
 * The pin is a commit rather than a version, because `ref/xsd` is a reference copy of ECMA-376's
 * schemas kept inside another project and has no release of its own — a branch would move under
 * the hash. They are the transitional schemas, the vocabulary Office has written since 2007: a
 * deck PowerPoint opens as `[Repaired]` is a deck outside the schema it was written to, so the
 * oracle has to be the one it claims. The `opc-*.xsd` beside them describe the relationship and
 * content-type parts, which are not the parts being validated.
 *
 * The checksum is of a manifest — every file name and the hash of its bytes — because a
 * directory is not one file to hash, and because a half-finished download has to fail the check
 * rather than pass it.
 *
 * Usage: php tools/install-ooxml-schemas.php
 */

$commit = 'e45454602b53e8e572b179ccf1c91093ec9f4ed7';
$sha256 = '103d6d6eceb452b75a44911ae0b336d38bdec18821feadd313ee692d0ebcdb6c';
$target = __DIR__ . '/ooxml-schemas';

// Every file in `ref/xsd` outside `opc-xsd`, in the order the manifest hashes them. A name added
// or removed here changes the manifest and stops it matching the checksum above, so a re-pin is a
// new $commit and the hash the install then prints for the set it fetched.
$files = [
    'dml-chart.xsd',
    'dml-chartDrawing.xsd',
    'dml-diagram.xsd',
    'dml-lockedCanvas.xsd',
    'dml-main.xsd',
    'dml-picture.xsd',
    'dml-spreadsheetDrawing.xsd',
    'dml-wordprocessingDrawing.xsd',
    'pml.xsd',
    'shared-additionalCharacteristics.xsd',
    'shared-bibliography.xsd',
    'shared-commonSimpleTypes.xsd',
    'shared-customXmlDataProperties.xsd',
    'shared-customXmlSchemaProperties.xsd',
    'shared-documentPropertiesCustom.xsd',
    'shared-documentPropertiesExtended.xsd',
    'shared-documentPropertiesVariantTypes.xsd',
    'shared-math.xsd',
    'shared-relationshipReference.xsd',
    'sml.xsd',
    'vml-main.xsd',
    'vml-officeDrawing.xsd',
    'vml-presentationDrawing.xsd',
    'vml-spreadsheetDrawing.xsd',
    'vml-wordprocessingDrawing.xsd',
    'wml.xsd',
];

if (manifestOf($target, $files) === $sha256) {
    printf("The ECMA-376 schemas of %s are already in place.\n", $commit);

    exit(0);
}

$downloaded = [];
$manifest = '';

foreach ($files as $name) {
    $url = sprintf('https://raw.githubusercontent.com/python-openxml/python-docx/%s/ref/xsd/%s', $commit, $name);
    $bytes = @file_get_contents($url);

    if ($bytes === false) {
        fwrite(STDERR, sprintf("%s did not download.\n", $url));

        exit(1);
    }

    $downloaded[$name] = $bytes;
    $manifest .= $name . "\t" . hash('sha256', $bytes) . "\n";
}

if (hash('sha256', $manifest) !== $sha256) {
    fwrite(STDERR, sprintf(
        "The schemas of %s did not download to the bytes they claim to be.\nThey hash to %s.\n",
        $commit,
        hash('sha256', $manifest),
    ));

    exit(1);
}

if (!is_dir($target) && !mkdir($target, 0o777, true) && !is_dir($target)) {
    fwrite(STDERR, sprintf("Unable to create %s.\n", $target));

    exit(1);
}

foreach ($downloaded as $name => $bytes) {
    file_put_contents(sprintf('%s/%s', $target, $name), $bytes);
}

// The directory is somebody else's schemas, and a `.gitignore` inside it keeps them out of a
// commit without a line in the repository's own list of what is not source.
file_put_contents(sprintf('%s/.gitignore', $target), "*\n");

printf("%d schema files from %s written to %s.\n", count($files), $commit, $target);

/**
 * The checksum `$sha256` is of, for whatever the directory holds rather than for what it should
 * hold.
 *
 * A name whose file is missing is hashed as `missing` rather than left out, because a directory
 * with one of them absent has to fail the check and reinstall instead of looking like a shorter
 * list that hashes to something else by accident.
 *
 * @param list<string> $names
 */
function manifestOf(string $directory, array $names): string
{
    $lines = [];

    foreach ($names as $name) {
        $path = sprintf('%s/%s', $directory, $name);
        $hash = is_file($path) ? hash_file('sha256', $path) : false;

        $lines[] = $name . "\t" . ($hash === false ? 'missing' : $hash);
    }

    return hash('sha256', implode("\n", $lines) . "\n");
}