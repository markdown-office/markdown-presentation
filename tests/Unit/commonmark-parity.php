<?php

declare(strict_types=1);

use League\CommonMark\Node\Block\Paragraph;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Render\BlockParser;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Render\CommonMark\SlideState;
use MarkdownPresentation\Render\Run;
use MarkdownPresentation\Render\SlideBlocks;
use MarkdownPresentation\Tests\Support\Doctest;
use MarkdownPresentation\Tests\Support\Scratch;

/*
 * The two parsers, one deck at a time.
 *
 * {@see BlockParser} is what renders a slide today and {@see BlockTranslator} reads the same
 * deck with league/commonmark. Nothing downstream of either can tell which one read the
 * deck, so this file is the only thing that knows they are two: every deck in the repository
 * goes through both, and every place they disagree is recorded in
 * `tests/fixtures/commonmark/divergences.json` with the reason the other answer is right.
 *
 * The corpus is everything rather than a sample, because a differential test handed a
 * hand-picked deck proves nothing about the decks nobody picked:
 *
 * - every slide of every deck in `examples/markdown/`, which is what the gallery shows;
 * - every slide of every deck Slidev really split, from `tests/fixtures/slidev/expected.json`;
 * - every string literal and heredoc the suite itself holds, which is where the awkward
 *   inputs live: a regular expression, a fragment of XML, a bullet followed by an unindented
 *   sentence. Those are read through {@see DeckParser} first, so that a whole deck is
 *   compared slide by slide rather than as one slide's text, and a string that will not split
 *   is compared as the slide content it was written as.
 *
 * `tests/fixtures/` is left out of the third corpus: it holds fixture output and the script
 * that builds it, not decks the suite writes.
 *
 * A divergence is a behaviour change somebody has to decide on rather than a bug in either
 * parser, and the record is the decision — it says why the two differ and which one is
 * right. The test fails when a deck that is recorded as differing stops differing, because a
 * strictness that has quietly gone is the one thing this file could not otherwise see.
 */

/**
 * The file the containment test below tries to draw, named rather than spelled at it.
 *
 * A name rather than a path, because the point of that test is that a file which exists and
 * is readable is still not one this render may read.
 */
const OUTSIDE = 'creds.svg';

/**
 * The places the two parsers are meant to differ, and why the other answer is the right one.
 *
 * @return array<string, string> The name of a deck or a string, and the reason.
 */
