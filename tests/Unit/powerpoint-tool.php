<?php

declare(strict_types=1);

use MarkdownPresentation\Tests\Support\Scratch;

/*
 * The question the PowerPoint tool asks about a PDF, and the answer it acts on.
 *
 * A PDF is written lazily, so a file that exists is a file that may still be being written.
 * The tool asked whether the file was there, copied one that was still being written, and
 * the copy was half a PDF. The question now has an answer of its own, in a file of its own,
 * so that it can be asked without PowerPoint: the tool needs the application and the answer
 * does not.
 */

/**
 * Whether the tool's own check calls a file a finished PDF.
 */
function powerPointPdfIsFinished(string $file): bool
{
    $tool = dirname(__DIR__, 2) . '/tools/pdf-is-complete.sh';

    $output = [];

    exec('sh ' . escapeshellarg($tool) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);

    return $status === 0;
}

it('calls a PDF that has been written whole finished', function () {
    // A PDF ends with the marker every PDF ends with, and the marker is written last of all,
    // so a file carrying it is a file that has been written whole.
    $file = Scratch::file('whole.pdf', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

    expect(powerPointPdfIsFinished($file))->toBeTrue();
});

it('calls a PDF that was cut short unfinished', function () {
    // The file is there and it is not empty, which is all the tool used to ask. A PDF being
    // written is exactly that, and copying it copies half a PDF.
    $file = Scratch::file('cut.pdf', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n");

    expect(powerPointPdfIsFinished($file))->toBeFalse();
});

it('calls a file that is not there unfinished', function () {
    // PowerPoint wrote no PDF at all, which is a failure of the export rather than a PDF
    // waiting to be finished.
    expect(powerPointPdfIsFinished(Scratch::directory() . '/nothing.pdf'))->toBeFalse();
});

it('calls an empty file unfinished', function () {
    // The export began and wrote nothing, which is the same failure as writing no file.
    $file = Scratch::file('empty.pdf', '');

    expect(powerPointPdfIsFinished($file))->toBeFalse();
});
