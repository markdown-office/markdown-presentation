<?php

declare(strict_types=1);

use MarkdownPresentation\Document\Deck;
use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Document\Slide;
use MarkdownPresentation\Exception\FencedFrontmatter;
use MarkdownPresentation\Exception\MalformedFrontmatter;

function split(string $markdown): array
{
    $deck = (new DeckParser())->parse($markdown);

    return array_map(
        static fn (Slide $slide): array => [
            'index' => $slide->index(),
            'raw' => $slide->raw(),
            'content' => $slide->content(),
            'frontmatter' => $slide->frontmatter(),
            'title' => $slide->title(),
            'level' => $slide->level(),
            'note' => $slide->note(),
            'start' => $slide->start(),
            'end' => $slide->end(),
        ],
        $deck->slides(),
    );
}

function contents(string $markdown): array
{
    return array_column(split($markdown), 'content');
}

/**
 * The two slides {@see TWO_SLIDES} is made of, kept as constants because half of these
 * assertions *are* the comparison against them.
 */
const ONE = '# One';
const TWO = '# Two';

it('splits a deck on the delimiter line', function () {
    expect(contents(TWO_SLIDES))->toBe([ONE, TWO]);
});

it('reads the deck frontmatter out of the first block, and keeps it on the slide', function () {
    $deck = (new DeckParser())->parse("---\ntitle: Deck\nauthor: Fabi\n---\n\n# One\n\n---\n\n# Two\n");

    // Slidev has no separate block for the deck's frontmatter: the first slide
    // carries it, and the content after it is the first slide's content. The deck's
    // own accessors read it from there.
    expect($deck->frontmatter())->toBe(['title' => 'Deck', 'author' => 'Fabi'])
        ->and($deck->count())->toBe(2)
        ->and($deck->slides()[0]->content())->toBe(ONE)
        ->and($deck->slides()[1]->frontmatter())->toBe([]);
});

it('does not make a slide of a frontmatter block with nothing after it', function () {
    // The delimiter and the block that closes it are one slide here, which is what
    // keeps a deck that opens with configuration from opening with an empty slide.
    expect(contents("---\ntitle: Deck\n---\n"))->toBe(['']);
});

it('reads a frontmatter block after the first slide too', function () {
    $deck = (new DeckParser())->parse("---\ntitle: Deck\n---\n\n# One\n\n---\nlayout: two-column\n---\n\n## Left\n");

    expect($deck->slides()[1]->frontmatter())->toBe(['layout' => 'two-column'])
        ->and($deck->slides()[1]->content())->toBe('## Left');
});

it('reads no frontmatter from a delimiter with a blank line after it', function () {
    expect(split(TWO_SLIDES)[1]['frontmatter'])->toBe([]);
});

it('reads a frontmatter block closed by a delimiter line, not by three dashes in a value', function () {
    // Slidev matches three dashes *anywhere*, so the block ended at the middle of the value:
    // `description` was `a` and `-b` opened the slide's body, with nothing to say so.
    $slide = split("---\ndescription: a---b\n---\n\n# One\n")[0];

    expect($slide['frontmatter'])->toBe(['description' => 'a---b'])
        ->and($slide['content'])->toBe(ONE);
});

it('reads a quoted value holding three dashes rather than refusing it as broken YAML', function () {
    expect(split("---\ndescription: \"before --- after\"\n---\n\n# One\n")[0]['frontmatter'])
        ->toBe(['description' => 'before --- after']);
});

it('reads a block closed by a delimiter indented under three spaces as frontmatter', function () {
    // The three spaces a closing code fence is allowed, and the reason the closer is not
    // anchored at column zero: this deck would lose its frontmatter entirely, and where a
    // slide delimiter follows one, the block would swallow what came after and fold it into
    // the title. Recorded as the `closing delimiter indented` deck in
    // tests/fixtures/slidev/, where Slidev's own parser agrees with this reading.
    $slide = split("---\ntitle: Deck\n  ---\n\n# One\n")[0];

    expect($slide['frontmatter'])->toBe(['title' => 'Deck'])
        ->and($slide['title'])->toBe('Deck');
});

