<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use League\CommonMark\Node\Block\Paragraph as LeagueParagraph;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Exception\UnsupportedLink;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\Block\Block;
use MarkdownPresentation\Render\Block\Code;
use MarkdownPresentation\Render\Block\Heading;
use MarkdownPresentation\Render\Block\Image;
use MarkdownPresentation\Render\Block\ListBlock;
use MarkdownPresentation\Render\Block\Paragraph;

/**
 * The target of the links the tests below parse, and the scheme {@see UnsupportedLink}
 * accepts.
 *
 * A constant rather than four spellings because the point of those assertions is that the
 * *same* target becomes a run's `link`: a reader comparing two literals has to check the
 * strings really are equal, which is the comparison the test was supposed to be making.
 */
const EXAMPLE_LINK = 'https://example.com';
use MarkdownPresentation\Render\Block\Quote;
use MarkdownPresentation\Render\Block\Rule;
use MarkdownPresentation\Render\Block\Table;
use MarkdownPresentation\Render\CommonMark\BlockTranslator;
use MarkdownPresentation\Render\CommonMark\InlineTranslator;
use MarkdownPresentation\Render\CommonMark\SlideEnvironment;
use MarkdownPresentation\Render\CommonMark\SlideState;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\Run;
use MarkdownPresentation\Render\SlideBlocks;
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
 * A slide's content as the parser read it: its blocks and the column each was written for.
 */
function slideContentOf(string $markdown): SlideBlocks
{
    return (new BlockTranslator())->parse($markdown, 1, Scratch::directory(), deckScope(Scratch::directory()));
}

/**
 * The blocks of a slide, in order.
 *
 * @return list<Block>
*/
function blocksOf(string $markdown): array
{
    return slideContentOf($markdown)->blocks();
}

/**
 * The runs one piece of inline Markdown makes, read through the translator the renderer uses.
 *
 * @return list<Run>
 */
function inlineRuns(string $markdown): array
{
    return (new InlineTranslator(SlideEnvironment::build()))->runsIn($markdown);
}

/**
 * What a refusal says, or `DRAWN` where the slide was read rather than refused.
 */
function refusalOf(callable $read): string
{
    try {
        $read();
    } catch (UnknownDirective $refusal) {
        return $refusal->getMessage();
    }

    return 'DRAWN';
}

