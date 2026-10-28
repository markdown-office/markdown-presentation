<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Document\Slide as SourceSlide;
use MarkdownPresentation\Exception\ChromeNotFound;
use MarkdownPresentation\Exception\MalformedChrome;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\TooManyShapes;
use MarkdownPresentation\Exception\UnknownTemplate;
use MarkdownPresentation\Render\Free\FreeShape;
use MarkdownPresentation\Render\Free\FreeSlideNumber;
use MarkdownPresentation\Template\ChromeFile;
use MarkdownPresentation\Template\Definition;
use MarkdownPresentation\Template\Templates;
use MarkdownPresentation\Theme\Theme;

/**
 * The furniture of a deck: the templates it has, the chrome they draw, and the shapes a
 * slide carries because it names one.
 *
 * A template is written once and drawn on every slide that names it, so the furniture is
 * read once for the deck and drawn on every slide: a picture in a template is a picture the
 * deck pays for once.
 *
 * Out of {@see PresentationRenderer} because it is a kind of thing the renderer draws rather
 * than the walk that draws it. The deck's own blocks are the other kind, and the two meet
 * only in the shapes a slide ends up carrying.
 */
final class Furniture
{
    /**
     * The most shapes one slide may carry, the chrome of a template included.
     *
     * The chrome of a template is read once and drawn on every slide that names it, so the
     * shapes of a template are multiplied by the slides that draw them: a template of two
     * hundred shapes on sixty slides is twelve thousand shapes and ten megabytes of slide
     * XML. The slide count is bounded by
     * {@see \MarkdownPresentation\Render\PresentationRenderer::MAX_SLIDES}, and this is the
     * other half of that bound.
     *
     * A hundred shapes is far more than a slide asks for, which is the point of a ceiling
     * that is generous: it bounds the damage without being something a real deck can reach
     * by accident.
     */
    public const MAX_SHAPES = 100;

    /**
     * @param Deck       $deck   The deck being rendered, which holds the templates it
     *                          defines and the chrome file it names.
     * @param ChromeFile $files  The reader of the chrome file the deck names.
     * @param Chrome     $chrome The reader of a template's body.
     */
    public function __construct(
        private readonly Deck $deck,
        private readonly ChromeFile $files,
        private readonly Chrome $chrome,
    ) {
    }

    /**
     * The templates the deck has: its own over the ones a chrome file holds.
     *
     * Read before anything is built, so a chrome file that cannot be read fails while there
     * is still no file on disk to clean up.
     *
     * @throws ChromeNotFound
     * @throws MalformedChrome
     * @throws MalformedFrontmatter
     */
    public function templates(): Templates
    {
        return Templates::of($this->deck->definitions(), $this->imported());
    }

    /**
     * The templates a slide names, checked.
     *
     * A slide may want more than one piece of furniture: a cover wants the wordmark and a
     * content slide wants the wordmark and the page number.
     *
     * @throws MalformedFrontmatter
     * @throws UnknownTemplate
     */
    public function check(Templates $templates, SourceSlide $slide): void
    {
        $this->names($templates, $slide);
    }

    /**
     * The chrome every template of the deck draws, by name.
     *
     * @return array<string, list<FreeShape>>
     *
     * @throws MalformedChrome
     */
    public function drawn(Templates $templates, Theme $theme): array
    {
        $drawn = [];

        foreach ($templates->all() as $name => $definition) {
            $drawn[$name] = $this->chrome->shapes($definition, $this->label($templates, $name), $theme);
        }

        return $drawn;
    }

