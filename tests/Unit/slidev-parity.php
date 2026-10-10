<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;

/*
 * The splitter against decks Slidev really split.
/**
 * The other file in this suite checks the behaviour this library intends; this one
 * checks the behaviour Slidev has, which is the only way to know whether a port is a
 * port. Every deck in the fixture was run through `parseSync` in
 * `slidev/packages/parser/src/core.ts` and recorded, so a change to the parser shows
 * up as a diff against the real thing rather than against a reading of it.
 * `tools/slidev-parity/regenerate.sh` is how the recording is made.
 *
 * `divergences.json` holds the decks where the two are meant to differ, each with the
 * reason and what this library produces instead. It is a recording too: a divergence
 * whose recorded output this library now produces some other way is a strictness that
 * has quietly changed, and the test fails rather than agreeing with it.
*/

$fixture = json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/fixtures/slidev/expected.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$divergences = [];

foreach (json_decode(
    (string) file_get_contents(dirname(__DIR__) . '/fixtures/slidev/divergences.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
) as $divergence) {
    $divergences[$divergence['name']] = $divergence;
}

/**
 * What this library produced for one deck, in the shape a recording is in.
 *
 * @return array{slides?: list<array<string, mixed>>, error?: string}
*/
function splitFor(DeckParser $parser, string $markdown): array
{
    try {
        $deck = $parser->parse($markdown);
    } catch (Throwable $error) {
        return ['error' => $error->getMessage()];
    }

    $slides = [];

    foreach ($deck->slides() as $slide) {
        $slides[] = [
            'index' => $slide->index(),
            'raw' => $slide->raw(),
            'content' => $slide->content(),
            'frontmatter' => $slide->frontmatter(),
            'title' => $slide->title(),
            'level' => $slide->level(),
            'note' => $slide->note(),
        ];
    }

    return ['slides' => $slides];
}

it('splits every recorded deck the way Slidev did', function (string $name, string $markdown) use ($fixture, $divergences) {
    $produced = splitFor(new DeckParser(), $markdown);
    $recorded = $fixture['slides'][$name];

    if (isset($divergences[$name])) {
        expect($produced)->toBe($divergences[$name]['port']);

        return;
    }

    expect($produced)->toBe($recorded);
})->with($fixture['decks']);

it('has no divergence that has quietly stopped being one', function () use ($fixture, $divergences) {
    $differing = [];

    foreach ($divergences as $name => $divergence) {
        if ($divergence['port'] === $fixture['slides'][$name]) {
            $differing[] = $name;
        }
    }

    // A declared divergence that this library now agrees with Slidev about is a
    // strictness that has gone, and the recording is the only thing that would have
    // said so.
    expect($differing)->toBe([]);
});

it('holds a recording for every deck, and a deck for every recording', function () use ($fixture, $divergences) {
    $decks = array_column($fixture['decks'], 'name');
    $recorded = array_keys($fixture['slides']);

    expect($recorded)->toBe($decks)
        ->and(array_values(array_diff(array_keys($divergences), $decks)))->toBe([])
        ->and(array_values(array_diff($decks, array_keys($divergences))))->not->toBe([]);
});