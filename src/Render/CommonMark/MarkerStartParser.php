<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * Recognises the markers a slide is written with: `::click`, `::column`, `:::click` and the
 * `:::` that closes a region.
 *
 * The generic directives extension is what teaches CommonMark that `::name` is a directive at
 * all, but its grammar has no room for `::click=3`: an argument is written in braces there,
 * and a step number is written after an equals. So the slide's own two markers are claimed
 * here, in front of it, and the argument is carried on the node rather than folded into an
 * attribute.
 *
 * Every pattern wants the marker alone on its line, because that is what makes a marker a
 * marker: `a ::click in a sentence` is a sentence, and a step a reader cannot predict is a
 * step nobody meant. The region comes first so that three colons are never read as a leaf
 * with a stray one in front of it.
 */
final class MarkerStartParser implements BlockStartParserInterface
{
    /**
     * Capture 1 is what a writer put after the equals. PHP drops the group when it did not
     * take part, which is the bare marker, and holds an empty string when it took part and
     * matched nothing, which is `::click=`: a mistake this library refuses by name rather
     * than reads as the marker without a number.
     */
    private const MARKERS = [
        '/\A[ \t]*:::click[ \t]*(?:=[ \t]*(.*?)[ \t]*)?[ \t]*\z/i' => MarkerKind::Region,
        '/\A[ \t]*::click[ \t]*(?:=[ \t]*(.*?)[ \t]*)?[ \t]*\z/i' => MarkerKind::Step,
        '/\A[ \t]*::column[ \t]*(?:=[ \t]*(.*?)[ \t]*)?[ \t]*\z/i' => MarkerKind::Column,
        '/\A[ \t]*:::[ \t]*\z/' => MarkerKind::Closer,
        '/\A[ \t]*:::.*\z/' => MarkerKind::Container,
    ];

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        // Four spaces of indentation is a code block in CommonMark, and a marker a deck
        // wrote inside one is code like anything else in it.
        if ($cursor->isIndented()) {
            return BlockStart::none();
        }

        $written = $cursor->getRemainder();

        foreach (self::MARKERS as $pattern => $kind) {
            if (preg_match($pattern, $written, $match) !== 1) {
                continue;
            }

            $marker = new Marker($kind, $match[1] ?? null, $written);
            $cursor->advanceToEnd();

            return BlockStart::of(new MarkerContinueParser($marker))->at($cursor);
        }

        return BlockStart::none();
    }
}
