<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Render\Block\Block;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Render\BlockParser;
use MarkdownPresentation\Render\InlineParser;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\Run;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;

 /**
  * How a slide's text is read: the inline markup of a run, and the blocks a slide is made of.
  *
  * Both are read through the public classes rather than through a render, because a block
  * parser is useful on its own and a test that can only reach it through a `.pptx` cannot say
  * which of the two it is checking.
 */

/**
 * The blocks of a slide, in order.
 *
 * @return list<Block>
*/
function blocksOf(string $markdown): array
{
    return (new BlockParser())->parse($markdown, 1, Scratch::directory());
}

/**
 * The kinds of block a slide is made of, in order, so a test can read a shape list.
 *
 * @return list<string>
*/
function kindsOf(string $markdown): array
{
    return array_map(static fn (Block $block): string => $block::class, blocksOf($markdown));
}

it('reads a paragraph of plain text as one block', function () {
    expect(kindsOf("Just words.\n"))->toBe([Paragraph::class]);
});

it('reads consecutive lines as one paragraph', function () {
    $blocks = blocksOf("One\nTwo\n\nNext\n");

    expect($blocks)->toHaveCount(2)
        ->and($blocks[0])->toBeInstanceOf(Paragraph::class);
});

it('reads a heading and keeps how deep it was', function () {
    $blocks = blocksOf("### Three\n");

    expect($blocks[0])->toBeInstanceOf(Heading::class)
        ->and($blocks[0]->level)->toBe(3);
});

it('takes the closing hashes off a heading', function () {
    $blocks = blocksOf("## Two ##\n");

    expect($blocks[0])->toBeInstanceOf(Heading::class)
        ->and(implode('', array_map(static fn (Run $run): string => $run->text, $blocks[0]->runs)))
        ->toBe('Two');
});

it('leaves a heading that is seven hashes long alone, because it is a paragraph', function () {
    // Seven `#` is not a heading in Markdown, and reading it as one would turn a line of
    // the deck into a title nobody wrote.
    expect(kindsOf("####### Seven\n"))->toBe([Paragraph::class]);
});

it('reads a thematic break of asterisks as a rule', function () {
    expect(kindsOf("***\n"))->toBe([Rule::class]);
});

it('reads a thematic break of underscores as a rule', function () {
    expect(kindsOf("___\n"))->toBe([Rule::class]);
});

it('reads a thematic break of dashes as a rule', function () {
    // A `---` line never reaches a slide as content — the splitter takes it as a delimiter —
    // but a break written with more dashes than that does.
    expect(kindsOf("-----\n"))->toBe([Rule::class]);
});

it('ends a list at a blank line', function () {
    $blocks = blocksOf("- one\n\nNot part of it.\n");

    expect($blocks)->toHaveCount(2)
        ->and($blocks[1])->toBeInstanceOf(Paragraph::class);
});

it('ends a list at a thematic break', function () {
    expect(kindsOf("- one\n***\n"))->toBe([ListBlock::class, Rule::class]);
});

it('ends a list at a fence', function () {
    expect(kindsOf("- one\n```\ncode\n```\n"))->toBe([ListBlock::class, Code::class]);
});

it('ends a list at a line that starts no item', function () {
    // CommonMark ends a list at a paragraph line, so a line of text after a list is its own
    // block rather than a fourth item.
    expect(kindsOf("- one\nplain text\n"))->toBe([ListBlock::class, Paragraph::class]);
});

it('reads a list written with a plus as a list', function () {
    expect(kindsOf("+ one\n"))->toBe([ListBlock::class]);
});

it('reads a list written with an asterisk as a list', function () {
    expect(kindsOf("* one\n"))->toBe([ListBlock::class]);
});

it('reads an ordered list written with a bracket as ordered', function () {
    expect(blocksOf("1) one\n")[0])->toBeInstanceOf(ListBlock::class)
        ->and(blocksOf("1) one\n")[0]->ordered)->toBeTrue();
});

it('reads a nested item as a deeper item', function () {
    $items = blocksOf("- one\n  - nested\n")[0]->items;

    expect(array_column($items, 'level'))->toBe([1, 2]);
});

it('reads four spaces of indent as two levels', function () {
    $items = blocksOf("- one\n    - deep\n")[0]->items;

    expect(array_column($items, 'level'))->toBe([1, 3]);
});

it('reads a fenced block as code and keeps its lines', function () {
    $block = blocksOf("```\none\ntwo\n```\n")[0];

    expect($block)->toBeInstanceOf(Code::class)
        ->and($block->lines)->toBe(['one', 'two']);
});