it('does not fold the rest of the deck into a value when the closer is indented', function () {
    // The same deck with a slide delimiter after it. YAML folds a following line into the
    // value above it, so a closer found too late reads as `title: "Deck ---"` and swallows
    // the slide's own heading — a title nobody wrote, with nothing anywhere to say so.
    $slide = split("---\ntitle: Deck\n  ---\n\n# One\n\n---\n\n# Two\n")[0];

    expect($slide['frontmatter'])->toBe(['title' => 'Deck']);
});

it('treats a run of four dashes as a delimiter and not as an opener', function () {
    expect(contents("# One\n\n----\n\n# Two\n"))->toBe([ONE, TWO]);
});

it('reads trailing whitespace on a delimiter as nothing', function () {
    expect(contents("# One\n\n---   \n\n# Two\n"))->toBe([ONE, TWO]);
});

it('leaves a thematic break alone when it is not a line of dashes', function () {
    // `---` cannot be a thematic break inside a slide because it is a delimiter;
    // `***` is one and stays part of the slide.
    expect(contents("# One\n\n***\n\nMore\n"))->toBe(["# One\n\n***\n\nMore"]);
});

it('skips a fenced code block whole, so a delimiter inside one cannot split a slide', function () {
    $markdown = "# One\n\n```\n---\nnot a slide\n---\n```\n\n---\n\n# Two\n";

    expect(contents($markdown))->toBe(["# One\n\n```\n---\nnot a slide\n---\n```", TWO]);
});

it('lets a flush closer close an indented opener', function () {
    // CommonMark: the closing fence is indented by no more than the opening one, so a
    // flush closer closes a block indented by two or three spaces. Slidev matches the
    // close with `startsWith` against the raw line and so needs the indentation
    // repeated exactly, which splits the deck on a `---` that is plainly inside a code
    // block. Recorded as a divergence in tests/fixtures/slidev/divergences.json.
    expect(contents("# One\n\n  ```\n  ---\n  ```\n"))->toBe(["# One\n\n  ```\n  ---\n  ```"])
        ->and(contents("  ```\na\n---\nb\n```\n"))->toHaveCount(1);
});

it('requires the closing fence to be at least as long as the opening one', function () {
    expect(contents("# One\n\n````\n```\n---\n````\n"))->toBe(["# One\n\n````\n```\n---\n````"]);
});

it('runs a fence that is never closed to the end of the deck', function () {
    // CommonMark: an unclosed fence runs to the end of the document. Slidev looks for
    // the closing fence first and only then steps over the block, so a fence that is
    // never closed leaves every line after it to be read normally — and a `---` inside
    // one splits the deck, leaving the next slide opening with a literal delimiter. A
    // missing backtick is a typo, not a request for two slides.
    expect(contents("# One\n\n```\n---\nnever closed\n"))->toBe(["# One\n\n```\n---\nnever closed"]);
});

it('reads a tilde fence as a fence, and an unterminated one to the end', function () {
    // Tildes fence a code block in CommonMark. Slidev recognises backticks only, so a
    // tilde block is read as prose and its `---` splits the deck.
    expect(contents("~~~\n# A\n---\n~~~\n"))->toBe(["~~~\n# A\n---\n~~~"])
        ->and(contents("~~~\n# A\n---\n# B\n"))->toBe(["~~~\n# A\n---\n# B"]);
});

it('does not let a closing fence carry anything after it', function () {
    // The whole line is matched rather than its prefix, so the info string of an
    // opening fence cannot satisfy the closing test.
    expect(contents("# One\n\n```php\n---\n```\n"))->toBe(["# One\n\n```php\n---\n```"])
        ->and(contents("# One\n\n```\na\n``` trailing\n"))->toBe(["# One\n\n```\na\n``` trailing"]);
});

it('does not read a delimiter inside an HTML comment as a delimiter', function () {
    $markdown = "# One\n\n<!--\n---\n-->\n\nStill one slide\n";

    expect(contents($markdown))->toBe(["# One\n\n<!--\n---\n-->\n\nStill one slide"]);
});

it('carries an HTML comment across lines without watching the delimiters on them', function () {
    // The comment is closed and another opened on one line, which is the case a
    // whole-comment match gets wrong.
    expect(contents("<!-- a --><!--\nb\n-->\n\n# One\n"))->toBe(["<!-- a --><!--\nb\n-->\n\n# One"]);
});

it('reads a ::note block as the speaker note', function () {
    $deck = (new DeckParser())->parse("# One\n\n::note\nthe note\n::\n");

    expect($deck->slides()[0]->note())->toBe('the note')
        ->and($deck->slides()[0]->content())->toBe(ONE);
});