/**
 * The column each block of a slide was written for, in the same order as the blocks.
 *
 * @return list<int>
*/
function writtenColumnsOf(string $markdown): array
{
    return slideContentOf($markdown)->columns();
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

it('takes a line of text after a list item as part of that item', function () {
    // CommonMark's lazy continuation: a paragraph line with no blank line above it joins the
    // item it follows rather than ending the list. The hand-written walk ended the list, and
    // the comment it carried said CommonMark did the same, which it does not.
    expect(kindsOf("- one\nplain text\n"))->toBe([ListBlock::class]);
});

it('reads a list written with a plus as a list', function () {
    expect(kindsOf("+ one\n"))->toBe([ListBlock::class]);
});

it('reads a list written with an asterisk as a list', function () {
    expect(kindsOf("* one\n"))->toBe([ListBlock::class]);
});

it('reads an ordered list written with a bracket as ordered', function () {
    expect(blocksOf("1) one\n")[0])->toBeInstanceOf(ListBlock::class)
        ->and(blocksOf("1) one\n")[0]->items[0]['ordered'])->toBeTrue();
});

it('ends a list where the marker changes', function () {
    // A change of marker starts a new list in CommonMark, so `- a`, `1. b`, `- c` is three
    // lists. The hand-written walk made one list of three items and carried an `ordered` flag
    // per item to draw it, which is a reading CommonMark does not have.
    expect(kindsOf("- a\n1. b\n- c\n"))->toBe([ListBlock::class, ListBlock::class, ListBlock::class]);
});

it('reads a nested item as a deeper item', function () {
    $items = blocksOf("- one\n  - nested\n")[0]->items;

    expect(array_column($items, 'level'))->toBe([1, 2]);
});

it('reads four spaces of indent as one level deeper', function () {
    // Four spaces is four spaces: `intdiv(4, 2)` made it two levels, and a sub-list written
    // with a tab's width of indent was drawn two levels down with nothing at the first.
    $items = blocksOf("- one\n    - deep\n")[0]->items;

    expect(array_column($items, 'level'))->toBe([1, 2]);
});

it('closes the levels an item comes back out of', function () {
    $items = blocksOf("- one\n  - nested\n    - deeper\n- back\n  - nested again\n")[0]->items;

    expect(array_column($items, 'level'))->toBe([1, 2, 3, 1, 2]);
});

it('keeps the number the first ordered item was written with', function () {
    // The number is what the writer's `startAt` is, and it was captured into a variable
    // nobody read: `3. three` came out as `1.` A numbering that does not begin at one carries
    // its number on every item rather than on the first alone, because the same `startAt` is
    // written on each of its paragraphs and a reader that restarts at each one would count
    // `3. three` and `4. four` as two threes.
    expect(array_column(blocksOf("3. three\n4. four\n")[0]->items, 'start'))->toBe([3, 4]);

    // One that does begin at one carries it on the first item alone, because nothing is
    // written for it and every reader counts on from one.
    expect(array_column(blocksOf("1. one\n2. two\n")[0]->items, 'start'))->toBe([1, null]);
});

it('starts a nested ordered list again at its own level', function () {
    // Three spaces, because `1. ` is three columns wide and a sub-item at two is a sibling.
    // Only the first item carries a number: each level begins its own numbering at one, so a
    // number on the sub-item would restate what the level already knows and on the way back
    // `two` would restart a numbering that had not ended.
    expect(array_column(blocksOf("1. one\n   1. sub\n2. two\n")[0]->items, 'start'))->toBe([1, null, null]);
});

it('keeps no number for a bulleted item', function () {
    expect(array_column(blocksOf("- one\n- two\n")[0]->items, 'start'))->toBe([null, null]);
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

it('reads a quote written across two lines as one block', function () {
    // Every `>` line made a shape of its own: one sentence of two lines came out as two
    // blocks, with two indents, two gaps and two line heights between them.
    $blocks = blocksOf("> line one\n> line two\n");

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0])->toBeInstanceOf(Quote::class)
        ->and(InlineTranslator::text($blocks[0]->runs))->toBe("line one\nline two");
});

it('reads a blank line as what ends a quote', function () {
    $blocks = blocksOf("> one\n\n> two\n");

    expect($blocks)->toHaveCount(2)
        ->and(array_map(static fn (Block $block): string => InlineTranslator::text($block->runs), $blocks))
        ->toBe(['one', 'two']);
});

it('keeps a paragraph written after a quote where it was written', function () {
    $blocks = blocksOf("> one\ntext\n");

    expect(array_map(static fn (Block $block): string => $block::class, $blocks))
        ->toBe([Quote::class, Paragraph::class]);
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

it('pads a short row so every row is as wide as the header', function () {
    // The cell it adds holds no runs, so nothing is written that the author did not write;
    // what it buys is a row whose columns line up with the header rather than one that stops
    // short of it. The hand-written walk left the row at its own width.
    $block = blocksOf("| A | B |\n| --- | --- |\n| 1 |\n")[0];

    expect($block->cells)->toEqual([
        [[new Run('A')], [new Run('B')]],
        [[new Run('1')], []],
    ]);
});

it('reads a cell as the runs it is drawn from, markup and all', function () {
    // A cell is a paragraph's worth of text and takes the same inline markup one does. The
    // parser handed the writer trimmed strings, so `**Name**` reached the slide as six
    // characters and `[lk](https://example.com)` as twenty-six.
    $block = blocksOf("| A | B |\n| --- | --- |\n| **Name** | [lk](https://example.com) |\n")[0];

    expect($block->cells[1])->toEqual([
        [new Run('Name', bold: true)],
        [new Run('lk', link: EXAMPLE_LINK)],
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

it('finds a picture whose name has a space in it', function () {
    // CommonMark has no bare space in a destination, so `a picture.png` on a line of its own is
    // a paragraph and `a%20picture.png` is the picture. Looking for the encoded spelling on
    // disk is looking for a file nobody has, which is what the deck would have got.
    $png = imageDirectory() . '/a picture.png';
    imagepng(imagecreatetruecolor(2, 2), $png);

    // The whole path, with only the space encoded: league reads an absolute path as one and
    // would treat an encoded separator as part of a file name.
    $written = str_replace(' ', '%20', $png);

    expect(blocksOf(sprintf("![alt](%s)\n", $written))[0])->toBeInstanceOf(Image::class)
        ->and(blocksOf(sprintf("![alt](%s)\n", $written))[0]->written)->toBe($png)
        ->and(kindsOf(sprintf("![alt](%s)\n", basename($png))))->toBe([Paragraph::class]);
});

it('reads an image inside a paragraph as text', function () {
    // A lone image is a block; an image among words is a sentence, and turning it into a
    // shape would drop the sentence around it.
    $png = imageDirectory() . '/inline.png';
    imagepng(imagecreatetruecolor(2, 2), $png);

    expect(kindsOf(sprintf("see ![alt](%s) here\n", $png)))->toBe([Paragraph::class]);
});

it('records which column each block was written for', function () {
    // The renderer cannot work this out from the blocks: two paragraphs are the same
    // paragraph in either column, so what says which one a deck meant is what it wrote.
    expect(writtenColumnsOf("Left.\n\n::column\n\nRight.\n"))->toBe([0, 1])
        ->and(writtenColumnsOf("Left.\n\nRight.\n"))->toBe([0, 0]);
});

it('closes an open paragraph before a column marker', function () {
    // Without the flush the two lines are one paragraph written either side of the marker,
    // and a block cannot belong to two columns.
    expect(kindsOf("Left.\n::column\nRight.\n"))->toBe([Paragraph::class, Paragraph::class])
        ->and(writtenColumnsOf("Left.\n::column\nRight.\n"))->toBe([0, 1]);
});

it('closes an open quote before a column marker', function () {
    expect(kindsOf("> Quoted.\n::column\nRight.\n"))->toBe([Quote::class, Paragraph::class])
        ->and(writtenColumnsOf("> Quoted.\n::column\nRight.\n"))->toBe([0, 1]);
});

it('moves no step for a column marker', function () {
    // The whole trap this one guards: `jump()` is the only thing that may move a step, and a
    // marker that also moved one would put the right column on step two and shift every click
    // on the slide by one.
    $content = slideContentOf("Left.\n\n::column\n\nRight.\n\n::click\n\nThird.\n");
    $steps = array_map(static fn (Block $block): int => $block->step(), $content->blocks());

    expect($steps)->toBe([1, 1, 2])
        ->and($content->columns())->toBe([0, 1, 1]);
});

it('leaves a ::click in the column it was written in', function () {
    expect(writtenColumnsOf("Left.\n\n::click\n\nRight.\n\n::column\n\nThird.\n"))->toBe([0, 0, 1]);
});

it('keeps a region in the column it was written in', function () {
    // Neither of these regions writes a marker of its own: three colons open a step and not a
    // column, so what is inside one starts where the opener was rather than at a walk of its
    // own. The marker below each is the only thing that moves the column.
    expect(writtenColumnsOf(":::click\n\nRight.\n\n:::\n\n::column\n\nThird.\n"))->toBe([0, 1])
        ->and(writtenColumnsOf("Left.\n\n::column\n\n:::click\n\nRight.\n:::\n"))->toBe([0, 1]);
});

it('moves the text after a ::column written inside a region into the next column', function () {
    // A column is where a block is drawn and a region is when it appears, so a region governs
    // what a click reveals and not where the text sits. `::column` on a line of its own moves
    // everything below it — including below the closer — which is the one reading of the
    // marker there is and the only one a deck has to know.
    expect(writtenColumnsOf("Left.\n\n:::click\n\nA.\n\n::column\n\nB.\n\n:::\n\nC.\n"))->toBe([0, 0, 1, 1])
        ->and(writtenColumnsOf(":::click\n\nA.\n\n::column\n\nB.\n\n:::\n"))->toBe([0, 1]);
});

it('leaves the step of a region where the region opened, though its ::column has moved', function () {
    // The other half of that asymmetry, and the half that needs pinning: the walk around the
    // region carries on from the step the region opened at, and not from the last step the
    // region itself reached. `D.` is on step two with `A.`, even though the region clicked
    // twice before it closed — a step inside a region is that region's own, and the chain the
    // slide clicks through is one where the region was already on it.
    $content = slideContentOf(":::click\n\nA.\n\n::column\n\nB.\n\n::click\n\nC.\n\n:::\n\nD.\n");
    $steps = array_map(static fn (Block $block): int => $block->step(), $content->blocks());

    expect($steps)->toBe([2, 2, 3, 2])
        ->and($content->columns())->toBe([0, 1, 1, 1]);
});

it('counts the column markers a slide was written with', function () {
    // What the renderer measures a layout against. A marker with nothing after it leaves no
    // block to count, and it is still the only thing on the slide that says the deck asked
    // for a second column.
    expect(slideContentOf("Left.\n\n::column\n\nRight.\n")->columnMarkers())->toBe(1)
        ->and(slideContentOf("Left.\n\n::column\n")->columnMarkers())->toBe(1)
        ->and(slideContentOf("Left.\n\nRight.\n")->columnMarkers())->toBe(0)
        ->and(slideContentOf("::column\n")->columnMarkers())->toBe(1);
});

it('counts a column marker before a region into the region as well', function () {
    // A region is read by its own walk, at the column and the count the one around it had
    // reached. A walk that began its own count at nothing would report fewer markers than
    // the column a block in it is written for — which is the invariant below, and the
    // reason the count is handed in rather than added up on the way out.
    $content = slideContentOf("Left.\n\n::column\n\n:::click\n\nRight.\n:::\n\nThird.\n");

    expect($content->columns())->toBe([0, 1, 1])
        ->and($content->columnMarkers())->toBe(1);
});

it('refuses a column marker with an argument, naming what would have worked', function () {
    // The marker means the next column and cannot be named as one, so there is no argument
    // for it to take. Drawn as a paragraph it put `::column=2` on the slide, which is the
    // marker read back to the author as text.
    expect(fn () => slideContentOf("Left.\n\n::column=2\n\nRight.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "::column=2", which is not a column marker: the marker '
            . 'takes no argument. Write "::column" on a line of its own to move the blocks after it into the '
            . 'next column.')
        ->and(fn () => slideContentOf("Left.\n\n::column=\n\nRight.\n"))
        ->toThrow(UnknownMarker::class, 'Slide 1 has a "::column=", which is not a column marker');
});

it('refuses a slide whose blocks and columns are not the same length', function () {
    // The invariant the object exists to hold. It is a caller that built the pair wrongly
    // rather than a deck that cannot be drawn, so it is not under `InvalidInput`: a deck
    // author told to go and rename something would go and rename something.
    expect(fn () => new SlideBlocks([new Paragraph([], 1)], [], 0))
        ->toThrow(Exception::class, 'A slide carries 1 blocks and 0 columns');
});

it('refuses a marker count below the column a block was written for', function () {
    // The other half of the same invariant, and one no deck reaches: the walk moves the column
    // and the count together, and hands a region the count it was given rather than counting
    // from nothing, so no block is ever in a column the count has not reached. What the guard
    // is left for is a caller that built the value itself.
    expect(fn () => new SlideBlocks([new Paragraph([], 1)], [1], 0))
        ->toThrow(Exception::class, 'A slide carries 0 "::column" markers and a block written in column 1; '
            . 'a marker moves what follows it into the next column, so there cannot be fewer markers than '
            . 'the column a block is in.');
});

it('refuses an HTML comment that is neither a step nor a note', function () {
    // Drawn as a paragraph it became a slide with the literal text `<!-- v-if false -->` on
    // it, which is a deck that renders the mistake rather than one that reports it.
    expect(fn () => blocksOf("# One\n\n<!-- v-if false -->\n\nSecond.\n"))
        ->toThrow(
            UnknownDirective::class,
            'Slide 1 has the HTML comment <!-- v-if false -->, which is not something a slide carries. '
            . 'A step is "::click" on a line of its own and a note is "::note", its text, and a '
            . 'closing "::".',
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
            . '"::click" on a line of its own, and there is no other element.',
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
            'Slide 1 opens the container "::: note", which is not something a slide draws. Three colons '
            . 'open a step region as ":::click" and close it as ":::"; divide a deck with a "---" line, '
            . 'write a section as a heading, and write a note as a quote: "> ".',
        );
});

it('reads a marker in either case as the marker it names', function () {
    // A deck written by a model capitalises a directive as readily as it writes it in lower
    // case. All three were drawn on the slide as literal text, which is the marker read back
    // to an author who thought they had used it — the one outcome a marker must never have.
    $clicked = slideContentOf("Left.\n\n::Click\n\nRight.\n");
    $steps = array_map(static fn (Block $block): int => $block->step(), $clicked->blocks());

    expect($steps)->toBe([1, 2])
        ->and(writtenColumnsOf("Left.\n\n::Column\n\nRight.\n"))->toBe([0, 1])
        ->and(writtenColumnsOf("Left.\n\n::COLUMN\n\nRight.\n"))->toBe([0, 1])
        ->and(kindsOf(":::Click\n\nA.\n\n:::\n"))->toBe([Paragraph::class])
        ->and(fn () => blocksOf("Left.\n\n::Note\n\nRight.\n"))
        ->toThrow(UnknownDirective::class, 'Slide 1 opens a "::note" that nothing closes');
});

it('refuses a ::: container whose word is not a region opener, whatever its case', function () {
    // The refusal's own lookahead is what leaves the three-colon grammar alone, and it is the
    // one place case folding has to be seen to: a container check left behind the `i` flag
    // would read `:::Click` as a container to refuse rather than as the region it is.
    expect(fn () => blocksOf("# One\n\n:::Column\nSomething.\n:::\n"))
        ->toThrow(UnknownDirective::class, 'Slide 1 opens the container ":::Column"');
});

it('refuses a leaf directive under a name this format does not draw', function () {
    // Painted onto the slide as the words the deck wrote, this is the failure the comment, the
    // element and the container refusals exist to prevent — a deck that renders, showing the
    // mistake as prose — reached by the one form in the grammar that none of them looks at.
    expect(fn () => blocksOf("# One\n\n::bogus\n"))
        ->toThrow(
            UnknownDirective::class,
            'Slide 1 opens the directive "::bogus", which is not something a slide draws. Two colons '
            . 'mark a step as "::click" or a column as "::column"; three open a step region as '
            . '":::click" and close it as ":::"; write a note as "::note", its text, and a closing "::".',
        );
});

it('refuses a leaf directive on its name rather than on what it carries', function () {
    // The label and the attributes are what the author was writing; the name is what says the
    // slide cannot act on it, so each of these is refused for the same reason and quoted whole.
    foreach (['::bogus{x=1}', '::bogus[hello]', '::BOGUS', ':: bogus'] as $written) {
        expect(fn () => blocksOf("# One\n\n" . $written . "\n"))
            ->toThrow(UnknownDirective::class, sprintf('opens the directive "%s"', $written));
    }
});

it('leaves a directive this format draws alone, whatever case it is written in', function () {
    // The other half of the refusal: a name the library acts on is not a mistake, and one
    // capitalised by a model is still the name it was looking for.
    foreach (['::click', '::Click', '::column', '::COLUMN'] as $written) {
        expect(refusalOf(fn () => blocksOf("# One\n\nLeft.\n\n" . $written . "\n\nRight.\n")))->toBe('DRAWN');
    }
});

it('reads a leaf directive a region opens as one the slide cannot draw', function () {
    // A `:::` region is raw lines in the content, so a directive inside one is on the same
    // footing as one on the slide: refused, and refused by name.
    expect(fn () => blocksOf("# One\n\n:::click\n::bogus\n:::\n"))
        ->toThrow(UnknownDirective::class, 'Slide 1 opens the directive "::bogus"');
});

it('leaves a directive written inside a quote or a list item as the text it is', function () {
    // A quote and a list item write their marker in front of every line, so nothing in one is
    // a line the slide reads. `::click` is already drawn as text there, and refusing an
    // unknown name in that position and not a known one would make nesting an error on its own.
    expect(refusalOf(fn () => blocksOf("# One\n\n> ::bogus\n")))->toBe('DRAWN')
        ->and(refusalOf(fn () => blocksOf("# One\n\n- ::bogus\n")))->toBe('DRAWN');
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
    expect(inlineRuns('plain'))->toEqual([new Run('plain')]);
});

it('reads bold, italic and code as separate runs', function () {
    expect(inlineRuns('**b** *i* `c`'))->toEqual([
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
    expect(inlineRuns('***bold italic***'))->toEqual([
        new Run('bold italic', bold: true, italic: true),
    ]);
});

it('keeps the words around a run of bold italic', function () {
    expect(array_map(
        static fn (Run $run): string => $run->text,
        inlineRuns('a ***b*** c'),
    ))->toBe(['a ', 'b', ' c']);
});

it('reads underscores as bold and italic the way asterisks are', function () {
    expect(inlineRuns('__bold__ and _italic_'))->toEqual([
        new Run('bold', bold: true),
        new Run(' and '),
        new Run('italic', italic: true),
    ]);
});

it('reads two tildes as strikethrough', function () {
    expect(inlineRuns('~~gone~~'))->toEqual([new Run('gone', strike: true)]);
});

it('leaves an underscore inside a word as the character it is', function () {
    // CommonMark's rule, and the one that keeps a variable name a variable name: `_*_`
    // between two word characters is not an emphasis delimiter.
    expect(inlineRuns('a `snake_case` b'))->toEqual([
        new Run('a '),
        new Run('snake_case', code: true),
        new Run(' b'),
    ]);
});

it('does not read the underscores inside a code span as emphasis', function () {
    expect(inlineRuns('`_not italic_`'))->toEqual([
        new Run('_not italic_', code: true),
    ]);
});

it('leaves an unclosed tilde pair as the characters they are', function () {
    // The same rule as an unclosed asterisk: a deck that renders is worth more here than
    // one that refuses a paragraph because of a typo.
    expect(inlineRuns('~~unclosed'))->toEqual([new Run('~~unclosed')]);
});

it('reads a link as its text with a target', function () {
    expect(inlineRuns('[text](https://example.com)'))->toEqual([
        new Run('text', link: EXAMPLE_LINK),
    ]);
});

it('refuses a link target a reader would act on rather than open', function () {
    // A hyperlink in a `.pptx` is followed by the person who opens it, so what the target
    // means to their machine is the question. `javascript:` is a script in every reader that
    // renders the deck as a document and in every pipeline that converts one to HTML, and
    // `data:` is the same thing with the script inlined. Refused where it is written rather
    // than carried into a file somebody opens.
    // Asserted on the refusal rather than on the exact spelling, because league percent-encodes
    // a target on the way in and the message quotes what it was handed: `data:text/html,<b>x`
    // arrives as `%3Cb%3Ex`. What matters is that the target is refused and named.
    foreach (['javascript:alert', 'vbscript:msgbox', 'data:text/html,<b>x', 'phar://payload.phar/x', 'file:///etc/passwd'] as $target) {
        expect(fn () => inlineRuns(sprintf('[click](%s)', $target)))
            ->toThrow(UnsupportedLink::class, 'is not a URL a deck may carry');
    }

    // The closing parenthesis is kept, which the hand-written pattern could not do: it stopped
    // at the first `)`, so this target was refused as `javascript:alert(1`.
    expect(fn () => inlineRuns('[click](javascript:alert(1))'))
        ->toThrow(UnsupportedLink::class, 'The link "javascript:alert(1)" is not a URL a deck may carry');
});

it('refuses a Windows share, which is a credential relay to whoever answers', function () {
    // Both spellings, because the follower is not a browser: `\\host\share\payload.exe` is
    // an SMB connection made with no prompt, and the reader's credentials go with it.
    foreach (['\\\\host\\share\\payload.exe', '//host/share/payload.exe'] as $target) {
        expect(fn () => inlineRuns(sprintf('[click](%s)', $target)))
            ->toThrow(UnsupportedLink::class, 'is not a URL a deck may carry');
    }
});

it('keeps a link whose target is a path relative to the deck', function () {
    // The other side of the rule, and the reason it is not an allow-list: `![alt](logo.png)`
    // inside a sentence is a picture this renderer draws as text, and every deck that writes
    // one would fail to render under an allow-list. A relative target is a file beside the
    // deck, not something a reader's machine acts on.
    imagepng(imagecreatetruecolor(2, 2), imageDirectory() . '/2x2.png');
    $runs = inlineRuns('see ![alt](2x2.png) here');

    expect(array_map(static fn (Run $run): ?string => $run->link, $runs))->toBe([null, '2x2.png', null]);
});

it('keeps a Windows drive letter, which is a path rather than a scheme', function () {
    // One letter before the colon: `C:\logo.png` is a picture on the disk, and RFC 3986's
    // one-character scheme is a reader's problem rather than a deck author's. league hands the
    // target over percent-encoded, so the run carries `C:%5Clogo.png`; the shape it draws
    // decodes it back before looking for the file.
    expect(inlineRuns('![alt](C:\\logo.png)')[1])->toEqual(new Run('alt', link: 'C:%5Clogo.png'));
});

it('keeps runs in the order they were written rather than the order of the patterns', function () {
    // A paragraph that says "**bold** and `code`" comes out in that order or the words move,
    // and which pattern is looked for first must not decide it.
    expect(array_map(
        static fn (Run $run): string => $run->text,
        inlineRuns('**bold** and `code`'),
    ))->toBe(['bold', ' and ', 'code']);
});

it('keeps a code span before a bold that follows it in the same order', function () {
    expect(array_map(
        static fn (Run $run): string => $run->text,
        inlineRuns('`code` then **bold**'),
    ))->toBe(['code', ' then ', 'bold']);
});

it('does not read the asterisks inside a code span as emphasis', function () {
    // Code first in the pattern list, so `*` inside a code span is the character it is.
    expect(inlineRuns('`**not bold**`'))->toEqual([new Run('**not bold**', code: true)]);
});

it('leaves an unclosed asterisk as the character it is', function () {
    expect(inlineRuns('2 * 3'))->toEqual([new Run('2 * 3')]);
});

it('leaves an unclosed bold marker as the characters they are', function () {
    expect(inlineRuns('**unclosed'))->toEqual([new Run('**unclosed')]);
});

it('leaves an unclosed code span as the characters they are', function () {
    expect(inlineRuns('`unclosed'))->toEqual([new Run('`unclosed')]);
});

it('reads an italic between two words', function () {
    expect(inlineRuns('a *b* c'))->toEqual([new Run('a '), new Run('b', italic: true), new Run(' c')]);
});

it('draws nothing for a link with no text in it', function () {
    // A writer who wrote that wrote nothing to show. The hand-written walk made one empty run
    // to hang the target on, which drew nothing either and carried a link nothing could click.
    expect(inlineRuns('[](https://example.com)'))->toBe([]);
});

it('reads a link with a title and keeps only its target', function () {
    // The title is for a reader hovering the link; PowerPoint has a tooltip for it and a
    // `.pptx` hyperlink does not carry one, so the target is what the run gets.
    expect(inlineRuns('[text](https://example.com "A title")'))->toEqual([
        new Run('text', link: EXAMPLE_LINK),
    ]);
});

it('reads an empty span as nothing', function () {
    expect(inlineRuns('**') )->toEqual([new Run('**')]);
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

it('reads four spaces of indent as a code block, and a marker inside one as code', function () {
    // A marker is a marker on a line of its own. Indented four it is an indented code block,
    // so the `::click` in it is characters rather than a step, which is what CommonMark does
    // with any block that starts four spaces in.
    expect(kindsOf("    ::click\n"))->toBe([Code::class])
        ->and(kindsOf("    plain words\n"))->toBe([Code::class]);
});

it('reads an HTML block as the words it carries', function () {
    // A tag this library does not act on is text, and an element is not one of the eight
    // shapes a slide draws. The `v-*` element is the one it refuses instead, which `Refusals`
    // does before this walk begins.
    expect(kindsOf("<div>\nSome words.\n</div>\n"))->toBe([Paragraph::class]);
});

it('refuses an HTML comment nothing closes', function () {
    // The parser cannot tell where an unclosed comment ends, so it stays in the text — which
    // is the one case where a comment would be drawn as prose, and the prose would be `<!--`.
    expect(fn () => blocksOf("# One\n\na comment <!-- never closed\n"))
        ->toThrow(UnknownDirective::class, 'has the HTML comment <!-- never closed');
});

it('has no lines for a node the parser built for itself', function () {
    // league counts lines for the blocks it read out of the deck and not for the nodes it
    // assembled around them, and reading a line that is not there has to be an empty answer
    // rather than a warning: a table's rows carry their text and no line of the deck.
    $state = new SlideState(1, __DIR__, deckScope(__DIR__), ['# One']);
    $built = new LeagueParagraph();

    expect($state->lines($built))->toBe([])
        ->and($state->written($built))->toBe('');
});