it('reads an unclosed fence as code to the end of the slide', function () {
    $block = blocksOf("```\none\ntwo")[0];

    expect($block)->toBeInstanceOf(Code::class)
        ->and($block->lines)->toBe(['one', 'two']);
});

it('keeps a fence closed by a longer run of the same character', function () {
    expect(blocksOf("````\n```\n````\n")[0]->lines)->toBe(['```']);
});

it('does not close a fence with a tilde when it was opened with a backtick', function () {
    // CommonMark's rule, and the one Slidev gets wrong: a closing fence is the character the
    // opener used.
    expect(kindsOf("```\n~~~\n"))->toBe([Code::class]);
});

it('reads a quote and keeps what was after the marker', function () {
    $block = blocksOf("> quoted text\n")[0];

    expect($block)->toBeInstanceOf(Quote::class)
        ->and(implode('', array_map(static fn (Run $run): string => $run->text, $block->runs)))
        ->toBe('quoted text');
});

it('reads a table as a header row, an alignment row and body rows', function () {
    $block = blocksOf("| A | B |\n| --- | --- |\n| 1 | 2 |\n| 3 | 4 |\n")[0];

    expect($block)->toBeInstanceOf(Table::class)
        ->and($block->cells)->toEqual([
            [[new Run('A')], [new Run('B')]],
            [[new Run('1')], [new Run('2')]],
            [[new Run('3')], [new Run('4')]],
        ]);
});

it('reads a table row that stops short by leaving the cells it has', function () {
    // A row with fewer cells than the header is a row with holes, not a row of blanks, and a
    // renderer that invents cells would write words the author never did.
    $block = blocksOf("| A | B |\n| --- | --- |\n| 1 |\n")[0];

    expect($block->cells)->toEqual([
        [[new Run('A')], [new Run('B')]],
        [[new Run('1')]],
    ]);
});

it('reads a cell as the runs it is drawn from, markup and all', function () {
    // A cell is a paragraph's worth of text and takes the same inline markup one does. The
    // parser handed the writer trimmed strings, so `**Name**` reached the slide as six
    // characters and `[lk](https://example.com)` as twenty-six.
    $block = blocksOf("| A | B |\n| --- | --- |\n| **Name** | [lk](https://example.com) |\n")[0];

    expect($block->cells[1])->toEqual([
        [new Run('Name', bold: true)],
        [new Run('lk', link: 'https://example.com')],
    ]);
});

it('reads a table whose columns are centred, left and right', function () {
    // `:---` is left-aligned and `:---:` is centred; a reader that got that backwards would
    // misalign every table a model writes.
    expect(blocksOf("| A | B | C |\n| :---: | :--- | ---: |\n| 1 | 2 | 3 |\n")[0]->align)
        ->toBe(['center', 'left', 'right']);
});

it('reads a pipe that is not a table as a paragraph', function () {
    // A `|` with nothing that could be an alignment row under it is just a character.
    expect(kindsOf("a | b\n"))->toBe([Paragraph::class]);
});

it('reads an image that stands alone as a block', function () {
    $png = imageDirectory() . '/block.png';
    imagepng(imagecreatetruecolor(2, 2), $png);

    $block = blocksOf(sprintf("![alt text](%s)\n", $png))[0];

    expect($block)->toBeInstanceOf(Image::class)
        ->and($block->alt)->toBe('alt text')
        ->and($block->written)->toBe($png);
});

it('reads an image inside a paragraph as text', function () {
    // A lone image is a block; an image among words is a sentence, and turning it into a
    // shape would drop the sentence around it.
    $png = imageDirectory() . '/inline.png';
    imagepng(imagecreatetruecolor(2, 2), $png);

    expect(kindsOf(sprintf("see ![alt](%s) here\n", $png)))->toBe([Paragraph::class]);
});

it('refuses an HTML comment that is neither a step nor a note', function () {
    // Drawn as a paragraph it became a slide with the literal text `<!-- v-if false -->` on
    // it, which is a deck that renders the mistake rather than one that reports it.
    expect(fn () => blocksOf("# One\n\n<!-- v-if false -->\n\nSecond.\n"))
        ->toThrow(
            UnknownDirective::class,
            'Slide 1 has the HTML comment <!-- v-if false -->, which is neither a step nor a note. '
            . 'A step is "<!-- v-click -->" on a line of its own, and a note is a comment after '
            . 'the last line of a slide.',
        );
});

it('refuses a comment in the middle of a slide', function () {
    expect(fn () => blocksOf("# One\n\n<!-- mid -->\n\nSecond.\n"))
        ->toThrow(UnknownDirective::class, 'has the HTML comment <!-- mid -->');
});

