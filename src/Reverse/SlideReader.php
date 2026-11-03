<?php

declare(strict_types=1);

namespace MarkdownPresentation\Reverse;

use PhpOffice\PhpPresentation\AbstractShape;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Slide as PowerPointSlide;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColour;
use PhpOffice\PhpPresentation\Slide\Background\Gradient as BackgroundGradient;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Style\GradientStop;

/**
 * One slide read back out of a deck.
 *
 * A slide is a set of boxes, and this is where they become a title and a
 * sequence of blocks. The order they come out in is the order they are read:
 *
 * - the title, because a title is a title whatever it is drawn at;
 * - the subtitle, for the same reason;
 * - everything else, in the order the slide lists its shapes in.
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
        $front = [];
        $rest = [];

        foreach ($slide->getShapeCollection() as $shape) {
            $role = $this->roleOf($shape);

            if ($role === 'furniture') {
                continue;
            }

            if ($role === 'title' && $shape instanceof RichText) {
                [$title, $blocks] = $this->titleOf($shape, $title);

                $front = [...$front, ...$blocks];

                continue;
            }

            if ($role === 'subtitle' && $shape instanceof RichText) {
                $front = [...$front, ...$this->mapper->prose(array_values($shape->getParagraphs()))];

                continue;
            }

            $rest[] = [$shape, $role === 'body'];
        }

        $blocks = $front;

        $image = 0;

        foreach ($this->asListed($rest) as [$shape, $outline]) {
            $blocks = [...$blocks, ...$this->mapper->blocks($shape, $index + 1, $image, $outline)];
            $image++;
        }

        return new Slide($index, $title, $blocks, $this->note($slide), $this->background($slide));
    }

    /**
     * The background of the slide, as the frontmatter that names it.
     *
     * A background of a colour is named as the colour it resolved to, and a background of a
     * gradient as the stops it is painted with — the slot a colour came from is in the theme,
     * and the theme is not in the deck.
     *
     * @return array<string, string|int|bool>
     */
    private function background(PowerPointSlide $slide): array
    {
        $background = $slide->getBackground();

        if ($background instanceof BackgroundColour) {
            return $this->coloured($background);
        }

        if (!$background instanceof BackgroundGradient) {
            return [];
        }

        return $this->graduated($background);
    }

    /**
     * The background of a colour, as the frontmatter that names it.
     *
     * Nothing where the background carries no colour: a background of no colour is one the
     * deck cannot draw again.
     *
     * @return array<string, string|int|bool>
     */
    private function coloured(BackgroundColour $background): array
    {
        $colour = $background->getColor();

        if ($colour === null) {
            return [];
        }

        return ['background' => '#' . strtoupper($colour->getRGB())];
    }

    /**
     * The background of a gradient, as the frontmatter that names it.
     *
     * The stops are written as the colours they resolved to and the places they are at, so
     * that a gradient read back is the gradient that was written.
     *
     * @return array<string, string|int|bool>
     */
    private function graduated(BackgroundGradient $background): array
    {
        $fill = $background->getFill();
        $stops = [];

        foreach ($fill->getGradientStops() as $stop) {
            $stops[] = self::stop($stop);
        }

        if ($fill->getFillType() === Fill::FILL_GRADIENT_PATH) {
            return [
                'background-gradient' => implode(',', $stops),
                'background-radial' => true,
            ];
        }

        return [
            'background-gradient' => implode(',', $stops),
            // The format counts its angles from the right and CSS from the top, so the one is
            // the other less ninety degrees.
            'background-angle' => (((int) $fill->getRotation() + 90) % 360),
        ];
    }

    /**
     * One stop of a gradient, as the colour it is and the place it is at.
     *
     * The place is written where it is not one of the two ends, which are where a stop
     * without one is spread to — so a gradient that is read back is the gradient that was
     * written.
     */
    private static function stop(GradientStop $stop): string
    {
        $colour = '#' . strtoupper($stop->getColor()->getRGB());
        $position = $stop->getPosition();

        if ($position === GradientStop::POSITION_START || $position === GradientStop::POSITION_END) {
            return $colour;
        }

        return $colour . '@' . (int) round($position / 1000);
    }

    /**
     * A title's heading, and the paragraphs of the title that are not it.
     *
     * A title is one line in Markdown, so only its first paragraph can be the
     * heading. The rest are the title's own paragraphs and are read as the
     * blocks they are, rather than being run into the heading:
     * `getPlainText()` runs the paragraphs together.
     *
     * Reading the paragraphs as blocks is also what skips the empty one a deck
     * written by PHPPresentation carries at the front of its shapes.
     *
     * @param string|null $title The title the slide carries so far.
     *
     * @return array{?string, list<Block>} The title the slide carries after
     *         this shape is read, and the blocks of the shape that are not it.
     */
    private function titleOf(RichText $shape, ?string $title): array
    {
        $paragraphs = $this->mapper->prose(array_values($shape->getParagraphs()));

        $first = $paragraphs[0] ?? null;
        $text = $first === null ? '' : trim($this->plain($first->inlines));

        // A title that is empty is not a title, so what it held is read as the
        // blocks it was, and the title the slide carries is the one it carried
        // before — a second, empty title leaves the first alone rather than
        // taking the heading away.
        if ($text === '') {
            return [$title, $paragraphs];
        }

        return [$text, array_slice($paragraphs, 1)];
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
    private function asListed(array $shapes): array
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

        return match (true) {
            in_array($type, self::TITLE, true) => 'title',
            in_array($type, self::SUBTITLE, true) => 'subtitle',
            in_array($type, self::BODY, true) => 'body',
            in_array($type, self::FURNITURE, true) => 'furniture',
            default => 'free',
        };
    }

    /**
     * The words of a block, without the formatting around them.
     *
     * A heading carries no formatting of its own, so the words are what is
     * wanted and the bold and the italic are not. A link is the words inside it.
     *
     * @param list<Inline> $inlines
     */
    private function plain(array $inlines): string
    {
        $text = '';

        foreach ($inlines as $inline) {
            $text .= $inline->kind === Inline::LINK ? $this->plain($inline->children) : $inline->text;
        }

        return $text;
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
