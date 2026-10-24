<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\FileNotWritable;
use MarkdownPresentation\Exception\NothingToConvert;
use MarkdownPresentation\Exception\UnreadableDeck;
use MarkdownPresentation\PowerPointToMarkdown;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Reverse\Block;
use MarkdownPresentation\Reverse\DeckWriter;
use MarkdownPresentation\Reverse\Escaping;
use MarkdownPresentation\Reverse\Image;
use MarkdownPresentation\Reverse\Inline;
use MarkdownPresentation\Reverse\Options;
use MarkdownPresentation\Reverse\ShapeMapper;
use MarkdownPresentation\Reverse\Slide;
use MarkdownPresentation\Tests\Support\Scratch;
use PhpOffice\PhpPresentation\DocumentProperties;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\Hyperlink;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Shape\Drawing\Gd;
use PhpOffice\PhpPresentation\Shape\Placeholder;
use PhpOffice\PhpPresentation\Shape\Table as TableShape;
use PhpOffice\PhpPresentation\Slide\AbstractSlide;

/**
 * A deck written from Markdown, as a file in the scratch directory.
 *
 * The directory is where the deck's pictures are looked for, so a deck that
 * names one has to be written from the directory that holds it.
 */
function writtenDeck(string $markdown, ?string $directory = null): string
{
    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer($directory ?? Scratch::directory()))->render(
        (new DeckParser())->parse($markdown),
        $path,
    );

    return $path;
}

/**
 * A deck built with PHPPresentation rather than with this library, for the
 * shapes this library does not write: a date, a footer, a slide number.
 *
 * @param callable(AbstractSlide): void $build
 */
function deckWithShapes(callable $build, ?callable $properties = null): string
{
    $presentation = new PhpPresentation();
    $slide = $presentation->getSlide(0);

    $build($slide);

    if ($properties !== null) {
        $properties($presentation->getDocumentProperties());
    }

    $path = Scratch::file('built.pptx', '');
    IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

    return $path;
}

/**
 * A shape carrying a placeholder of the given type, and its text.
 */
function placeholderShape(AbstractSlide $slide, string $type, string $text): void
{
    $shape = $slide->createRichTextShape();
    $shape->setName($type);
    $shape->createText($text);
    $shape->setPlaceHolder(new Placeholder($type));
}

/**
 * A directory holding a picture, for a deck that names one.
 */
function pictureDirectory(): string
{
    $directory = Scratch::directoryPath('images');

    $image = imagecreatetruecolor(2, 2);
    imagefill($image, 0, 0, imagecolorallocate($image, 10, 200, 30));
    imagepng($image, $directory . '/dot.png');

    return $directory;
}

/**
 * A table with one cell, holding the paragraphs named.
 *
 * Each paragraph is a text, the level it sits at, and whether it carries a
 * bullet — the three things that decide what the cell becomes.
 *
 * @param list<array{0: string, 1: int, 2: bool}> $paragraphs
 */
function tableWithCell(array $paragraphs): TableShape
{
    $table = new TableShape();
    $cell = $table->createRow()->getCell(0);

    foreach ($paragraphs as [$text, $level, $bullet]) {
        $paragraph = $cell->createParagraph();
        $paragraph->getAlignment()->setLevel($level);

        if ($bullet) {
            $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET);
        }

        $paragraph->createTextRun($text);
    }

    return $table;
}

/**
 * A URL that reads a part out of a package made for the test.
 */
function zipUrl(string $part, string $contents): string
{
    $path = Scratch::file('parts.pptx', '');

    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFromString($part, $contents);
    $zip->close();

    return 'zip://' . $path . '#' . $part;
}

/**
 * The Markdown a deck converts to.
 */
function converted(string $path, ?Options $options = null): string
{
    return (new PowerPointToMarkdown($path, $options ?? new Options(mediaDirectory: null)))->convert();
}

/**
 * The words of a text, in the order they come.
 *
 * @return list<string>
 */
