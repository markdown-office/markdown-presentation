<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\CommonMark;

use League\CommonMark\Environment\EnvironmentInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\InlineParserEngine;
use League\CommonMark\Reference\ReferenceMap;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownPresentation\Exception\MalformedTextStyle;
use MarkdownPresentation\Exception\UnknownTextStyle;
use MarkdownPresentation\Exception\UnsupportedLink;
use MarkdownPresentation\Render\Run;
use MarkdownPresentation\Theme\Theme;

/**
 * league/commonmark's inline nodes as the runs a slide draws.
 *
 * The style is carried down the tree rather than read off each node, because CommonMark
 * nests one emphasis inside another, and a single delimiter pair cannot say which: `*one
 * **two** three*` is three runs, and the middle one is both italic and bold.
 *
 * Runs of the same style are joined as they are written, because a line break is a node of
 * its own: `one\ntwo` is one run of text to a reader, and three would draw the same words in
 * three shapes.
 *
 * A link's target is checked here rather than left to the reader. A target in a `.pptx` is
 * followed when a reader clicks it, so `javascript:` and a UNC share are refused at parse
 * time rather than written into a file somebody opens.
 *
 * @phpstan-import-type Properties from StyleReader
 * @phpstan-type Style array{bold: ?bool, italic: ?bool, code: bool, strike: ?bool, link: ?string, colour: ?string, size: ?int, font: ?string, fontWhere: ?string, underline: ?bool}
 */
final class InlineTranslator
{
    /**
     * The targets a deck may point at, in the order the message names them.
     *
     * `stripos()` rather than `str_starts_with()` because a scheme is case-insensitive and
     * `HTTP://` means the same thing as `http://`.
     */
    private const LINK_SCHEMES = ['https://', 'http://', 'mailto:'];

    /**
     * Any scheme at all: what a reader will act on rather than open, which is every scheme
     * this list does not name.
     *
     * The one or more character before the colon is what keeps a Windows drive letter out of
     * it — `C:\logo.png` is a path a deck writes, and a one-letter scheme is a reader's
     * problem rather than a deck author's.
     */
    private const SCHEME = '#\A[a-zA-Z][a-zA-Z0-9+.-]+:#';

    /**
     * A target that begins with a path separator, which is a Windows or UNC share in either
     * spelling — `\\host\share\payload.exe` and `//host/share` — or a path rooted at the
     * reader's own machine.
     *
     * One separator rather than the two that spell a share, because league reduces `\\host` to
     * `/host` on the way in and a pattern waiting for two would never see the share it was
     * written for. A single leading separator is already the thing to refuse on its own: a
     * reader resolves it against the machine the deck is opened on or against the deck's own
     * folder, which is never what a deck means by a link.
     */
    private const SHARE = '#\A[/\\\\]#';

    /**
     * The style a run is written in before anything has emphasised it.
     *
     * An array rather than a `Run`, because a run carries text and this carries none: it is
     * the flags and the target, handed down the tree and read off at the leaves.
     *
     * A property that is null is one nothing has said anything about yet, and the run takes
     * whatever the block it is in gave it.
     *
     * @var Style
     */
    private const PLAIN = [
        'bold' => null,
        'italic' => null,
        'code' => false,
        'strike' => null,
        'link' => null,
        'colour' => null,
        'size' => null,
        'font' => null,
        'fontWhere' => null,
        'underline' => null,
    ];

    private readonly StyleReader $styles;

    public function __construct(private readonly EnvironmentInterface $environment)
    {
        $this->styles = new StyleReader();
    }

    /**
     * The runs a node's own children make, in the style it was written in.
     *
     * `$break` is what a line break becomes. A paragraph written across two lines is one
     * block with the break in the middle of it and a list item written across two lines is
     * one line of text on the slide, so the two ask for different answers from the same
     * question.
     *
     * @return list<Run>
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     * @throws UnsupportedLink
     */
    public function runs(Node $container, int $slide, Theme $theme, string $break = "\n"): array
    {
        $runs = [];

        $this->walk($container->children(), $runs, self::PLAIN, $slide, $theme, $break);

        return $runs;
    }

    /**
     * The runs a piece of Markdown makes, parsed on its own.
     *
     * For the text a node carries rather than the tree it owns: the lines of a quote with
     * the markers taken off, or the lines of a shape this library draws as the words on it.
     *
     * @return list<Run>
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     * @throws UnsupportedLink
     */
    public function runsIn(string $markdown, int $slide, Theme $theme, string $break = "\n"): array
    {
        $paragraph = new Paragraph();

        (new InlineParserEngine($this->environment, new ReferenceMap()))->parse($markdown, $paragraph);

        return $this->runs($paragraph, $slide, $theme, $break);
    }