function parityDivergences(): array
{
    $recorded = [];

    foreach (json_decode(
        (string) file_get_contents(Doctest::root() . '/tests/fixtures/commonmark/divergences.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    ) as $divergence) {
        $recorded[$divergence['name']] = $divergence['why'];
    }

    return $recorded;
}

/**
 * A slide's blocks as one comparison, or the failure the parser raised.
 *
 * Readable rather than two objects compared for equality, because a diff of two hundred
 * objects says nothing about which deck is wrong when one of them is. A refusal is part of
 * it: a parser that agrees about every block and disagrees about which decks it refuses has
 * not agreed about anything.
 */
function parityOf(SlideBlocks $blocks): string
{
    $lines = [];

    foreach ($blocks->blocks() as $at => $block) {
        $name = substr($block::class, (int) strrpos($block::class, '\\') + 1);
        $line = sprintf('%s step=%d column=%d', $name, $block->step(), $blocks->columns()[$at] ?? -1);

        if ($block instanceof Code) {
            $line .= sprintf(' language=%s lines=%s', $block->language, json_encode($block->lines));
        }

        if (property_exists($block, 'runs')) {
            $line .= ' ' . parityRuns($block->runs);
        }

        if ($block instanceof ListBlock) {
            $line .= ' ' . implode(' ', array_map(
                static fn (array $item): string => sprintf(
                    '%d:%s%s:%s',
                    $item['level'],
                    $item['ordered'] ? 'ordered' : 'bullet',
                    $item['start'] === null ? '' : ' starting at ' . $item['start'],
                    parityRuns($item['runs']),
                ),
                $block->items,
            ));
        }

        if ($block instanceof Table) {
            $line .= sprintf(
                ' align=%s rows=%s',
                implode(',', $block->align),
                implode(' | ', array_map(
                    static fn (array $row): string => implode(',', array_map(parityRuns(...), $row)),
                    $block->cells,
                )),
            );
        }

        if ($block instanceof Image) {
            $line .= sprintf(' written=%s alt=%s', $block->written, $block->alt);
        }

        $lines[] = $line;
    }

    return implode("\n", $lines) . sprintf("\ncolumn markers: %d", $blocks->columnMarkers());
}

/**
 * The runs of a piece of text as one line: each run's words, and what it is emphasised as.
 *
 * @param list<Run> $runs
 */
function parityRuns(array $runs): string
{
    return implode('', array_map(
        static fn (Run $run): string => sprintf(
            '[%s%s%s%s%s]',
            $run->text,
            $run->bold ? ' bold' : '',
            $run->italic ? ' italic' : '',
            $run->code ? ' code' : '',
            $run->strike ? ' strike' : '',
            $run->link === null ? '' : ' link ' . $run->link,
        ),
        $runs,
    ));
}

/**
 * What one parser made of one slide's content.
 */
function parityRead(callable $parse): string
{
    try {
        return parityOf($parse());
    } catch (Throwable $failure) {
        return '!' . $failure::class . ': ' . $failure->getMessage();
    }
}

/**
 * Whether two parsers agree about a slide, and what each of them made of it.
 *
 * @return array{0: bool, 1: string, 2: string}
 */
function parityCompare(string $content, string $directory): array
{
    $scope = static fn (): PathScope => new PathScope([$directory]);
    $handWritten = parityRead(static fn (): SlideBlocks => (new BlockParser())->parse($content, 1, $directory, $scope()));
    $league = parityRead(static fn (): SlideBlocks => (new BlockTranslator())->parse($content, 1, $directory, $scope()));

    return [$handWritten === $league, $handWritten, $league];
}

/**
 * Every slide of every deck in `examples/markdown/`.
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function parityExampleDecks(): array
{
    $decks = [];

    foreach (glob(Doctest::root() . '/examples/markdown/*.md') ?: [] as $file) {
        foreach ((new DeckParser())->parse((string) file_get_contents($file))->slides() as $slide) {
            $name = sprintf('examples/markdown/%s slide %d', basename($file), $slide->index() + 1);
            $decks[$name] = [$name, $slide->content(), dirname($file)];
        }
    }

    return $decks;
}

/**
 * Every slide of every deck Slidev really split.
 *
 * The decks that will not split are left out rather than compared as one slide's text:
 * refusing them is {@see DeckParser}'s own job and nothing has reached the block parser.
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function paritySlidevDecks(): array
{
    $directory = Doctest::root() . '/examples/markdown';
    $decks = [];

    foreach (json_decode(
        (string) file_get_contents(Doctest::root() . '/tests/fixtures/slidev/expected.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['decks'] as $deck) {
        try {
            $parsed = (new DeckParser())->parse($deck['markdown']);
        } catch (Throwable) {
            continue;
        }

        foreach ($parsed->slides() as $slide) {
            $name = sprintf('slidev: %s slide %d', $deck['name'], $slide->index() + 1);
            $decks[$name] = [$name, $slide->content(), $directory];
        }
    }

    return $decks;
}

/**
 * Every string literal and heredoc a PHP file under `tests/` holds, except a fixture's.
 *
 * Read through the tokenizer rather than through a list of the strings this file has seen,
 * because such a list stops being true the moment a test is edited — and a corpus is only
 * worth anything while it is the whole corpus.
 *
 * `eval()` because a literal's value is what the tokenizer does not give: every other way
 * of reading one is a second PHP parser written in this file.
 *
 * @return list<string>
 */
function paritySuiteLiterals(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $found = [];
    $count = count($tokens);

    for ($at = 0; $at < $count; $at++) {
        $token = $tokens[$at];

        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $literal = eval('return ' . $token[1] . ';');

            if (is_string($literal)) {
                $found[] = $literal;
            }

            continue;
        }

        if (!is_array($token) || $token[0] !== T_START_HEREDOC) {
            continue;
        }

        $body = '';

        for ($part = $at + 1; $part < $count; $part++) {
            if (!is_array($tokens[$part]) || $tokens[$part][0] === T_END_HEREDOC) {
                $at = $part;

                break;
            }

            if ($tokens[$part][0] === T_ENCAPSED_AND_WHITESPACE) {
                $body .= $tokens[$part][1];
            }
        }

        if ($body !== '') {
            $found[] = $body;
        }
    }

    return $found;
}

/**
 * A name for one of the suite's strings: what it starts with, with nothing unprintable in it.
 *
 * A dataset name is a sentence a reader has to read in a test report, and a string holding
 * a carriage return or a NUL is neither one nor anything a name may carry.
 */
function parityLabel(string $content): string
{
    $readable = preg_replace_callback(
        '/[\x00-\x1f\x7f]/',
        static fn (array $found): string => sprintf('\x%02x', ord($found[0])),
        $content,
    );

    return substr((string) $readable, 0, 70);
}

/**
 * Every slide of every string the suite holds, keyed by where the string was written.
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function paritySuiteDecks(): array
{
    $directory = Doctest::root() . '/examples/markdown';
    $decks = [];

    foreach (['tests/Unit', 'tests/Readme', 'tests/Support'] as $suite) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(Doctest::root() . '/' . $suite, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (paritySuiteLiterals($file->getPathname()) as $literal) {
                $slides = [];

                try {
                    foreach ((new DeckParser())->parse($literal)->slides() as $slide) {
                        $slides[] = $slide->content();
                    }
                } catch (Throwable) {
                    $slides = [$literal];
                }

                foreach ($slides as $at => $content) {
                    $name = sprintf(
                        'tests/%s: %s slide %d',
                        substr($file->getPathname(), strlen(Doctest::root() . '/tests/')),
                        parityLabel($content),
                        $at,
                    );

                    $decks[$name] = [$name, $content, $directory];
                }
            }
        }
    }

    return $decks;
}

$divergences = parityDivergences();

it('reads every deck in the repository the way the hand-written parser does', function (string $name, string $content, string $directory) use ($divergences) {
    [$same, $handWritten, $league] = parityCompare($content, $directory);

    if (isset($divergences[$name])) {
        expect($same)->toBeFalse(
            sprintf('%s is recorded as a place the two parsers differ and has stopped differing.', $name)
            . ' The reason it was recorded: ' . $divergences[$name],
        );

        return;
    }

    expect($league)->toBe($handWritten);
})->with([...parityExampleDecks(), ...paritySlidevDecks()]);

it('reads every string the suite builds the way the hand-written parser does', function () use ($divergences) {
    // One record, two corpora: the decks above are named after the file they are in and the
    // strings below after the file they were written in, and this walk is only over the
    // second of those.
    $recorded = array_filter(
        $divergences,
        static fn (string $name): bool => str_starts_with($name, 'tests/'),
        ARRAY_FILTER_USE_KEY,
    );

    $differing = [];

    foreach (paritySuiteDecks() as $name => [, $content, $directory]) {
        [$same, $handWritten, $league] = parityCompare($content, $directory);

        if ($same) {
            continue;
        }

        $differing[$name] = sprintf("%s\n hand-written: %s\n league: %s", $name, $handWritten, $league);
    }

    // Both ways, because a record nobody checks is a claim: a difference that appears
    // somewhere new is a behaviour change nobody decided on, and a record whose deck no
    // longer differs is a strictness that has gone without anybody being told.
    expect(array_diff_key($differing, $recorded))->toBe([])
        ->and(array_diff_key($recorded, $differing))->toBe([]);
});

it('draws a directive it does not read as the words the deck wrote it as', function () {
    // Not one of the two refusals — a container and a `::note` — so there is nothing to
    // refuse here and something to draw. It is drawn literally, which is the outcome this
    // library had for every directive until it started refusing them, and is the honest one
    // for the rest: a deck with `::foo` on it gets `::foo` on the slide and no pretence that
    // the directive was read. Written as the deck wrote it too — `{a=1}` rather than the
    // `{a="1"}` a serialiser would round-trip it through.
    $content = parityOf((new BlockTranslator())->parse('::foo[bar]{a=1}', 1, __DIR__, deckScope(__DIR__)));

    expect($content)->toContain('Paragraph step=1 column=0 [::foo[bar]{a=1}]');
});

it('draws a directive written in a sentence the way the extension serialises it', function () {
    // The one place league's serialiser is visible on a slide: a text directive is drawn
    // from the node rather than from the deck, because an inline node carries no line of the
    // deck behind it. Its attributes come back quoted.
    $content = parityOf((new BlockTranslator())->parse('Ship it :badge[X]{a=1} today.', 1, __DIR__, deckScope(__DIR__)));

    expect($content)->toContain('[Ship it :badge[X]{a="1"} today.]');
});

it('has no lines for a node the parser built for itself', function () {
    // league counts lines for the blocks it read out of the deck and not for the nodes it
    // assembled around them, and reading a line that is not there has to be an empty answer
    // rather than a warning: a table's rows carry their text and no line of the deck.
    $state = new SlideState(1, __DIR__, deckScope(__DIR__), ['# One']);

    expect($state->lines(new Paragraph()))->toBe([])
        ->and($state->written(new Paragraph()))->toBe('');
});

it('refuses a picture that lands outside the directories the caller allowed', function () {
    $directory = Scratch::directoryPath('league-outside');
    file_put_contents($directory . '/' . OUTSIDE, '<svg xmlns="http://www.w3.org/2000/svg"><desc>secret</desc></svg>');

    expect(fn () => (new BlockTranslator())->parse(
        '![secret](' . OUTSIDE . ')',
        1,
        $directory,
        new PathScope([Scratch::directoryPath('league-somewhere-else')]),
    ))->toThrow(ImageNotFound::class, 'rather than one this render may read');
});