function wordsOf(string $text): array
{
    $words = [];

    foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
        if ($word !== '') {
            $words[] = $word;
        }
    }

    return $words;
}

/**
 * The words of every slide of a deck, in the order they come.
 *
 * The paragraphs are read rather than the text of a shape, because a shape of
 * text answers `getPlainText()` by running its paragraphs together -- and a
 * shape that is a placeholder is such a shape with a placeholder on it, so it
 * answers alike.
 *
 * A picture is left out: its text is its description, which a deck that was
 * written from Markdown holds and a deck that was read does not.
 *
 * @return list<list<string>>
 */
function slideWords(string $path): array
{
    $presentation = IOFactory::load($path);
    $slides = [];

    foreach ($presentation->getAllSlides() as $slide) {
        $words = [];

        foreach ($slide->getShapeCollection() as $shape) {
            if ($shape instanceof TableShape) {
                foreach ($shape->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        foreach (wordsOf($cell->getPlainText()) as $word) {
                            $words[] = $word;
                        }
                    }
                }

                continue;
            }

            if (! method_exists($shape, 'getParagraphs')) {
                continue;
            }

            foreach ($shape->getParagraphs() as $paragraph) {
                foreach (wordsOf($paragraph->getPlainText()) as $word) {
                    $words[] = $word;
                }
            }
        }

        $slides[] = $words;
    }

    return $slides;
}

/**
 * The text of every slide of a deck, as it is written.
 *
 * @return list<list<string>>
 */
function slideTexts(string $path): array
{
    $presentation = IOFactory::load($path);
    $slides = [];

    foreach ($presentation->getAllSlides() as $slide) {
        $lines = [];

        foreach ($slide->getShapeCollection() as $shape) {
            if (! method_exists($shape, 'getPlainText')) {
                continue;
            }

            foreach (explode("\n", $shape->getPlainText()) as $line) {
                if (trim($line) !== '') {
                    $lines[] = trim($line);
                }
            }
        }

        $slides[] = $lines;
    }

    return $slides;
}

it('reads a deck back into a deck', function () {
    $markdown = <<<'MD'
    ---
    title: Harbour lights
    ---

    # Harbour lights

    Three walks, one afternoon

    ---

    # What is out there

    - The long pier, at dusk
    - The boats, at anchor
      - The one with the red lamp
    - The water, after

    ::note
    Ask about the tide tables.
    ::
    MD;

    $first = writtenDeck($markdown);
    $back = converted($first);

    $second = writtenDeck($back);

    expect(slideTexts($second))->toBe(slideTexts($first));
});

it('reads a title placeholder as the heading of the slide', function () {
    $markdown = converted(writtenDeck("# Harbour lights\n\nThree walks.\n"));

    expect($markdown)->toContain('# Harbour lights');
});

it('reads a subtitle as a paragraph under the heading', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        placeholderShape($slide, 'ctrTitle', 'Harbour lights');
        placeholderShape($slide, 'subTitle', 'A field guide to the North');
    });

    expect(converted($path))->toContain("# Harbour lights\n\nA field guide to the North");
});

it('leaves the date, the footer and the slide number out', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        placeholderShape($slide, 'title', 'Harbour lights');
        placeholderShape($slide, 'body', 'Three walks.');
        placeholderShape($slide, 'dt', '22 October 2026');
        placeholderShape($slide, 'ftr', 'Confidential');
        placeholderShape($slide, 'sldNum', '3');
    });

    $markdown = converted($path);

    expect($markdown)->toContain('Harbour lights')
        ->and($markdown)->toContain('Three walks.')
        ->and($markdown)->not->toContain('22 October 2026')
        ->and($markdown)->not->toContain('Confidential')
        ->and($markdown)->not->toContain('3');
});

it('reads a bulleted body as a list', function () {
    $markdown = converted(writtenDeck("# Agenda\n\n- Where we are\n- What broke\n"));

    expect($markdown)->toContain("- Where we are\n- What broke");
});