    /**
     * The chrome of the templates a slide names, beside the shapes the slide placed itself.
     *
     * The chrome last, so that it is drawn over what the slide placed itself: a full-bleed
     * picture is behind the wordmark and not over it, which is the order the deck wrote the
     * wordmark in before the wordmark was a template.
     *
     * The number of the slide is filled in on the way, in the slide's own shapes and in the
     * chrome alike: it is the one thing about a page number that is different on every slide,
     * and a shape carrying it cannot be drawn before the slide it is on is known.
     *
     * The shapes are counted on the way, because the chrome of a template is drawn on every
     * slide that names it and the slide count is bounded: {@see self::MAX_SHAPES} is the
     * other half of that bound.
     *
     * @param array<string, list<FreeShape>> $drawn The chrome every template draws.
     *
     * @throws MalformedFrontmatter
     * @throws TooManyShapes
     * @throws UnknownTemplate
     */
    public function on(
        Templates $templates,
        array $drawn,
        SlideBlocks $content,
        SourceSlide $source,
    ): SlideBlocks {
        $shapes = [];

        foreach ($content->shapes() as $shape) {
            $shapes[] = $this->numbered($shape, $source->index() + 1);
        }

        foreach ($this->names($templates, $source) as $name) {
            foreach ($drawn[$name] as $shape) {
                $shapes[] = $this->numbered($shape, $source->index() + 1);
            }
        }

        if (count($shapes) > self::MAX_SHAPES) {
            throw TooManyShapes::on($source->index() + 1, count($shapes), self::MAX_SHAPES);
        }

        if ($shapes === []) {
            return $content;
        }

        return new SlideBlocks(
            $content->blocks(),
            $content->columns(),
            $content->columnMarkers(),
            $shapes,
        );
    }

    /**
     * The templates a slide names, and the refusal of a name there is no template under.
     *
     * @return list<string>
     *
     * @throws MalformedFrontmatter
     * @throws UnknownTemplate
     */
    private function names(Templates $templates, SourceSlide $slide): array
    {
        $named = $slide->frontmatterValue('template');

        if ($named === null) {
            return [];
        }

        $names = [];

        foreach (is_array($named) ? $named : [$named] as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw MalformedFrontmatter::wrongType(
                    $slide->index() + 1,
                    'template',
                    'a template name or a list of them',
                );
            }

            $names[] = trim($name);
        }

        foreach ($names as $name) {
            if (!$templates->has($name)) {
                throw UnknownTemplate::named($slide->index() + 1, $name, $templates->names());
            }
        }

        return $names;
    }

    /**
     * The templates a chrome file holds.
     *
     * A file beside the deck, named by the deck's frontmatter and resolved against the
     * deck's own directory, so that a deck and the furniture it shares travel together.
     *
     * @return list<Definition>
     *
     * @throws ChromeNotFound
     * @throws MalformedChrome
     * @throws MalformedFrontmatter
     */
    private function imported(): array
    {
        $written = $this->deck->frontmatterValue('chrome');

        if ($written === null) {
            return [];
        }

        if (!is_string($written) || trim($written) === '') {
            throw MalformedFrontmatter::wrongType(1, 'chrome', 'a path to a file of chrome');
        }

        $markdown = $this->files->read(trim($written));
        $chrome = (new DeckParser())->parse($markdown);

        // A chrome file holds definitions and nothing else: a slide in it is a slide
        // nobody asked for, and one that would be drawn on a deck it was not written for.
        if ($chrome->slides() !== []) {
            throw MalformedChrome::slide(trim($written));
        }

        return $chrome->definitions();
    }

    /**
     * The slide a mistake in a template would first be seen on.
     *
     * The chrome of a template is read once, so the refusal in it has to name a slide a
     * reader can look at, and the first slide that draws the template is the one they can.
     * A template no slide draws is read against the first slide of the deck, because there
     * is no other slide to name.
     */
    private function label(Templates $templates, string $name): int
    {
        foreach ($this->deck->slides() as $slide) {
            if (in_array($name, $this->names($templates, $slide), true)) {
                return $slide->index() + 1;
            }
        }

        return 1;
    }

    /**
     * A shape with the number of the slide filled into it, or the shape itself where it
     * carries no number.
     */
    private function numbered(FreeShape $shape, int $number): FreeShape
    {
        return $shape instanceof FreeSlideNumber
            ? $shape->numbered($number, $this->deck->count())
            : $shape;
    }
}