it('refuses a marker that shares its line with a word', function () {
    // A step is honoured on a line of its own, so this is not a step anybody can say where it
    // goes. Read as text it was a slide with `<!-- v-click -->` printed on it.
    expect(fn () => blocksOf("# One\n\nFirst <!-- v-click -->\n\nSecond.\n"))
        ->toThrow(UnknownDirective::class, 'has the HTML comment <!-- v-click -->');
});

it('quotes a comment written across several lines as one line', function () {
    expect(fn () => blocksOf("# One\n\n<!--\nsomething\n-->\n\nSecond.\n"))
        ->toThrow(UnknownDirective::class, 'has the HTML comment <!-- something -->');
});

it('refuses a comment that is never closed, naming what it found', function () {
    expect(fn () => blocksOf("# One\n\n<!-- never closed\n\nSecond.\n"))
        ->toThrow(UnknownDirective::class, 'has the HTML comment <!-- never closed');
});

it('refuses a Vue element, closing one included', function () {
    expect(fn () => blocksOf("# One\n\n<v-click>Shown</v-click>\n"))
        ->toThrow(
            UnknownDirective::class,
            'Slide 1 has the element "<v-click>", which is not something a slide draws. A step is '
            . 'the comment "<!-- v-click -->" on a line of its own, and there is no other element.',
        );
});

it('refuses an element carrying a v-* directive, which is written like ordinary HTML', function () {
    expect(fn () => blocksOf("# One\n\n<div v-if=\"clicks\">Shown</div>\n"))
        ->toThrow(UnknownDirective::class, 'has the element "<div v-if="clicks">"');
});

it('refuses a ::: container, naming the alternatives', function () {
    expect(fn () => blocksOf("# One\n\n::: note\nSomething.\n:::\n"))
        ->toThrow(
            UnknownDirective::class,
            'Slide 1 opens the container "::: note", which is not something a slide draws. Divide a '
            . 'deck with a "---" line, write a section as a heading, and write a note as a quote: "> ".',
        );
});

it('refuses a directive in a table row as loudly as one on a line of its own', function () {
    // The whole slide is judged before it is parsed into blocks, so a directive that a block
    // parser would have swallowed as a cell is refused rather than drawn as a cell.
    expect(fn () => blocksOf("# One\n\n| A | B |\n| --- | --- |\n| <!-- v-if --> | x |\n"))
        ->toThrow(UnknownDirective::class, 'has the HTML comment <!-- v-if -->');
});

it('leaves HTML that is not a directive as the text it is', function () {
    // `<b>` is not in Slidev's vocabulary, so a deck that writes HTML is a deck saying
    // something about HTML — which is text, and text is what a slide draws.
    expect(kindsOf("A <b>bold</b> word.\n"))->toBe([Paragraph::class]);
});

it('leaves a comment inside a code block to the code block', function () {
    // The ordinary way to show a comment rather than run it, which the failure message names.
    $blocks = blocksOf("# One\n\n```html\n<!-- v-if -->\n```\n");

    expect($blocks[1])->toBeInstanceOf(Code::class)
        ->and($blocks[1]->lines)->toBe(['<!-- v-if -->']);
});

it('leaves a directive inside an unclosed code block to the code block too', function () {
    expect(blocksOf("```\n<!-- v-if -->\n")[0])->toBeInstanceOf(Code::class);
});

it('reads plain text as one run with nothing emphasised', function () {
    expect((new InlineParser())->parse('plain'))->toEqual([new Run('plain')]);
});

it('reads bold, italic and code as separate runs', function () {
    expect((new InlineParser())->parse('**b** *i* `c`'))->toEqual([
        new Run('b', bold: true),
        new Run(' '),
        new Run('i', italic: true),
        new Run(' '),
        new Run('c', code: true),
    ]);
});

it('reads a run of bold italic as one run carrying both', function () {
    // The italic pattern matched the inner `**` first, so `***bold italic***` came out as
    // `"*"`, `"bolditalic"` and `"* here"` — three runs with the delimiters printed on the
    // slide and the space between the words gone.
    expect((new InlineParser())->parse('***bold italic***'))->toEqual([
        new Run('bold italic', bold: true, italic: true),
    ]);
});

it('keeps the words around a run of bold italic', function () {
    expect(array_map(
        static fn (Run $run): string => $run->text,
        (new InlineParser())->parse('a ***b*** c'),
    ))->toBe(['a ', 'b', ' c']);
});

it('reads underscores as bold and italic the way asterisks are', function () {
    expect((new InlineParser())->parse('__bold__ and _italic_'))->toEqual([
        new Run('bold', bold: true),
        new Run(' and '),
        new Run('italic', italic: true),
    ]);
});