it('reads a numbered list as a numbered list', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');

        foreach (['One', 'Two'] as $line) {
            $paragraph = $shape->createParagraph();
            $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_NUMERIC);
            $paragraph->createTextRun($line);
        }
    });

    expect(converted($path))->toContain("1. One\n2. Two");
});

it('reads a nested list as a nested list', function () {
    $markdown = converted(writtenDeck("# Agenda\n\n- The boats\n  - The one with the red lamp\n"));

    expect($markdown)->toContain("- The boats\n    - The one with the red lamp");
});

it('reads a body placeholder as a list and a text box as prose', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        placeholderShape($slide, 'body', 'Where we are');

        $box = $slide->createRichTextShape();
        $box->setName('TextBox');
        $box->createText('A note under the slide');
    });

    $markdown = converted($path);

    expect($markdown)->toContain('- Where we are')
        ->and($markdown)->toContain("\nA note under the slide");
});

it('reads a shape whose text is a link as a link', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createText('The harbour');
        $shape->setHyperlink(new Hyperlink('https://example.org/harbour'));
    });

    expect(converted($path))->toContain('[The harbour](https://example.org/harbour)');
});

it('leaves a link to another slide out, which a deck cannot say', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createText('The next slide');

        $hyperlink = new Hyperlink();
        $hyperlink->setSlideNumber(2);
        $shape->setHyperlink($hyperlink);
    });

    expect(converted($path))->toContain('The next slide')
        ->and(converted($path))->not->toContain('](');
});

it('reads a speaker note into a note block', function () {
    $markdown = converted(writtenDeck("# Agenda\n\n::note\nAsk about the tide tables.\n::\n"));

    expect($markdown)->toContain("::note\nAsk about the tide tables.\n::");
});

it('reads a table into a Markdown table', function () {
    $markdown = converted(writtenDeck("# Numbers\n\n| Deck | Slides |\n| --- | --- |\n| worked in | six |\n"));

    expect($markdown)->toContain("| **Deck** | **Slides** |\n| --- | --- |\n| worked in | six |");
});

it('keeps every paragraph of a cell', function () {
    // A cell holds whatever was written into it, which can be more than one
    // paragraph. Reading only the first block of the cell lost the rest.
    $table = tableWithCell([['First line', 0, false], ['Second line', 0, false]]);
    $mapper = new ShapeMapper(new Options(mediaDirectory: null));
    $blocks = $mapper->blocks($table, 1, 0);

    $writer = new DeckWriter(new Options(mediaDirectory: null));
    $markdown = $writer->write([new Slide(1, null, $blocks, null)], new DocumentProperties());

    expect($markdown)->toContain('First line')
        ->and($markdown)->toContain('Second line');
});

it('keeps the text of a cell whose paragraph is a bullet', function () {
    // A bulleted paragraph in a cell is a list rather than a paragraph, and a
    // list has no inline content of its own — so reading the cell's first block
    // as if it were a paragraph gave an empty cell.
    $table = tableWithCell([['Bulleted', 0, true]]);
    $mapper = new ShapeMapper(new Options(mediaDirectory: null));
    $blocks = $mapper->blocks($table, 1, 0);

    $writer = new DeckWriter(new Options(mediaDirectory: null));
    $markdown = $writer->write([new Slide(1, null, $blocks, null)], new DocumentProperties());

    expect($markdown)->toContain('Bulleted');
});

it('reads a picture out of the deck and refers to it by name', function () {
    $images = pictureDirectory();
    $directory = Scratch::directoryPath('media');
    $path = writtenDeck("![A dot](dot.png)\n", $images);

    $markdown = (new PowerPointToMarkdown($path, new Options(mediaDirectory: $directory)))->convert();

    expect($markdown)->toContain('![')
        ->and($markdown)->toContain($directory . '/slide-1-image-0.png');
});

it('writes the pictures beside the deck', function () {
    $images = pictureDirectory();
    $directory = Scratch::directoryPath('media');
    $path = writtenDeck("![A dot](dot.png)\n", $images);

    (new PowerPointToMarkdown($path, new Options(mediaDirectory: $directory)))->convert();

    expect(glob($directory . '/*'))->not->toBeEmpty();
});