    /**
     * What a reader sees of these runs, which is the text with its markup taken out.
     *
     * The comparison a title is made against goes through here and through
     * {@see self::plain()} and nothing else, so the two sides of it cannot be normalised
     * differently and then found unequal.
     *
     * @param list<Run> $runs
     */
    public static function text(array $runs): string
    {
        return implode('', array_map(static fn (Run $run): string => $run->text, $runs));
    }

    /**
     * The text as a reader reads it: parsed and laid back out, so the markup is gone.
     *
     * An instance method rather than a static one because the strip is this translator's: a
     * title and a heading normalised by two translators are two strings that agree only
     * while the two translators do.
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     * @throws UnsupportedLink
     */
    public function plain(string $text, int $slide, Theme $theme): string
    {
        return self::text($this->runsIn($text, $slide, $theme));
    }

    /**
     * @param iterable<Node> $nodes
     * @param list<Run>      $runs
     * @param Style          $style
     * @param int            $slide
     * @param Theme          $theme
     * @param string         $break
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     * @throws UnsupportedLink
     */
    private function walk(iterable $nodes, array &$runs, array $style, int $slide, Theme $theme, string $break = "\n"): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Link) {
                array_push($runs, ...$this->linked($node, $style, $slide, $theme));

                continue;
            }

            if ($node instanceof Image) {
                $this->picture($node, $runs, $slide, $theme);

                continue;
            }

            if ($node instanceof Emphasis) {
                $this->walk($node->children(), $runs, ['italic' => true] + $style, $slide, $theme, $break);

                continue;
            }

            if ($node instanceof Strong) {
                $this->walk($node->children(), $runs, ['bold' => true] + $style, $slide, $theme, $break);

                continue;
            }

            if ($node instanceof Strikethrough) {
                $this->walk($node->children(), $runs, ['strike' => true] + $style, $slide, $theme, $break);

                continue;
            }

            if ($node instanceof DirectiveInline) {
                $this->styled($node, $runs, $style, $slide, $theme);

                continue;
            }

            // Everything else in a paragraph is text as far as a slide is concerned: a text
            // node, an HTML tag this library draws literally, and a line break.
            $this->append($runs, new Run(
                match (true) {
                    $node instanceof Newline => $break,
                    default => self::words($node),
                },
                bold: $style['bold'] === true,
                italic: $style['italic'] === true,
                code: $node instanceof Code || $style['code'],
                link: $style['link'],
                strike: $style['strike'] === true,
                colour: $style['colour'],
                size: $style['size'],
                font: $style['font'],
                underline: $style['underline'] === true,
                fontWhere: $style['fontWhere'],
            ));
        }
    }

    /**
     * The run a text style draws, in the style the words around it were written in.
     *
     * The style's own properties win over the ones the words around it carry, because the
     * style is the more particular of the two statements: a word written in bold by the
     * paragraph and in a style that says not bold is not bold.
     *
     * @param Style     $style
     * @param list<Run> $runs
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     */
    private function styled(DirectiveInline $node, array &$runs, array $style, int $slide, Theme $theme): void
    {
        $style = self::merged($style, $this->styles->read($node, $slide, $theme));

        $this->append($runs, new Run(
            (string) $node->getLabel(),
            bold: $style['bold'] === true,
            italic: $style['italic'] === true,
            code: $style['code'],
            link: $style['link'],
            strike: $style['strike'] === true,
            colour: $style['colour'],
            size: $style['size'],
            font: $style['font'],
            underline: $style['underline'] === true,
            fontWhere: $style['fontWhere'],
        ));
    }

    /**
     * A link's runs, each carrying the target the deck wrote.
     *
     * One run per piece of text rather than one for the whole label, because a label is text
     * this library can emphasise: a link written `[**docs**](…)` is a bold link, and a single
     * run for the label would have to carry the markup inside it as text.
     *
     * @param Style $style
     *
     * @return list<Run>
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     * @throws UnsupportedLink
     */
    private function linked(Link $node, array $style, int $slide, Theme $theme): array
    {
        $style['link'] = self::target($node->getUrl());

        $runs = [];
        $this->walk($node->children(), $runs, $style, $slide, $theme);

        return $runs;
    }

    /**
     * The runs a picture written inside a sentence makes.
     *
     * A picture on a line of its own is a shape, and {@see BlockTranslator} draws it as one.
     * Inside a sentence it is text, and the text a reader sees is the `!` and then the label
     * as a link — which is what every Markdown renderer does with a picture it cannot draw,
     * and the reason the one this library does draw is written on a line of its own.
     *
     * @param list<Run> $runs
     *
     * @throws MalformedTextStyle
     * @throws UnknownTextStyle
     * @throws UnsupportedLink
     */
    private function picture(Image $node, array &$runs, int $slide, Theme $theme): void
    {
        $this->append($runs, new Run('!'));

        $style = self::PLAIN;
        $style['link'] = self::source($node->getUrl());

        $this->walk($node->children(), $runs, $style, $slide, $theme);
    }

    /**
     * The words an inline node is made of.
     *
     * A node that carries its own words gives them up, and anything else is read as the words
     * inside it: that is what happens to a shape nested where a slide has no room for it. A
     * table inside a bullet is drawn as the text of its cells rather than as nothing at all,
     * because a bullet with a table under it is a bullet somebody wrote.
     */
    private static function words(Node $node): string
    {
        return $node instanceof AbstractStringContainer
            ? $node->getLiteral()
            : implode('', array_map(self::words(...), iterator_to_array($node->children(), false)));
    }

    /**
     * The target a deck is allowed to point a reader at.
     *
     * A relative target is not one of the refused shapes and cannot be: `[docs](logo.png)`
     * inside a sentence is a picture the renderer draws as text, and refusing every relative
     * target would refuse every deck that writes one.
     *
     * @throws UnsupportedLink
     */
    private static function target(string $target): string
    {
        return self::check($target, true);
    }

    /**
     * A picture's source, which is a path this library resolves rather than a target a reader
     * follows.
     *
     * The scheme is refused and the share is not, which is the difference between the two: a
     * picture is looked for on disk inside the directories the caller allowed, so a leading
     * separator is an ordinary path and not a machine the reader would connect to. A scheme
     * still has nothing to do with a file, so it is refused here as it is for a link.
     */
    private static function source(string $source): string
    {
        return self::check($source, false);
    }

    private static function check(string $target, bool $followed): string
    {
        // The check reads the decoded form and the link keeps what league handed over. That is
        // not a convenience: league percent-encodes a target on the way in, so `\\host\share`
        // arrives as `%5Chost%5Cshare` and a check written against the raw string sees a path
        // rather than the share it was — the one refusal here that a reader would have acted on.
        // Decoding for the check also catches an encoded scheme, which is the same trick the
        // refusal exists for.
        $read = rawurldecode($target);

        foreach (self::LINK_SCHEMES as $scheme) {
            if (stripos($read, $scheme) === 0) {
                return $target;
            }
        }

        $share = $followed && preg_match(self::SHARE, $read) === 1;

        if (preg_match(self::SCHEME, $read) === 1 || $share) {
            throw UnsupportedLink::target($target);
        }

        return $target;
    }

    /**
     * A run added to the ones before it, or joined onto the one already there.
     *
     * @param list<Run> $runs
     */
    private static function append(array &$runs, Run $run): void
    {
        $last = $runs === [] ? null : $runs[count($runs) - 1];

        if ($last === null || !self::sameStyle($last, $run)) {
            $runs[] = $run;

            return;
        }

        // Popped and appended rather than overwritten: an index is not what makes a list
        // one, and this is handed a list back.
        array_pop($runs);

        $runs[] = new Run(
            $last->text . $run->text,
            bold: $run->bold,
            italic: $run->italic,
            code: $run->code,
            link: $run->link,
            strike: $run->strike,
            colour: $run->colour,
            size: $run->size,
            font: $run->font,
            underline: $run->underline,
            fontWhere: $run->fontWhere,
        );
    }

    /**
     * One style laid over another, property by property.
     *
     * A property the other does not set is left as this one had it, which is what "the
     * attributes override the named style" means: an attribute says nothing about the
     * properties it does not name.
     *
     * @param Style          $style
     * @param Properties     $over
     *
     * @return Style
     */
    private static function merged(array $style, array $over): array
    {
        foreach ($over as $property => $value) {
            if ($value !== null) {
                $style[$property] = $value;
            }
        }

        return $style;
    }

    /**
     * Whether two runs are drawn the same way, which is what makes them one run of text.
     */
    private static function sameStyle(Run $one, Run $two): bool
    {
        return $one->bold === $two->bold
            && $one->italic === $two->italic
            && $one->code === $two->code
            && $one->strike === $two->strike
            && $one->link === $two->link
            && $one->colour === $two->colour
            && $one->size === $two->size
            && $one->font === $two->font
            && $one->underline === $two->underline;
    }
}
