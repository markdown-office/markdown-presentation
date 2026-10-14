<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

/**
 * A shape a deck placed itself, rather than one the layout put where it did.
 *
 * Three kinds rather than one class with a nullable payload on each: a shape is written as one
 * shape, and {@see \MarkdownPresentation\Render\ShapeWriter} has to know which before it can
 * pick a shape class, so a {@see FreePicture} whose picture was missing and a {@see FreeText}
 * whose frame was missing would be two states the draw site has to defend against. Three
 * classes make both impossible rather than merely discouraged.
 *
 * The paint is here rather than on the two that use it because a shape that is neither type
 * nor a picture is an ink shape whatever else becomes of it — which is what lets
 * {@see \MarkdownPresentation\Render\ShapeWriter::free()} treat the ink case as the one that is
 * left rather than as a third thing to match. A {@see FreePicture} never paints: `::image`
 * offers no `fill` or `stroke`, so its paint is the default and nothing reads it.
 *
 * The step is the one the deck wrote this on and nothing else, because a free shape does not
 * occupy a column: {@see \MarkdownPresentation\Render\PresentationRenderer} writes it in
 * document order beside the layout's own shapes, and the click chain it builds is what reveals
 * it.
 */
abstract class FreeShape
{
    protected function __construct(
        public readonly ShapeKind $kind,
        public readonly Placement $placement,
        public readonly int $step,
        public readonly Paint $paint,
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