it('writes the pictures beside the Markdown that names them', function () {
    // The reference in the Markdown is relative to the Markdown, so the pictures
    // have to be where it resolves to. Writing them beside the working directory
    // instead puts them where the reference does not reach, and every conversion
    // whose target is not in the working directory comes out with a broken image.
    $images = pictureDirectory();
    $path = writtenDeck("![A dot](dot.png)\n", $images);

    // A target in a directory of its own, and a working directory that is not
    // that directory: the two are what a caller gets when the deck is somewhere
    // else and the Markdown is somewhere else again.
    $target = Scratch::directoryPath('output') . '/deck.md';
    $working = Scratch::directoryPath('working');

    $previous = getcwd();
    chdir($working);

    try {
        (new PowerPointToMarkdown($path, new Options(mediaDirectory: 'media')))->save($target);
    } finally {
        chdir((string) $previous);
    }

    $markdown = (string) file_get_contents($target);

    // The reference is relative, and the file it names is beside the Markdown.
    expect($markdown)->toContain('![A dot](media/slide-1-image-0.png)')
        ->and(is_file(dirname($target) . '/media/slide-1-image-0.png'))->toBeTrue();

    // And nothing was written into the working directory, which is where it went
    // before and where the reference did not reach.
    expect(glob($working . '/media/*'))->toBe([]);
});

it('leaves a picture that is already there alone', function () {
    $images = pictureDirectory();
    $directory = Scratch::directoryPath('media');
    $path = writtenDeck("![A dot](dot.png)\n", $images);

    file_put_contents($directory . '/slide-1-image-0.png', 'not a picture');

    (new PowerPointToMarkdown($path, new Options(mediaDirectory: $directory)))->convert();

    expect(file_get_contents($directory . '/slide-1-image-0.png'))->toBe('not a picture');
});

it('keeps the order the slide lists its shapes in', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        placeholderShape($slide, 'title', 'What broke');

        $left = $slide->createRichTextShape();
        $left->setName('Left');
        $left->createText('The exporter');
        $left->setOffsetX(60);
        $left->setOffsetY(240);

        $right = $slide->createRichTextShape();
        $right->setName('Right');
        $right->createText('The theme');
        $right->setOffsetX(600);
        $right->setOffsetY(60);
    });

    $markdown = converted($path);

    expect(strpos($markdown, 'The exporter'))->toBeLessThan(strpos($markdown, 'The theme'));
});

it('keeps the bold and the italic and drops the rest of the setting', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createTextRun('Plain')->getFont()->setBold(true);
    });

    expect(converted($path))->toContain('**Plain**');
});

it('keeps the italic', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createTextRun('Slanted')->getFont()->setItalic(true);
    });

    expect(converted($path))->toContain('*Slanted*');
});

it('keeps the strike-through', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createTextRun('Dropped')->getFont()->setStrikethrough('sngStrike');
    });

    expect(converted($path))->toContain('~~Dropped~~');
});

it('escapes text that would otherwise be read as Markdown', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createText('* not emphasis');
    });

    expect(converted($path))->toContain('\\* not emphasis');
});

it('writes the line ending it was asked for', function () {
    $path = writtenDeck("# Harbour lights\n");

    $markdown = (new PowerPointToMarkdown($path, new Options(mediaDirectory: null, lineEnding: "\r\n")))->convert();

    expect($markdown)->toContain("\r\n");
});

it('writes no frontmatter for a deck that says nothing about itself', function () {
    $path = deckWithShapes(
        static function (AbstractSlide $slide): void {
            placeholderShape($slide, 'title', 'Harbour lights');
        },
        static function (DocumentProperties $properties): void {
            $properties->setTitle('');
            $properties->setCreator('');
        },
    );

    expect(converted($path))->not->toContain('---');
});