it('reads a note opener written in either case as the note it opens', function () {
    // The block parser refuses a `::Note` that nothing closes, in either case, so an opener
    // left case-sensitive here would leave a correct deck refused as an unclosed one: the
    // message would say a `::` closed nothing when it did.
    expect((new DeckParser())->parse("# One\n\n::Note\nthe note\n::\n")->slides()[0]->note())->toBe('the note')
        ->and((new DeckParser())->parse("# One\n\n::NOTE\nthe note\n::\n")->slides()[0]->note())->toBe('the note');
});

it('does not read a delimiter inside a note as a delimiter', function () {
    // The same rule the fence and the HTML comment have, and for the same reason: a line of
    // the notes pane is not a slide delimiter however much of a delimiter it looks like.
    // Asserted on `raw` rather than `content` because the note has already been taken off
    // the content by this point, and what is being checked is that the walk never split.
    expect(split("# One\n\n::note\n---\n::\n")[0])
        ->toMatchArray([
            'raw' => "# One\n\n::note\n---\n::\n",
            'content' => ONE,
            'note' => "---",
        ]);
});

it('carries a note across the fence inside it, so the fence does not end the split', function () {
    // The fence branch and the note are both state the walk keeps, and a fence written
    // inside a note is the one line that has to reach neither.
    expect(split("# One\n\n::note\n```\n---\n```\n::\n")[0]['raw'])
        ->toBe("# One\n\n::note\n```\n---\n```\n::\n");
});

it('reads a frontmatter block holding nothing but a comment as an empty block', function () {
    expect(split("---\n# nothing configured\n---\n\n# One\n")[0]['frontmatter'])->toBe([]);
});

it('reads no note from a closing :: with nothing opening it', function () {
    // A note needs an opener too: a deck with a stray `::` loses nothing rather than
    // losing everything in front of it. The renderer refuses the stray one.
    expect(split("# One\n\n::\n")[0])->toMatchArray(['note' => null, 'content' => "# One\n\n::"]);
});

it('reads no note from a ::note that nothing closes', function () {
    // Taken as a note it would swallow the rest of the deck; left in the content it is a
    // line of prose, and the renderer is what says it is neither.
    expect(split("# One\n\n::note\nnote\n")[0])
        ->toMatchArray(['note' => null, 'content' => "# One\n\n::note\nnote"]);
});

it('reads no note from a closing :: with nothing opening it, in the middle of a slide', function () {
    expect(split("# One\n\nBody\n\n::\n\nMore\n")[0]['note'])->toBeNull();
});

it('reads a note that mentions a step marker as a note', function () {
    // The note is a note because of its fences, not because of a word somewhere in it: a
    // presenter who writes "click through to the last one" in the notes pane has written a
    // note, and reading it for a marker would drop it.
    expect(split("# One\n\n::note\nclick ::click again to reveal the rest\n::\n")[0])
        ->toMatchArray([
            'note' => 'click ::click again to reveal the rest',
            'content' => ONE,
        ]);
});

it('reads a note that carries markup as the text of it', function () {
    // Markdown, so `**bold**` arrives at the notes pane with the asterisks still on it:
    // PowerPoint's notes pane takes a string, not a deck, and this is what the deck wrote.
    expect(split("# One\n\n::note\nSay **this**.\n::\n")[0])
        ->toMatchArray(['note' => 'Say **this**.']);
});

it('takes the title from the frontmatter, then from a name, then from the first heading', function () {
    expect(split("---\ntitle: Named\n---\n\n# Heading\n")[0]['title'])->toBe('Named')
        ->and(split("---\nname: Named\n---\n\n# Heading\n")[0]['title'])->toBe('Named')
        ->and(split("# Heading\n\nBody\n")[0]['title'])->toBe('Heading')
        ->and(split("No heading here\n")[0]['title'])->toBeNull();
});

it('takes a heading from outside a code block', function () {
    expect(split("```\n# Not a title\n```\n")[0]['title'])->toBeNull()
        ->and(split("```\n# Not a title\n```\n\n# Real\n")[0]['title'])->toBe('Real');
});

it('reads the heading level, and lets the frontmatter name one for a title that is not a heading', function () {
    expect(split('## Two\n')[0]['level'])->toBe(2)
        ->and(split("---\ntitle: Named\nlevel: 3\n---\n\nBody\n")[0]['level'])->toBe(3);
});

