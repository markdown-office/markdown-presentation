<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

use ZipArchive;

/**
 * An archive that opens like any other and cannot produce one of its parts.
 *
 * Whether a damaged payload actually fails to inflate is the build's business, not this
 * library's: one zlib rejects a reserved deflate block type and another decodes it into
 * different bytes, and neither a wrong CRC nor an impossible compressed size is refused
 * everywhere. So a test that corrupts a file asserts whichever inflater is under it. This
 * answers `false` because it was told to, which is the value `ZipArchive` documents for an
 * entry it cannot produce, and the pass under test cannot tell the two apart.
 *
 * Everything else is the real archive, so the parts, their order and their sizes are the
 * writer's — which is what lets a test assert afterwards that the file on disk still holds
 * what it held before.
 */
final class UnreadableArchive extends ZipArchive
{
    public function __construct(private readonly string $part)
    {
    }

    public function getFromIndex(int $index, int $length = 0, int $flags = 0): string|false
    {
        return $this->getNameIndex($index) === $this->part
            ? false
            : parent::getFromIndex($index, $length, $flags);
    }
}