it('writes the deck\'s own properties in the frontmatter', function () {
    $path = deckWithShapes(
        static function (AbstractSlide $slide): void {
            placeholderShape($slide, 'title', 'Harbour lights');
        },
        static function (DocumentProperties $properties): void {
            $properties->setTitle('Harbour lights');
            $properties->setCreator('Fabian');
        },
    );

    expect(converted($path))->toContain("title: \"Harbour lights\"")
        ->and(converted($path))->toContain('author: "Fabian"');
});

it('refuses a package with more parts than the limit allows', function () {
    $path = writtenDeck("# One\n");

    expect(fn () => converted($path, new Options(mediaDirectory: null, maxEntries: 1)))
        ->toThrow(UnreadableDeck::class, 'more entries than the 1 allowed');
});

it('refuses a part larger than the limit allows', function () {
    $path = writtenDeck("# One\n");

    expect(fn () => converted($path, new Options(mediaDirectory: null, maxPartBytes: 16)))
        ->toThrow(UnreadableDeck::class, 'larger than the 16 allowed');
});

it('refuses a file that is not a deck', function () {
    $path = Scratch::file('not-a-deck.pptx', 'this is not a package');

    expect(fn () => converted($path))->toThrow(UnreadableDeck::class);
});

it('refuses a conversion with nothing to convert', function () {
    expect(fn () => (new PowerPointToMarkdown())->convert())
        ->toThrow(NothingToConvert::class, 'There is no deck to convert');
});

it('converts the bytes of a deck', function () {
    $bytes = file_get_contents(writtenDeck("# Harbour lights\n"));

    $markdown = (new PowerPointToMarkdown(options: new Options(mediaDirectory: null)))->toMarkdown($bytes);

    expect($markdown)->toContain('# Harbour lights');
});

it('refuses the bytes of something that is not a deck', function () {
    expect(fn () => (new PowerPointToMarkdown(options: new Options(mediaDirectory: null)))->toMarkdown('not a package'))
        ->toThrow(UnreadableDeck::class);
});

it('reads the slides of a deck for a caller that wants to look at them', function () {
    $path = writtenDeck("# Harbour lights\n\nThree walks.\n");

    $slides = (new PowerPointToMarkdown($path, new Options(mediaDirectory: null)))->read($path);

    expect($slides)->toHaveCount(1)
        ->and($slides[0]->title)->toBe('Harbour lights')
        ->and($slides[0]->blocks)->not->toBeEmpty();
});

it('hands back the options it was given', function () {
    $options = new Options(mediaDirectory: null);

    expect((new PowerPointToMarkdown(options: $options))->getOptions())->toBe($options);
});

it('refuses to write a deck where the file cannot go', function () {
    $path = writtenDeck("# Harbour lights\n");
    $blocker = Scratch::file('blocker', 'not a directory');

    expect(fn () => (new PowerPointToMarkdown($path, new Options(mediaDirectory: null)))->save($blocker . '/deck.md'))
        ->toThrow(FileNotWritable::class, 'Unable to create the directory');
});

it('writes the deck to a file', function () {
    $path = writtenDeck("# Harbour lights\n");
    $target = Scratch::file('deck.md', '');

    (new PowerPointToMarkdown($path, new Options(mediaDirectory: null)))->save($target);

    expect(file_get_contents($target))->toContain('# Harbour lights');
});

it('changes one option and leaves the others', function () {
    $options = new Options();

    expect($options->withMediaDirectory(null)->mediaDirectory)->toBeNull()
        ->and($options->withMediaDirectory(null)->lineEnding)->toBe($options->lineEnding)
        ->and($options->withLineEnding("\r\n")->lineEnding)->toBe("\r\n")
        ->and($options->withLineEnding("\r\n")->mediaDirectory)->toBe($options->mediaDirectory)
        ->and($options->withMaxPartBytes(1)->maxPartBytes)->toBe(1)
        ->and($options->withMaxPartBytes(1)->maxEntries)->toBe($options->maxEntries)
        ->and($options->withMaxEntries(1)->maxEntries)->toBe(1)
        ->and($options->withMaxEntries(1)->maxPartBytes)->toBe($options->maxPartBytes);
});

