<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading as LeagueHeading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock as LeagueList;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Table\Table as LeagueTable;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Node\Node;
use MarkdownPresentation\Render\CommonMark\Marker;
use MarkdownOffice\CommonMark\Directive\Directive\DirectiveAttributes;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\MalformedShape;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Exception\UnsupportedLink;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image as ImageBlock;
use MarkdownPresentation\Render\Block\Paragraph as ParagraphBlock;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\CommonMark\FrameReader;
use MarkdownPresentation\Render\CommonMark\InlineTranslator;
use MarkdownPresentation\Render\CommonMark\PictureReader;
use MarkdownPresentation\Render\CommonMark\Refusals;
use MarkdownPresentation\Render\CommonMark\SlideState;
use MarkdownPresentation\Render\TextFrame;

/**
 * A free-placement directive, read as the one shape it is written as.
 *
 * Out of {@see \MarkdownPresentation\Render\CommonMark\BlockTranslator} because a shape is not a
 * block. It takes no column, it is not measured against the layout's box, and the walk has
 * nothing to walk over it — so it goes onto the {@see SlideState} and is carried beside the
 * blocks rather than among them. Keeping the reading here leaves the translator a translator.
 *
 * Everything is read here rather than deferred to the writer, because the attribute vocabulary
 * is per directive ({@see ShapeKind::attributes()}) and an attribute nobody reads has to be a
 * failure before anything is built, which is before there is a shape to refuse it on.
 */
final class ShapeReader
{
    public function __construct(
        private readonly InlineTranslator $inline,
        private readonly PictureReader $pictures,
        private readonly FrameReader $frames,
    ) {
    }

    /**
     * The shape a directive is written as, recorded on the state the slide carries it in.
     *
     * @throws ImageNotFound
     * @throws MalformedShape
     * @throws UnknownDirective
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    public function read(DirectiveBlock $node, SlideState $state): void
    {
        // Not a branch: {@see Refusals::leaves()} has refused every leaf under a name this
        // library does not draw, before the walk began, so a name that is not one of ours cannot
        // arrive. The `??` is there because a `null` below would reach the dispatch and draw
        // nothing without saying so, and a name this library does draw is one that refusal does
        // not cover — so the two must agree rather than one of them be assumed.
        $kind = FreeShapes::kindOf($node->getName())
            ?? throw UnknownDirective::leaf($state->slide, Refusals::oneLine($state->written($node)));

        $attributes = $node->getAttributes();

        self::refusable($state->slide, $kind, $node, $attributes, $state->written($node));

        $placement = Placement::read($state->slide, $kind->value, $attributes, $kind);

        $state->place($this->shape($kind, $placement, $node, $attributes, $state));
    }

    /**
     * The shape, told apart by what it carries rather than by which directive it was written as.
     *
     * @throws ImageNotFound
     * @throws MalformedShape
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function shape(
        ShapeKind $kind,
        Placement $placement,
        DirectiveBlock $node,
        DirectiveAttributes $attributes,
        SlideState $state,
    ): FreeShape {
        // Read before the branch, because every one of the free shapes draws a box and can
        // cast one, and `::image` is no exception: a `p:pic` carries an effect list of its own.
        $shadow = Shadow::read($state->slide, $kind->value, $attributes, $state->theme);

        // A picture takes no fill of its own: it is painted by its own bytes.
        $paint = $kind === ShapeKind::Image
            ? Paint::none()
            : Paint::read($state->slide, $kind->value, $attributes, $state->theme);

        return match (true) {
            $kind === ShapeKind::Image => FreePicture::at(
                $kind,
                $placement,
                $state->step,
                $paint,
                $this->picture($attributes, $state),
                Pictures::fit($state->slide, $kind->value, $attributes),
                $shadow,
            ),
            // Before the text block, which is a box whose body the deck wrote: this one's body
            // is the number, and the renderer writes it.
            $kind === ShapeKind::SlideNumber => FreeSlideNumber::at(
                $kind,
                $placement,
                $state->step,
                $paint,
                TextStyle::read($state->slide, $kind->value, $attributes, $state->theme),
                self::of($state->slide, $attributes),
                $shadow,
            ),
            $kind->isContent() => FreeText::at(
                $kind,
                $placement,
                $state->step,
                $paint,
                $this->frame($node, $state),
                TextStyle::read($state->slide, $kind->value, $attributes, $state->theme),
                $shadow,
            ),
            default => FreeAutoShape::at(
                $kind,
                $placement,
                $state->step,
                $paint,
                Length::written($state->slide, $kind->value, 'label', $attributes, ''),
                $shadow,
            ),
        };
    }

    /**
     * Whether the number of the deck is written after the number of the slide.
     *
     * A flag, and read as one: `getBool()` answers false for a word it does not know, so
     * `of=maybe` was a page number with no deck count on it and nothing said so. Every other
     * value in this vocabulary is refused with a sentence, and this one is refused with one
     * too.
     *
     * A bare `of` asks for the attribute rather than for its value, so it means true.
     *
     * @throws MalformedShape
     */
    private static function of(int $slide, DirectiveAttributes $attributes): bool
    {
        $written = $attributes->get('of');

        if ($written === null) {
            return false;
        }

        if ($written === true) {
            return true;
        }

        $lower = strtolower(trim($written));

        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }

        throw MalformedShape::flag($slide, ShapeKind::SlideNumber->value, 'of', $written);
    }

    /**
     * What this directive may not be written with, said in the terms of the mistake.
     *
     * Three come before the generic refusal, because each says more than "not an attribute"
     * would: a `border-radius` is a real attribute of this vocabulary and only one shape has the
     * corners for it; a `label` is a real one and each shape it is refused on has a reason of
     * its own; and a label in brackets has nowhere on any of them.
     *
     * @throws MalformedShape
     */
    private static function refusable(
        int $slide,
        ShapeKind $kind,
        DirectiveBlock $node,
        DirectiveAttributes $attributes,
        string $written,
    ): void {
        if ($kind !== ShapeKind::RoundRect && $attributes->has('border-radius')) {
            throw MalformedShape::radius($slide, $kind->value);
        }

        if ($attributes->has('label') && ($kind === ShapeKind::Image || $kind->isContent())) {
            throw MalformedShape::noLabel(
                $slide,
                $kind->value,
                match (true) {
                    $kind === ShapeKind::Image => 'a picture is a `p:pic`, which carries no text of its own',
                    $kind === ShapeKind::SlideNumber => 'the number of the slide is written into it by the renderer',
                    default => 'the body between the fences is already its text',
                },
            );
        }

        $read = $kind->attributes();

        foreach (array_keys($attributes->toArray()) as $attribute) {
            if (!in_array($attribute, $read, true)) {
                throw MalformedShape::unknownAttribute($slide, $kind->value, $attribute, $read);
            }
        }

        $label = $node->getLabel();

        if ($label !== null && $label !== '') {
            throw MalformedShape::label($slide, Refusals::oneLine($written));
        }
    }

    /**
     * The Markdown between a `:::content`'s fences, as the one frame it is drawn as.
     *
     * Only the blocks a {@see TextFrame} holds, because one directive is one shape: see {@see
     * FreeText}. Everything else is refused by name rather than drawn, because a table or a
     * picture here would be a second shape and the click chain counts shapes by position.
     *
     * Every block carries the step the container was written on, so a free text block is
     * revealed by the click that reached it rather than by one of its own — there is no marker
     * inside it to carry a step.
     *
     * @throws ImageNotFound
     * @throws MalformedShape
     * @throws UnsupportedImageFormat
     * @throws UnsupportedLink
     */
    private function frame(DirectiveBlock $node, SlideState $state): TextFrame
    {
        $written = Refusals::oneLine($state->written($node));
        $blocks = [];

        foreach ($node->children() as $child) {
            $blocks[] = match (true) {
                $child instanceof Marker => throw MalformedShape::marker(
                    $state->slide,
                    $written,
                    Refusals::oneLine($child->written()),
                ),
                $child instanceof DirectiveBlock => throw MalformedShape::body($state->slide, $written, 'directive'),
                $child instanceof FencedCode, $child instanceof IndentedCode => throw MalformedShape::body(
                    $state->slide,
                    $written,
                    'code',
                ),
                $child instanceof LeagueTable => throw MalformedShape::body($state->slide, $written, 'table'),
                $child instanceof ThematicBreak => throw MalformedShape::body($state->slide, $written, 'rule'),
                $child instanceof LeagueHeading => new Heading(
                    $this->inline->runs($child, $state->slide, $state->theme),
                    $child->getLevel(),
                    $state->step,
                ),
                $child instanceof LeagueList => $this->frames->list($child, $state),
                $child instanceof BlockQuote => new Quote(
                    $this->inline->runsIn(implode("\n", $this->frames->quoted($child, $state)), $state->slide, $state->theme),
                    $state->step,
                ),
                default => $this->paragraph($child, $written, $state),
            };
        }

        if ($blocks === []) {
            throw MalformedShape::emptyBody($state->slide, $written);
        }

        return new TextFrame($blocks);
    }

    /**
     * A paragraph of a free text block, which is a paragraph or a refusal.
     *
     * A picture on a line of its own is one here as it is in a column — the rule that a picture
     * is a picture only on a line of its own is what makes `![a logo](x.png)` a shape rather
     * than a sentence with a hole in it, and it is the same rule in both places.
     *
     * An HTML block is text, exactly as it is in a column: this library has no opinion about
     * what a deck says about HTML and draws the words in it.
     *
     * @throws MalformedShape
     * @throws UnsupportedLink
     */
    private function paragraph(Node $node, string $written, SlideState $state): ParagraphBlock
    {
        $only = $node instanceof Paragraph && iterator_count($node->children()) === 1 ? $node->firstChild() : null;

        if ($only instanceof Image) {
            throw MalformedShape::body($state->slide, $written, 'image');
        }

        if ($node instanceof HtmlBlock) {
            return new ParagraphBlock(
                $this->inline->runsIn(implode("\n", array_map(trim(...), $state->lines($node))), $state->slide, $state->theme),
                $state->step,
            );
        }

        return new ParagraphBlock($this->inline->runs($node, $state->slide, $state->theme), $state->step);
    }

    /**
     * The picture a `::image` names, through the same rules a picture in a column goes through.
     *
     * @throws ImageNotFound
     * @throws MalformedShape
     * @throws UnsupportedImageFormat
     */
    private function picture(DirectiveAttributes $attributes, SlideState $state): ImageBlock
    {
        $src = $attributes->getString('src');

        if ($src === null || trim($src) === '') {
            throw MalformedShape::attributeValue(
                $state->slide,
                ShapeKind::Image->value,
                'src',
                'src',
                'A picture names the file it draws: src="chart.png". The path is read from the directory the deck is in.',
            );
        }

        return $this->pictures->path($src, $attributes->getString('alt', '') ?? '', $state);
    }
}
