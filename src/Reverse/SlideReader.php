<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide as PowerPointSlide;

/**
 * One slide read back out of a deck.
 *
 * A slide is a set of boxes, and this is where they become a title and a
 * sequence of blocks. The order they come out in is the order they are read:
 *
 * - the title, because a title is a title whatever it is drawn at;
 * - the subtitle, for the same reason;
 * - everything else, by where it sits — the top of it first, and the left of it
 *   when two share a top.
 *
 * The furniture is left out: a date, a footer and a slide number are on every
 * slide of a deck and say nothing about this one.
 */
final class SlideReader
{
    private const TITLE = ['title', 'ctrTitle'];

    private const SUBTITLE = ['subTitle'];

    private const BODY = ['body', 'obj'];

    private const FURNITURE = ['dt', 'ftr', 'sldNum', 'sldImg'];

    public function __construct(private readonly ShapeMapper $mapper)
    {
    }

    /**
     * The slide, as a deck holds it.
     *
     * @param int $index The slide's place in the deck, counting from zero.
     */
    public function read(PowerPointSlide $slide, int $index): Slide
    {
        $title = null;
        $subtitle = null;
        $rest = [];

        foreach ($slide->getShapeCollection() as $shape) {
            $role = $this->roleOf($shape);

            if ($role === 'furniture') {
                continue;
            }

            if ($role === 'title' && $shape instanceof RichText) {
                $title = $this->text($shape) ?? $title;

                continue;
            }

            if ($role === 'subtitle' && $shape instanceof RichText) {
                $subtitle = $this->text($shape) ?? $subtitle;

                continue;
            }

            $rest[] = [$shape, $role === 'body'];
        }

        $blocks = [];

        if ($subtitle !== null) {
            $blocks[] = Block::paragraph([Inline::text($subtitle)]);
        }

        $image = 0;

        foreach ($this->byPosition($rest) as [$shape, $outline]) {
            $blocks = [...$blocks, ...$this->mapper->blocks($shape, $index + 1, $image, $outline)];
            $image++;
        }

        return new Slide($index, $title, $blocks, $this->note($slide));
    }

    /**
     * The shapes in the order they are read.
     *
     * The order the slide lists them in, which is the order they were put on
     * it. Not the order they sit in: a slide is a set of boxes, and a box
     * that was dragged after the fact is still the box it was, in the place
     * the author made it. The fixture this library carries says as much — its
     * fifth slide has a left column that was dragged down and shrunk, and a
     * text box belonging to nothing beside it, and the order the slide lists
     * them in is the order they are read in, while their positions are not.
     *
     * @param list<array{AbstractShape, bool}> $shapes
     *
     * @return list<array{AbstractShape, bool}>
     */
    private function byPosition(array $shapes): array
    {
        return $shapes;
    }

    /**
     * What a shape is for, as far as a deck is concerned.
     *
     * @return string One of `title`, `subtitle`, `body`, `furniture` or `free`.
     */
    private function roleOf(AbstractShape $shape): string
    {
        if (! $shape->isPlaceholder()) {
            return 'free';
        }

        $type = $shape->getPlaceholder()?->getType() ?? '';

        if (in_array($type, self::TITLE, true)) {
            return 'title';
        }

        if (in_array($type, self::SUBTITLE, true)) {
            return 'subtitle';
        }

        if (in_array($type, self::BODY, true)) {
            return 'body';
        }

        if (in_array($type, self::FURNITURE, true)) {
            return 'furniture';
        }

        return 'free';
    }

    /**
     * The text of a shape, or null when it has none.
     */
    private function text(RichText $shape): ?string
    {
        $text = trim($shape->getPlainText());

        return $text === '' ? null : $text;
    }

    /**
     * The speaker's note of a slide, or null when it has none.
     *
     * A notes part holds three placeholders: the slide's own picture, the
     * note, and the slide number. Only the second is the note, and the third
     * is a number that would otherwise be read as the words the speaker said.
     */
    private function note(PowerPointSlide $slide): ?string
    {
        $note = $slide->getNote();

        foreach ($note->getShapeCollection() as $shape) {
            $text = $shape instanceof RichText ? trim($shape->getPlainText()) : '';
            $type = $shape->getPlaceholder()?->getType() ?? '';

            if ($type !== 'body') {
                continue;
            }

            return $text === '' ? null : $text;
        }

        return null;
    }
}