it('escapes the characters that would otherwise be read as Markdown', function (string $text, string $expected) {
    expect(Escaping::text($text))->toBe($expected);
})->with([
    'nothing' => ['', ''],
    'a backslash' => ['a\\b', 'a\\\\b'],
    'a backtick' => ['a`b', 'a\\`b'],
    'a star' => ['a*b', 'a\\*b'],
    'a bracket' => ['a[b]c', 'a\\[b\\]c'],
    'a strikethrough' => ['a~~b', 'a\\~\\~b'],
    'an ampersand' => ['a &amp; b', 'a \\&amp; b'],
    'an ampersand and nothing else' => ['a & b', 'a & b'],
    'a tag' => ['<div>', '\\<div>'],
    'a less-than' => ['a < b', 'a < b'],
]);

it('escapes the start of a line', function (string $text, string $expected) {
    expect(Escaping::text($text, lineStart: true))->toBe($expected);
})->with([
    'a heading' => ['# One', '\\# One'],
    'a quote' => ['> One', '\\> One'],
    'a rule' => ['===', '\\==='],
    'a list' => ['- One', '\\- One'],
    'a numbered list' => ['1. One', '1\\. One'],
    'a numbered list in brackets' => ['1) One', '1\\) One'],
    'a year' => ['1986. It was', '1986\\. It was'],
    'nothing after the space' => ['  ', '  '],
    'nothing at all' => ['', ''],
]);

it('escapes a pipe inside a table cell', function () {
    expect(Escaping::text('a|b', inTable: true))->toBe('a\\|b');
});

it('leaves an underscore inside a word alone', function () {
    expect(Escaping::text('snake_case'))->toBe('snake_case');
});

it('escapes an underscore that could open emphasis', function () {
    expect(Escaping::text('a _b_ c'))->toBe('a \\_b\\_ c');
});

it('leaves an underscore that could open nothing alone', function () {
    expect(Escaping::text('__ foo bar__'))->toBe('__ foo bar__');
});

it('escapes every underscore of a run', function () {
    expect(Escaping::text('a __b__ c'))->toBe('a \\_\\_b\\_\\_ c');
});

it('makes a break inside a paragraph', function () {
    expect(Inline::break()->kind)->toBe(Inline::BREAK);
});

it('reads a line break inside a paragraph as a break', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createText('First');
        $shape->createBreak();
        $shape->createText('Second');
    });

    expect(converted($path))->toContain("First\\\nSecond");
});

it('reads a run that holds more than one line as more than one line', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createText("First\nSecond");
    });

    expect(converted($path))->toContain("First\nSecond");
});

it('leaves a field out, which is a number and not a word', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');
        $shape->createText('Slide ');
        $shape->getParagraph(0)->createField('slidenum');
    });

    expect(converted($path))->toContain('Slide');
});

it('leaves a shape that is none of the three kinds out', function () {
    $path = deckWithShapes(
        static function (AbstractSlide $slide): void {
            $slide->createLineShape(0, 0, 100, 100);
        },
        static function (DocumentProperties $properties): void {
            $properties->setTitle('');
            $properties->setCreator('');
        },
    );

    expect(trim(converted($path)))->toBe('');
});

it('reads a list whose levels jump as the levels say', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');

        foreach ([0, 2, 1] as $level) {
            $paragraph = $shape->createParagraph();
            $paragraph->getAlignment()->setLevel($level);
            $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET);
            $paragraph->createTextRun('Level ' . $level);
        }
    });

    // Every item survives, and not only the first one: the levels jump from 0 to
    // 2 and back to 1, and the walk that reads the nested items used to end at
    // the jump and drop every item from there onwards.
    $markdown = converted($path);

    expect($markdown)->toContain('- Level 0')
        ->and($markdown)->toContain('- Level 2')
        ->and($markdown)->toContain('- Level 1');
});

it('reads a placeholder of a kind it does not know as free text', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        placeholderShape($slide, 'chart', 'A chart');
    });

    expect(converted($path))->toContain('A chart');
});