it('says where each slide sits in the source', function () {
    $slides = split(TWO_SLIDES);

    expect([$slides[0]['start'], $slides[0]['end']])->toBe([0, 2])
        ->and([$slides[1]['start'], $slides[1]['end']])->toBe([3, 6]);
});

it('reads a deck written with CRLF line endings the same way', function () {
    expect(contents("---\r\ntitle: Deck\r\n---\r\n\r\n# One\r\n\r\n---\r\n\r\n# Two\r\n"))->toBe([ONE, TWO]);
});

it('reads an empty deck as one empty slide', function () {
    expect(split(''))->toHaveCount(1);
});

it('reads the block after a delimiter as the slide it opened, delimiters included', function () {
    // The closing delimiter belongs to the block it closes. Making it a slide of its
    // own is how a deck that opens with a frontmatter block grows an empty slide in
    // front of it.
    $slides = split("# One\n\n---\nlayout: blank\n---\n\n# Two\n");

    expect($slides)->toHaveCount(2)
        ->and($slides[1]['raw'])->toBe("---\nlayout: blank\n---\n\n# Two\n")
        ->and($slides[1]['frontmatter'])->toBe(['layout' => 'blank']);
});

it('reads an unterminated frontmatter block as a slide with no frontmatter', function () {
    expect(contents("# One\n\n---\nlayout: blank\n"))->toBe([ONE, "---\nlayout: blank"]);
});

it('reads a frontmatter block that is not YAML as an error, naming the slide', function () {
    split("---\ntitle: \"unclosed\n---\n\n# One\n");
})->throws(MalformedFrontmatter::class, 'The frontmatter of slide 1 is not valid YAML');

it('refuses a frontmatter block that is not a mapping', function () {
    split("---\n- one\n- two\n---\n\n# One\n");
})->throws(MalformedFrontmatter::class, 'is not a mapping of keys to values');

it('refuses a title that is not a string, rather than rendering a number as one', function () {
    split("---\ntitle: 42\n---\n\n# One\n");
})->throws(MalformedFrontmatter::class, 'has a "title" that is not a string');

it('refuses a level that is not a positive integer', function () {
    split("---\nlevel: 0\n---\n\n# One\n");
})->throws(MalformedFrontmatter::class, 'has a "level" that is not a positive integer');

it('refuses a slide that opens with a fenced yaml block instead of reading it as frontmatter', function () {
    split("```yaml\ntitle: Deck\n---\n\n# One\n");
})->throws(FencedFrontmatter::class, 'opens with a fenced yaml block');

it('reads a slide whose content is a fenced block of another language', function () {
    expect(contents("```js\nconst a = 1\n```\n"))->toBe(["```js\nconst a = 1\n```"]);
});

it('reads a key out of a frontmatter without reaching into the array', function () {
    $deck = (new DeckParser())->parse("---\ntitle: Deck\nlayout: content\n---\n\n# One\n");

    expect($deck->frontmatterValue('layout'))->toBe('content')
        ->and($deck->frontmatterValue('theme', 'default'))->toBe('default')
        ->and($deck->slides()[0]->frontmatterValue('missing'))->toBeNull();
});

it('reads an empty deck as having no frontmatter at all', function () {
    $deck = new Deck('', []);

    expect($deck->frontmatter())->toBe([])
        ->and($deck->frontmatterValue('title', 'none'))->toBe('none')
        ->and($deck->count())->toBe(0)
        ->and($deck->raw())->toBe('');
});

it('hands back the Markdown it was given', function () {
    $markdown = TWO_SLIDES;

    expect((new DeckParser())->parse($markdown)->raw())->toBe($markdown);
});

it('describes the dialect it implements', function () {
    $dialect = DeckParser::dialect();

    // What the description claims about the delimiters, checked against a deck that
    // does what it says. A description that drifts from the parser is the one part
    // of this library a caller cannot check by running it.
    expect($dialect)->toContain('closes the slide')
        ->and($dialect)->toContain('`***` is one, and')
        ->and($dialect)->toContain('refused rather than read')
        ->and(contents("# One\n\n***\n\nMore\n"))->toHaveCount(1)
        ->and(contents(TWO_SLIDES))->toHaveCount(2);
});
