<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

/**
 * The five things a line of colons can be in a slide, told apart by the colons and by
 * nothing else.
 *
 * A slide's own vocabulary is two markers and the fences around them, and each of the five
 * is a different number of colons: a step and a column are leaves, a region is a container
 * and a `:::` is what closes one. The case is folded because a deck written by a model
 * capitalises a marker as readily as it writes it in lower case.
 */
enum MarkerKind: string
{
    /** `::click` — one step of its own. */
    case Step = 'step';

    /** `:::click` … `:::` — the blocks between the fences are one step. */
    case Region = 'region';

    /** `::column` — the blocks after it belong to the next column. */
    case Column = 'column';

    /** `:::` with nothing to close. */
    case Closer = 'closer';

    /**
     * A `:::` line that opens a container this library does not draw, however it is spelled.
     *
     * Claimed rather than left to the generic directives extension because the extension
     * reads no space after the colons, so `::: note` is a container to this library and a
     * paragraph to it. Both are refusals, and the one naming what the deck wrote is the one
     * an author can act on.
     */
    case Container = 'container';
}