it('leaves a shape with no text out', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Empty');
    });

    expect(converted($path))->not->toContain('Empty');
});

it('reads a note that is not in the body placeholder', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $note = $slide->getNote();
        $note->createRichTextShape()->createText('Ask about the tide tables.');
    });

    expect(converted($path))->toContain("::note\nAsk about the tide tables.\n::");
});

it('refuses a package that is not a deck', function () {
    $path = Scratch::file('a-package.pptx', '');
    unlink($path);

    $zip = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE);
    $zip->addFromString('hello.txt', 'not a deck');
    $zip->close();

    expect(fn () => converted($path))->toThrow(UnreadableDeck::class, 'as a deck');
});

it('writes nothing for a block that holds nothing', function () {
    $writer = new DeckWriter(new Options(mediaDirectory: null));

    expect($writer->write([
        new Slide(0, null, [
            Block::paragraph([]),
            Block::list([Block::item([Block::paragraph([])])]),
            Block::table([]),
            Block::image(new Image('A dot', '', '')),
        ]),
    ], new DocumentProperties()))->toContain('---');
});

it('leaves a picture with nothing in it out', function () {
    $mapper = new ShapeMapper(new Options(mediaDirectory: 'media'));
    $picture = new Gd();
    $picture->setMimeType('image/');

    expect($mapper->blocks($picture, 1, 0))->toBe([]);
});

it('leaves a table with no rows out', function () {
    $mapper = new ShapeMapper(new Options(mediaDirectory: 'media'));
    $table = new TableShape();

    expect($mapper->blocks($table, 1, 0))->toBe([]);
});

it('refuses to write a deck where the target is a directory', function () {
    $path = writtenDeck("# Harbour lights\n");
    $target = Scratch::directoryPath('a-directory');

    expect(fn () => (new PowerPointToMarkdown($path, new Options(mediaDirectory: null)))->save($target))
        ->toThrow(FileNotWritable::class, 'Unable to write');
});

it('refuses to write a deck two levels under a file', function () {
    $path = writtenDeck("# Harbour lights\n");
    $blocker = Scratch::file('blocker', 'not a directory');

    expect(fn () => (new PowerPointToMarkdown($path, new Options(mediaDirectory: null)))->save($blocker . '/a/deck.md'))
        ->toThrow(FileNotWritable::class, 'Unable to create the directory');
});

it('makes the directory a path asks for', function () {
    $path = writtenDeck("# Harbour lights\n");
    $target = Scratch::directory() . '/nested-' . bin2hex(random_bytes(4)) . '/deck.md';

    (new PowerPointToMarkdown($path, new Options(mediaDirectory: null)))->save($target);

    expect(file_get_contents($target))->toContain('# Harbour lights');
});

it('refuses to write the pictures where the media directory cannot be made', function () {
    $images = pictureDirectory();
    $blocker = Scratch::file('blocker', 'not a directory');
    $path = writtenDeck("![A dot](dot.png)\n", $images);

    expect(fn () => (new PowerPointToMarkdown($path, new Options(mediaDirectory: $blocker)))->convert())
        ->toThrow(FileNotWritable::class, 'Unable to create the media directory');
});

it('reads a deck PowerPoint wrote', function () {
    $markdown = converted(workshopNotes());

    expect($markdown)->toContain('# Workshop notes')
        ->and($markdown)->toContain('Tuesday')
        ->and($markdown)->toContain('# Agenda')
        ->and($markdown)->toContain('Where we are');
});

it('takes a deck PowerPoint wrote apart and puts it back together in another design', function () {
    $images = Scratch::directoryPath('media');

    $markdown = (new PowerPointToMarkdown(workshopNotes(), new Options(mediaDirectory: $images)))->convert();

    $path = Scratch::file('deck.pptx', '');
    (new PresentationRenderer(Scratch::directory()))->render(
        (new DeckParser())->parse($markdown),
        $path,
    );

    $read = slideWords(workshopNotes());
    $written = slideWords($path);

    // The picture in the deck that was read, and the one in the deck that was written.
    $zip = new \ZipArchive();
    $zip->open(workshopNotes());
    $original = $zip->getFromName('ppt/media/image1.png');
    $zip->close();

    $zip = new \ZipArchive();
    $zip->open($path);
    $media = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with($zip->getNameIndex($i), 'ppt/media/')) {
            $media = $zip->getFromName($zip->getNameIndex($i));

            break;
        }
    }
    $zip->close();

    expect($written)->toBe($read)
        ->and($media)->toBe($original);
});