it('reads two tildes as strikethrough', function () {
    expect((new InlineParser())->parse('~~gone~~'))->toEqual([new Run('gone', strike: true)]);
});

it('leaves an underscore inside a word as the character it is', function () {
    // CommonMark's rule, and the one that keeps a variable name a variable name: `_*_`
    // between two word characters is not an emphasis delimiter.
    expect((new InlineParser())->parse('a `snake_case` b'))->toEqual([
        new Run('a '),
        new Run('snake_case', code: true),
        new Run(' b'),
    ]);
});

it('does not read the underscores inside a code span as emphasis', function () {
    expect((new InlineParser())->parse('`_not italic_`'))->toEqual([
        new Run('_not italic_', code: true),
    ]);
});

it('leaves an unclosed tilde pair as the characters they are', function () {
    // The same rule as an unclosed asterisk: a deck that renders is worth more here than
    // one that refuses a paragraph because of a typo.
    expect((new InlineParser())->parse('~~unclosed'))->toEqual([new Run('~~unclosed')]);
});

it('reads a link as its text with a target', function () {
    expect((new InlineParser())->parse('[text](https://example.com)'))->toEqual([
        new Run('text', link: 'https://example.com'),
    ]);
});

it('keeps runs in the order they were written rather than the order of the patterns', function () {
    // A paragraph that says "**bold** and `code`" comes out in that order or the words move,
    // and which pattern is looked for first must not decide it.
    expect(array_map(
        static fn (Run $run): string => $run->text,
        (new InlineParser())->parse('**bold** and `code`'),
    ))->toBe(['bold', ' and ', 'code']);
});

it('keeps a code span before a bold that follows it in the same order', function () {
    expect(array_map(
        static fn (Run $run): string => $run->text,
        (new InlineParser())->parse('`code` then **bold**'),
    ))->toBe(['code', ' then ', 'bold']);
});

it('does not read the asterisks inside a code span as emphasis', function () {
    // Code first in the pattern list, so `*` inside a code span is the character it is.
    expect((new InlineParser())->parse('`**not bold**`'))->toEqual([new Run('**not bold**', code: true)]);
});

it('leaves an unclosed asterisk as the character it is', function () {
    expect((new InlineParser())->parse('2 * 3'))->toEqual([new Run('2 * 3')]);
});

it('leaves an unclosed bold marker as the characters they are', function () {
    expect((new InlineParser())->parse('**unclosed'))->toEqual([new Run('**unclosed')]);
});

it('leaves an unclosed code span as the characters they are', function () {
    expect((new InlineParser())->parse('`unclosed'))->toEqual([new Run('`unclosed')]);
});

it('reads an italic between two words', function () {
    expect((new InlineParser())->parse('a *b* c'))->toEqual([new Run('a '), new Run('b', italic: true), new Run(' c')]);
});

it('reads a link with no text as a run with nothing in it', function () {
    // A writer who did that wrote nothing to show; the target is still kept so the link is
    // not silently discarded.
    expect((new InlineParser())->parse('[](https://example.com)'))->toEqual([
        new Run('', link: 'https://example.com'),
    ]);
});

it('reads a link with a title and keeps only its target', function () {
    // The title is for a reader hovering the link; PowerPoint has a tooltip for it and a
    // `.pptx` hyperlink does not carry one, so the target is what the run gets.
    expect((new InlineParser())->parse('[text](https://example.com "A title")'))->toEqual([
        new Run('text', link: 'https://example.com'),
    ]);
});

it('reads an empty span as nothing', function () {
    expect((new InlineParser())->parse('**') )->toEqual([new Run('**')]);
});

it('refuses a deck description that is not a string, and names the key', function () {
    // The splitter refuses a `title` of the wrong type because it is a title; `description`
    // is not one and reaches the renderer as whatever YAML made of it.
    $path = Scratch::file('deck.pptx', '');

    expect(fn () => (new PresentationRenderer())->render(
        (new DeckParser())->parse("---\ndescription:\n  key: value\n---\n# One\n"),
        $path,
    ))->toThrow(MalformedFrontmatter::class, 'has a "description" that is not a string');
});

it('writes the cells a ragged table has and stops where the row stops', function () {
    // A body row with more cells than the header has no column to put them in; the
    // alternative is inventing a column the deck never asked for.
    $path = Scratch::file('deck.pptx', '');
    (new PresentationRenderer())->render(
        (new DeckParser())->parse("| A | B |\n| --- | --- |\n| 1 | 2 | 3 |\n"),
        $path,
    );

    expect(Pptx::open($path)->texts(1))->toBe(['A', 'B', '1', '2']);
});