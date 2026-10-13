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
use MarkdownPresentation\Exception\UnsupportedLink;
use MarkdownPresentation\Render\Run;

/**
 * league/commonmark's inline nodes as the runs a slide draws.
 *
 * The style is carried down the tree rather than read off each node, because CommonMark
 * nests one emphasis inside another where {@see \MarkdownPresentation\Render\InlineParser}
 * reads a single delimiter pair: `*one **two** three*` is three runs here and one run of
 * asterisks there. Both are honest about what a reader sees, and this is the one that keeps
 * the bold.
 *
 * Runs of the same style are joined as they are written, because a line break is a node of
 * its own: `one\ntwo` is one run of text to a reader, and three would draw the same words in
 * three shapes.
 *
 * A link's target is checked here for the reason
 * {@see \MarkdownPresentation\Render\InlineParser} checks it. A target in a `.pptx` is
 * followed when a reader clicks it, so `javascript:` and a UNC share are refused at parse
 * time rather than written into a file somebody opens.
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
     * A Windows or UNC share, in either spelling: `\\host\share\payload.exe` and
     * `//host/share`.
     */
    private const SHARE = '#\A[/\\\\]{2}#';

    /**
     * The style a run is written in before anything has emphasised it.
     *
     * An array rather than a `Run`, because a run carries text and this carries none: it is
     * the four flags and the target, handed down the tree and read off at the leaves.
     *
     * @var array{bold: bool, italic: bool, code: bool, strike: bool, link: string|null}
     */
    private const PLAIN = ['bold' => false, 'italic' => false, 'code' => false, 'strike' => false, 'link' => null];

    public function __construct(private readonly EnvironmentInterface $environment)
    {
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
     * @throws UnsupportedLink
     */
    public function runs(Node $container, string $break = "\n"): array
    {
        $runs = [];

        $this->walk($container->children(), $runs, self::PLAIN, $break);

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
     * @throws UnsupportedLink
     */
    public function runsIn(string $markdown, string $break = "\n"): array
    {
        $paragraph = new Paragraph();

        (new InlineParserEngine($this->environment, new ReferenceMap()))->parse($markdown, $paragraph);

        return $this->runs($paragraph, $break);
    }

    /**
     * @param iterable<Node>                                                               $nodes
     * @param list<Run>                                                                    $runs
     * @param array{bold: bool, italic: bool, code: bool, strike: bool, link: string|null} $style
     *
     * @throws UnsupportedLink
     */
    private function walk(iterable $nodes, array &$runs, array $style, string $break = "\n"): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Link) {
                array_push($runs, ...$this->linked($node, $style));

                continue;
            }

            if ($node instanceof Image) {
                $this->picture($node, $runs);

                continue;
            }

            if ($node instanceof Emphasis) {
                $this->walk($node->children(), $runs, ['italic' => true] + $style);

                continue;
            }

            if ($node instanceof Strong) {
                $this->walk($node->children(), $runs, ['bold' => true] + $style);

                continue;
            }

            if ($node instanceof Strikethrough) {
                $this->walk($node->children(), $runs, ['strike' => true] + $style);

                continue;
            }

            // Everything else in a paragraph is text as far as a slide is concerned: a text
            // node, an HTML tag this library draws literally, a line break, and a directive
            // written in a sentence, which is not one this library draws and so is its own
            // spelling rather than its label.
            $this->append($runs, new Run(
                match (true) {
                    $node instanceof Newline => $break,
                    $node instanceof DirectiveInline => (string) $node,
                    default => self::words($node),
                },
                bold: $style['bold'],
                italic: $style['italic'],
                code: $node instanceof Code || $style['code'],
                link: $style['link'],
                strike: $style['strike'],
            ));
        }
    }

    /**
     * A link's runs, each carrying the target the deck wrote.
     *
     * One run per piece of text rather than one for the whole label, because a label is text
     * this library can emphasise: a link written `[**docs**](…)` is a bold link, and a single
     * run for the label would have to carry the markup inside it as text.
     *
     * @param array{bold: bool, italic: bool, code: bool, strike: bool, link: string|null} $style
     *
     * @return list<Run>
     *
     * @throws UnsupportedLink
     */
    private function linked(Link $node, array $style): array
    {
        $style['link'] = self::target($node->getUrl());

        $runs = [];
        $this->walk($node->children(), $runs, $style);

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
     * @throws UnsupportedLink
     */
    private function picture(Image $node, array &$runs): void
    {
        $this->append($runs, new Run('!'));

        $style = self::PLAIN;
        $style['link'] = self::target($node->getUrl());

        $this->walk($node->children(), $runs, $style);
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
        foreach (self::LINK_SCHEMES as $scheme) {
            if (stripos($target, $scheme) === 0) {
                return $target;
            }
        }

        if (preg_match(self::SCHEME, $target) === 1 || preg_match(self::SHARE, $target) === 1) {
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
        );
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
            && $one->link === $two->link;
    }
}