it('leaves the pictures out when there is nowhere to put them', function () {
    $images = pictureDirectory();
    $path = writtenDeck("![A dot](dot.png)\n", $images);

    expect(converted($path))->not->toContain('![');
});

it('reads a paragraph nested deeper than the first level as a list item', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');

        $paragraph = $shape->createParagraph();
        $paragraph->getAlignment()->setLevel(1);
        $paragraph->createTextRun('Nested');
    });

    expect(converted($path))->toContain('- Nested');
});

it('takes the pictures out of a deck that has words as well', function () {
    $images = pictureDirectory();
    $directory = Scratch::directoryPath('media');
    $path = writtenDeck("Words.\n\n![A dot](dot.png)\n", $images);

    $markdown = (new PowerPointToMarkdown($path, new Options(mediaDirectory: $directory)))->convert();

    expect($markdown)->toContain('Words.')
        ->and($markdown)->toContain('![');
});

it('refuses a source that is neither a deck nor the name of one', function () {
    expect(fn () => (new PowerPointToMarkdown('/nowhere/deck.pptx'))->convert())
        ->toThrow(UnreadableDeck::class, 'There is no deck at "/nowhere/deck.pptx"');
});

it('reads a deck given as bytes and a deck given as a path the same way', function () {
    $path = writtenDeck("# Harbour lights\n");
    $bytes = (string) file_get_contents($path);

    $fromPath = (new PowerPointToMarkdown($path, new Options(mediaDirectory: null)))->convert();
    $fromBytes = (new PowerPointToMarkdown($bytes, new Options(mediaDirectory: null)))->convert();

    expect($fromBytes)->toBe($fromPath);
});

it('takes the picture out of the deck with the bytes the deck holds', function () {
    $directory = Scratch::directoryPath('media');
    $path = workshopNotes();

    $markdown = (new PowerPointToMarkdown($path, new Options(mediaDirectory: $directory)))->convert();

    // The picture in the deck, as the package holds it.
    $zip = new \ZipArchive();
    $zip->open($path);
    $original = $zip->getFromName('ppt/media/image1.png');
    $zip->close();

    $written = glob($directory . '/*.png');

    expect($original)->not->toBeFalse()
        ->and($written)->toHaveCount(1)
        ->and((string) file_get_contents($written[0]))->toBe((string) $original);
});

it('leaves a picture with no path out', function () {
    $mapper = new ShapeMapper(new Options(mediaDirectory: 'media'));

    expect($mapper->blocks(new Gd(), 1, 0))->toBe([]);
});

it('leaves a picture whose name is not an image out', function () {
    $mapper = new ShapeMapper(new Options(mediaDirectory: 'media'));

    $picture = new Gd();
    $picture->setPath(zipUrl('ppt/media/notes.php', '<?php echo 1;'));

    expect($mapper->blocks($picture, 1, 0))->toBe([]);
});

it('leaves a picture whose bytes are not an image out', function () {
    $mapper = new ShapeMapper(new Options(mediaDirectory: 'media'));

    $picture = new Gd();
    $picture->setPath(zipUrl('ppt/media/dot.png', 'not a picture'));

    expect($mapper->blocks($picture, 1, 0))->toBe([]);
});

it('takes a vector out as well, which a raster check cannot see', function () {
    $mapper = new ShapeMapper(new Options(mediaDirectory: 'media'));

    $picture = new Gd();
    $picture->setPath(zipUrl('ppt/media/dot.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));

    expect($mapper->blocks($picture, 1, 0))->toHaveCount(1);
});
