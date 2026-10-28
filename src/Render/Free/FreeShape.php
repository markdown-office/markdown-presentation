<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

/**
 * A shape a deck placed itself, rather than one the layout put where it did.
 *
 * Four kinds rather than one class with a nullable payload on each: a shape is written as one
 * shape, and {@see \MarkdownPresentation\Render\ShapeWriter} has to know which before it can
 * pick a shape class, so a {@see FreePicture} whose picture was missing and a {@see FreeText}
 * whose frame was missing would be two states the draw site has to defend against. Four
 * classes make both impossible rather than merely discouraged.
 *
 * The paint and the label are here rather than on the subclasses that use them, because a
 * shape that is neither type nor a picture is an ink shape whatever else becomes of it — which
 * is what lets {@see \MarkdownPresentation\Render\ShapeWriter::free()} treat the ink case as
 * the one that is left rather than as a third thing to match.
 *
 * A {@see FreePicture} never paints and never carries a label: `::image` offers neither, and a
 * picture is a `p:pic` with no text body to put one in. A {@see FreeText} never carries a
 * label either — its body is Markdown, so a label on it would be a second way to put the same
 * words in the same box. Both hold the default, which nothing reads.
 *
 * A {@see FreeSlideNumber} is type as well, and its words are the number the renderer fills
 * in rather than anything the deck wrote.
 *
 * The step is the one the deck wrote this on and nothing else, because a free shape does not
 * occupy a column: {@see \MarkdownPresentation\Render\PresentationRenderer} writes it in
 * document order beside the layout's own shapes, and the click chain it builds is what reveals
 * it.
 */
abstract class FreeShape
{
    /**
     * @param string $label The shape's own text, or '' for none. See
     *                      {@see \MarkdownPresentation\Render\Free\ShapeKind::attributes()}.
     * @param Shadow $shadow The shadow, or null where the deck named none.
     */
    protected function __construct(
        public readonly ShapeKind $kind,
        public readonly Placement $placement,
        public readonly int $step,
        public readonly Paint $paint,
        public readonly string $label,
        public readonly ?Shadow $shadow,
    ) {
    }

    /**
     * The name the shape carries in `ppt/slides/slideN.xml`.
     *
     * The directive's own name rather than the block a flow shape is drawn from, because a free
     * shape is not drawn from a block: it is the thing the deck wrote, and `rectangle` is the
     * word to go and find it by. Two shapes of one kind on a slide share the name, which is
     * already true of every `text` shape a deck of paragraphs writes.
     */
    public function name(): string
    {
        return $this->kind->value;
    }

    /** Whether the shape is written before the layout's own shapes rather than after them. */
    public function isBack(): bool
    {
        return $this->placement->isBack();
    }
}
