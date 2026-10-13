<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/**
 * Carries one marker from the line that opened it to the line that closes it.
 *
 * Only a region looks for a fence, and it closes on three colons and nothing else. Not on
 * four: a region written `:::click` ends at `:::`, and a line of four colons inside it is
 * a paragraph the author wrote rather than the end of anything.
 *
 * A leaf ends by declining to continue rather than by reporting itself finished, because a
 * block that reports itself finished takes the rest of the line with it — and a `::click`
 * standing on the line before a paragraph would swallow that paragraph.
 */
final class MarkerContinueParser extends AbstractBlockContinueParser
{
    public function __construct(private readonly Marker $marker)
    {
    }

    public function getBlock(): Marker
    {
        return $this->marker;
    }

    public function isContainer(): bool
    {
        return $this->marker->kind() === MarkerKind::Region;
    }

    public function canHaveLazyContinuationLines(): bool
    {
        return false;
    }

    public function canContain(AbstractBlock $childBlock): bool
    {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if (!$this->isContainer()) {
            return BlockContinue::none();
        }

        $cursor->advanceToNextNonSpaceOrTab();

        if (preg_match('/\A:::[ \t]*\z/', $cursor->getRemainder()) !== 1) {
            return BlockContinue::at($cursor);
        }

        $this->marker->close();

        return BlockContinue::finished();
    }
}
