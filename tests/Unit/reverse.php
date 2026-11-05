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
use MarkdownPresentation\Reverse\Shape;
use MarkdownPresentation\Reverse\SlideReader;
use MarkdownPresentation\Reverse\ShapeMapper;
use MarkdownPresentation\Reverse\Slide;
use MarkdownPresentation\Tests\Support\Scratch;
use PhpOffice\PhpPresentation\DocumentProperties;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\PhpPresentation;
use MarkdownPresentation\Render\Free\ShapeKind;
use PhpOffice\PhpPresentation\Shape\AutoShape;
use PhpOffice\PhpPresentation\Shape\Hyperlink;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Shape\Drawing\Gd;
use PhpOffice\PhpPresentation\Shape\Placeholder;
use PhpOffice\PhpPresentation\Shape\Table as TableShape;
use PhpOffice\PhpPresentation\Slide\Background\Color as BackgroundColour;
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

it('reads a background of a colour back as one', function () {
    $deck = writtenDeck("---\nbackground: accent1\n---\n\n# One\n");

    $markdown = (new PowerPointToMarkdown($deck))->convert();

    expect($markdown)->toContain('background: "#4472C4"');
});

it('reads a background of a gradient back as one', function () {
    $deck = writtenDeck("---\nbackground-gradient: \"accent1,lt2\"\nbackground-angle: 45\n---\n\n# One\n");

    $markdown = (new PowerPointToMarkdown($deck))->convert();

    expect($markdown)->toContain('background-gradient: "#4472C4,#E7E6E6"')
        ->and($markdown)->toContain('background-angle: 45');
});

it('reads the places of a background gradient back as one', function () {
    $deck = writtenDeck("---\nbackground-gradient: \"accent1,dk1@50,lt2\"\n---\n\n# One\n");

    $markdown = (new PowerPointToMarkdown($deck))->convert();

    expect($markdown)->toContain('background-gradient: "#4472C4,#000000@50,#E7E6E6"');
});

it('reads a background of a radial gradient back as one', function () {
    $deck = writtenDeck("---\nbackground-gradient: \"accent1,lt2\"\nbackground-radial: true\n---\n\n# One\n");

    $markdown = (new PowerPointToMarkdown($deck))->convert();

    expect($markdown)->toContain('background-gradient: "#4472C4,#E7E6E6"')
        ->and($markdown)->toContain('background-radial: true');
});

it('reads the background of a slide that is not the first back as one', function () {
    $deck = writtenDeck("---\ntitle: One\n---\n\n# One\n\n---\nbackground: accent1\n---\n\n# Two\n");

    $markdown = (new PowerPointToMarkdown($deck))->convert();

    expect($markdown)->toContain('background: "#4472C4"');
});

it('leaves a background of no colour out', function () {
    // The writer refuses a background of no colour, so it is read rather than written.
    $presentation = new PhpPresentation();
    $slide = $presentation->getActiveSlide();
    $slide->setBackground(new BackgroundColour());

    $read = (new SlideReader(new ShapeMapper(new Options())))->read($slide, 0);

    expect($read->frontmatter)->toBe([]);
});

it('reads the gradient of a shape back as one', function () {
    $deck = writtenDeck("---\nlayout: blank\n---\n\n::rectangle{gradient=\"accent1,dk1@50,lt2\" gradient-angle=45}\n");

    $markdown = (new PowerPointToMarkdown($deck, new Options(preserveGeometry: true)))->convert();

    expect($markdown)->toContain('gradient="#4472C4,#000000@50,#E7E6E6"')
        ->and($markdown)->toContain('gradient-angle=45');
});

it('reads the gradient of a radial shape back as one', function () {
    $deck = writtenDeck("---\nlayout: blank\n---\n\n::rectangle{gradient=\"accent1,lt2\" gradient-radial}\n");

    $markdown = (new PowerPointToMarkdown($deck, new Options(preserveGeometry: true)))->convert();

    expect($markdown)->toContain('gradient="#4472C4,#E7E6E6"')
        ->and($markdown)->toContain('gradient-radial=true');
});

it('keeps the paragraphs of a title apart', function () {
    // A title is one line in Markdown, so only its first paragraph is the
    // heading; the rest are the title's own paragraphs, and `getPlainText()`
    // runs them together.
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('ctrTitle');
        $shape->setPlaceHolder(new Placeholder('ctrTitle'));

        $shape->createParagraph()->createTextRun('First line');
        $shape->createParagraph()->createTextRun('Second line');
    });

    $markdown = converted($path);

    expect($markdown)->toContain('# First line')
        ->and($markdown)->toContain('Second line')
        ->and($markdown)->not->toContain('First lineSecond line');
});

it('does not let an empty title take the heading away', function () {
    // A title that is empty is not a title, so what it held is read as the
    // blocks it was and the heading the slide already carries is left alone.
    // Two titles on one slide is unusual, and nothing in the deck says which
    // of them is the heading, so the order they are in is what decides.
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('ctrTitle');
        $shape->setPlaceHolder(new Placeholder('ctrTitle'));
        $shape->createParagraph()->createTextRun('Harbour lights');

        $empty = $slide->createRichTextShape();
        $empty->setName('ctrTitle');
        $empty->setPlaceHolder(new Placeholder('ctrTitle'));
        $empty->createParagraph()->createTextRun('   ');
    });

    $markdown = converted($path);

    expect($markdown)->toContain('# Harbour lights');
});

it('keeps the paragraphs of a subtitle apart', function () {
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('subTitle');
        $shape->setPlaceHolder(new Placeholder('subTitle'));

        $shape->createParagraph()->createTextRun('A field guide');
        $shape->createParagraph()->createTextRun('to the North');
    });

    $markdown = converted($path);

    expect($markdown)->toContain('A field guide')
        ->and($markdown)->toContain('to the North')
        ->and($markdown)->not->toContain('A field guideto the North');
});

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
 * A picture that declares a canvas of forty thousand by forty thousand.
 *
 * The header is what says so, and the header is what the size is read from:
 * the picture is a few dozen bytes and the data behind it is a stub, because
 * nothing decodes it.
 */
function bombPicture(): string
{
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    $header = pack('NN', 40000, 40000) . pack('C', 8) . pack('C', 2) . pack('C', 0) . pack('C', 0) . pack('C', 0);

    return pack('H*', '89504e470d0a1a0a')
        . $chunk('IHDR', $header)
        . $chunk('IDAT', gzcompress("\0"))
        . $chunk('IEND', '');
}

/**
 * A deck with one picture in it, holding the bytes named.
 *
 * The deck is one this library wrote, so that the picture is the only thing
 * hostile about it.
 */
function deckWithPicture(string $picture): string
{
    $path = Scratch::file('deck.pptx', '');

    $presentation = new PhpPresentation();
    $slide = $presentation->getActiveSlide();

    $shape = $slide->createRichTextShape();
    $shape->setName('Text');
    $shape->createText('A picture');

    $drawing = new Gd();
    $drawing->setContents($picture);
    $slide->addShape($drawing);

    IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

    return $path;
}

function deckWithNestedList(int $levels): string
{
    return deckWithShapes(static function (AbstractSlide $slide) use ($levels): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('Text');

        for ($level = 0; $level <= $levels; $level++) {
            $paragraph = $shape->createParagraph();
            $paragraph->getAlignment()->setLevel($level);
            $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET);
            $paragraph->createTextRun('Level ' . $level);
        }
    });
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
 * The free shapes of a deck, by slide and by name, with the box and the paint each has.
 *
 * The names the library gives its free shapes are the names of the directives that draw
 * them, so a shape whose name is one of those is a shape the deck placed itself.
 *
 * @return array<int, array<string, array{box: string, paint: string}>>
 */
function freeShapesOf(string $path): array
{
    $deck = (new IOFactory())->createReader('PowerPoint2007')->load($path);
    $kinds = array_column(ShapeKind::cases(), 'value');
    $shapes = [];

    foreach ($deck->getAllSlides() as $index => $slide) {
        foreach ($slide->getShapeCollection() as $shape) {
            $name = $shape->getName();

            if (!in_array($name, $kinds, true)) {
                continue;
            }

            $fill = $shape->getFill();

            $shapes[$index][$name] = [
                'box' => sprintf(
                    '%d,%d %dx%d',
                    $shape->getOffsetX(),
                    $shape->getOffsetY(),
                    $shape->getWidth(),
                    $shape->getHeight(),
                ),
                'paint' => $fill->getFillType() === Fill::FILL_SOLID
                    ? $fill->getStartColor()->getRGB()
                    : $fill->getFillType(),
            ];
        }
    }

    return $shapes;
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

it('names the theme a deck was drawn in, so that it can be drawn in it again', function () {
    // A deck carries the theme's colours and its fonts and nothing else about it: the name a
    // deck's `theme:` names is a property of the Markdown it was written from, and nothing in
    // the package said which directory it came from. So a deck read back into Markdown came
    // back in the library's own theme rather than the one it was drawn in.
    $path = writtenDeck("---\ntitle: Themed\ntheme: pitch\n---\n\n# One\n\nText.\n");

    $zip = new ZipArchive();
    $zip->open($path);
    $theme = (string) $zip->getFromName('ppt/theme/theme1.xml');
    $zip->close();

    expect($theme)->toContain('name="pitch"');
});

it('writes the theme back into the Markdown it reads a deck into', function () {
    $markdown = converted(writtenDeck("---\ntitle: Themed\ntheme: pitch\n---\n\n# One\n\nText.\n"));

    expect($markdown)->toContain('theme: "pitch"');
});

it('writes no theme into the Markdown of a deck drawn in the default one', function () {
    // A deck that names the default theme names nothing in particular, and a line saying so
    // is a line the reader has to read past.
    $markdown = converted(writtenDeck("# One\n\nText.\n"));

    expect($markdown)->not->toContain('theme:');
});

it('writes no theme into the Markdown of a deck another program wrote', function () {
    // The name in the theme part is the name a deck's `theme:` names only when this library
    // wrote the deck. Another program writes `Office Theme` there, and a deck that names a
    // theme nobody has is a deck that cannot be drawn again.
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->createText('Text');
    });

    expect(converted($path))->not->toContain('theme:');
});

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
    // paragraph.
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

it('escapes the alt text of a picture, which is the author\'s', function () {
    // The alt text is a `descr` attribute of the picture, so it is whatever the
    // author typed, and a bracket in it ends the reference early. A newline in
    // it ends the line the reference is on and leaves the rest of it as prose.
    $image = new Image("a ] bracket\n![evil](http://evil/x.png)", 'slide-1-image-0.png', 'bytes');
    $writer = new DeckWriter(new Options(mediaDirectory: 'media'));

    $markdown = $writer->write(
        [new Slide(1, null, [new Block(Block::IMAGE, image: $image)], null)],
        new DocumentProperties(),
    );

    expect($markdown)->toContain('a \\] bracket')
        ->and($markdown)->not->toContain('](' . "\n")
        ->and($markdown)->not->toContain("\n![evil]");
});

it('writes the address of a link between angle brackets', function () {
    // A space in an address ends it early, and a bracket in it is read as the
    // end of the reference.
    $link = Inline::link('http://example.com/a b', [Inline::text('a link')]);
    $writer = new DeckWriter(new Options(mediaDirectory: 'media'));

    $markdown = $writer->write(
        [new Slide(1, null, [Block::paragraph([$link])], null)],
        new DocumentProperties(),
    );

    expect($markdown)->toContain('<http://example.com/a b>');
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
    // have to be where it resolves to.
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

it('writes no title into the frontmatter of a deck that has none', function () {
    // PHPPresentation gives every deck the title "Untitled Presentation", and that is the
    // library's own default rather than a title somebody wrote: a deck carrying it says
    // nothing about its title. The frontmatter wrote it all the same, and drawing the
    // Markdown again added a title to a slide that never had one.
    $path = writtenDeck("Text only.\n");

    expect(converted($path))->not->toContain('title:');
});

it('writes the title of the first slide into the frontmatter of a deck that has none', function () {
    // The frontmatter is the first slide's, and a `title:` in it is that slide's title, so
    // a deck whose title is on its slide and nowhere else would lose it.
    $path = writtenDeck("# One\n\nText.\n");

    expect(converted($path))->toContain('title: "One"');
});

it('takes the title of the first slide and not the title of a later one', function () {
    // The deck's title is the first slide's title, the way it is in the other direction:
    // a deck whose second slide has a title and whose first does not gains no title.
    $path = writtenDeck("Text only.\n\n---\n\n# Two\n");

    expect(converted($path))->not->toContain('title:');
});

it('writes the title of the deck into the frontmatter where it has one', function () {
    // The deck's own title is the deck's, and the first slide's title is the first
    // slide's: the two are told apart here, and the deck's is the one that is written.
    $path = deckWithShapes(
        static function (AbstractSlide $slide): void {
            placeholderShape($slide, 'title', 'What broke');
        },
        static function (DocumentProperties $properties): void {
            $properties->setTitle('Harbour lights');
        },
    );

    expect(converted($path))->toContain('title: "Harbour lights"');
});

it('writes no author into the frontmatter of a deck that has none', function () {
    // PHPPresentation gives every deck the author "Unknown Creator", and that is the
    // library's own default rather than a name somebody wrote: the Markdown carried it
    // as though somebody had, and the deck drawn from that Markdown was made by
    // "Unknown Creator".
    $path = deckWithShapes(
        static function (AbstractSlide $slide): void {
            placeholderShape($slide, 'title', 'What broke');
        },
    );

    expect(converted($path))->not->toContain('author:');
});

it('draws the same slide again when the Markdown it came from is drawn again', function () {
    // The round trip has to be a round trip: the Markdown a deck is read back into, drawn
    // again, has to be the deck it came from. The title in the frontmatter is the first
    // slide's title, and a heading that says the same thing is not drawn a second time.
    $first = writtenDeck("# One\n\nText.\n");
    $back = converted($first);
    $second = writtenDeck($back);

    expect(slideTexts($second))->toBe(slideTexts($first))
        ->and(converted($second))->toBe($back);
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

it('refuses a picture larger than a picture is allowed to be', function () {
    // A picture is allocated by the canvas its header declares rather than by
    // the bytes it is made of, and the memory that takes is outside the limit
    // PHP enforces, so the process is killed rather than the deck refused.
    $path = deckWithPicture(bombPicture());

    expect(fn () => converted($path, new Options(mediaDirectory: null)))
        ->toThrow(UnreadableDeck::class, '40000 x 40000 pixels');
});

it('refuses a list that nests deeper than a list may nest', function () {
    // Reading a list is a walk that recurses once per level, and the level
    // comes from the deck, so without a ceiling the walk runs out of stack and
    // the process dies on a signal.
    $path = deckWithNestedList(Options::MAX_NESTING + 1);

    expect(fn () => converted($path, new Options(mediaDirectory: null)))
        ->toThrow(UnreadableDeck::class, 'more than the 32 allowed');
});

it('refuses a list that nests deeper than a list may nest, when it is written', function () {
    // The writer walks the same tree the reader built, and it is public API:
    // a caller holding a tree of blocks can write it without the reader having
    // seen it, so the ceiling is checked on this side too.
    $nested = Block::paragraph([]);

    for ($level = 0; $level <= Options::MAX_NESTING + 1; $level++) {
        $nested = Block::list([Block::item([$nested])]);
    }

    $writer = new DeckWriter(new Options(mediaDirectory: null));

    expect(fn () => $writer->write([new Slide(1, null, [$nested], null)], new DocumentProperties()))
        ->toThrow(UnreadableDeck::class, 'more than the 32 allowed');
});

it('reads a picture that is not one, and leaves it out', function () {
    // A deck may hold a media part that is not a raster — a vector, a sound —
    // and the size of a picture cannot be read out of one.
    $path = deckWithPicture('not a picture at all');

    expect(converted($path, new Options(mediaDirectory: null)))->toContain('A picture');
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

it('carries every option through every method that changes one', function () {
    // The test above names the options one by one, so a fifth option added to
    // the class would be dropped by every `with*()` and the suite would still
    // be green. This one reads the class instead.
    $properties = (new ReflectionClass(Options::class))->getProperties();
    $names = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        $properties,
    );

    $options = new Options();

    foreach ($names as $name) {
        $value = $options->{$name};

        foreach ($names as $other) {
            $changed = new Options();

            foreach ($names as $set) {
                $changed = match ($set) {
                    $name => $changed->{'with' . ucfirst($name)}($value),
                    default => $changed,
                };
            }

            expect($changed->{$other})->toBe($options->{$other});
        }
    }
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

it('writes a free shape back as the free shape it is, with its box and its colour', function () {
    // The inverse of the renderer: a shape the deck placed itself comes back as the
    // directive that draws it, with the box it was given and the colour it was painted.
    $path = writtenDeck(
        "# One\n\nText.\n\n::roundrect{left=10% top=40% width=30% height=20% fill=accent1}\n",
    );

    $markdown = (new PowerPointToMarkdown($path, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    expect($markdown)->toContain('::roundrect{left=10% top=40% width=30% height=20% fill=4472C4}');
});

it('writes a free text block back with the words it holds', function () {
    $path = writtenDeck(
        "# One\n\nText.\n\n:::content{left=50% top=40% width=30% height=20%}\nWords.\n:::\n",
    );

    $markdown = (new PowerPointToMarkdown($path, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    // The block's own type travels with it, because it is an attribute of the directive
    // rather than of the words in it.
    expect($markdown)->toContain(
        ':::content{left=50% top=40% width=30% height=20% color=000000 font-size=16}'
        . "\n" . 'Words.' . "\n" . ':::',
    );
});

it('writes a shape that runs off the edge as one that bleeds', function () {
    // A shape off the edge is refused by the renderer unless it says so, so a shape that
    // was off the edge has to say so when it comes back.
    $path = writtenDeck(
        "# One\n\nText.\n\n::roundrect{left=90% top=40% width=30% height=20% fill=accent1 bleed=true}\n",
    );

    $markdown = (new PowerPointToMarkdown($path, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    expect($markdown)->toContain('bleed=true');
});

it('draws the same deck again from the Markdown it reads one into', function () {
    // The claim the whole of it is for: a deck taken apart and put back together is the
    // deck it was.
    $path = writtenDeck(
        "# One\n\nText.\n\n::roundrect{left=10% top=40% width=30% height=20% fill=accent1}\n",
    );

    $markdown = (new PowerPointToMarkdown($path, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    $again = Scratch::file('again.pptx', '');

    (new PresentationRenderer())->render((new DeckParser())->parse($markdown), $again);

    $shape = static function (string $path): ?array {
        $zip = new ZipArchive();
        $zip->open($path);
        $slide = (string) $zip->getFromName('ppt/slides/slide1.xml');
        $zip->close();

        if (preg_match('#name="roundrect".*?<a:off x="(-?\d+)" y="(-?\d+)"/>.*?<a:ext cx="(\d+)" cy="(\d+)"#s', $slide, $found) !== 1) {
            return null;
        }

        return [(int) $found[1], (int) $found[2], (int) $found[3], (int) $found[4]];
    };

    expect($shape($again))->toBe($shape($path));
});

it('reads a deck whose theme part names nothing', function () {
    // The name is whatever the writer put there. A deck that names no theme has no name to
    // write back, and a deck that names something else is not one this library wrote.
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->createText('Text');
    });

    $zip = new ZipArchive();
    $zip->open($path);
    $theme = (string) $zip->getFromName('ppt/theme/theme1.xml');
    $zip->close();

    expect($theme)->toContain('<a:theme')
        ->and(converted($path))->not->toContain('theme:');
});

it('writes a rule back with the colour it was drawn in', function () {
    // A rule is a stroke and not a fill, and a rule that comes back with no colour is a rule
    // nobody can see.
    $markdown = (new PowerPointToMarkdown(writtenDeck(
        "# One\n\nText.\n\n::line{left=6% top=50% width=88% height=0.3% stroke=accent1 stroke-width=2}\n",
    ), new Options(mediaDirectory: null, preserveGeometry: true)))->convert();

    expect($markdown)->toContain('::line{')
        ->and($markdown)->toContain('stroke=4472C4')
        ->and($markdown)->toContain('stroke-width=2.25');
});

it('writes no stroke for a shape that was drawn without one', function () {
    // A border is born black, and a reader cannot tell a border that was never given a
    // colour from one that was given black.
    $markdown = (new PowerPointToMarkdown(writtenDeck(
        "# One\n\nText.\n\n::rectangle{left=6% top=40% width=88% height=30% fill=panel}\n",
    ), new Options(mediaDirectory: null, preserveGeometry: true)))->convert();

    expect($markdown)->toContain('::rectangle{')
        ->and($markdown)->not->toContain('stroke=');
});

it('writes the alignment of a free text block back in the middle of it', function () {
    foreach (['align=right' => 'align=right', 'align=justify' => 'align=justify'] as $written => $expected) {
        $markdown = (new PowerPointToMarkdown(writtenDeck(
            "# One\n\nText.\n\n:::content{left=6% top=40% width=88% height=30% " . $written . "}\nWords.\n:::\n",
        ), new Options(mediaDirectory: null, preserveGeometry: true)))->convert();

        expect($markdown)->toContain($expected);
    }
});

it('writes a free text block back at the bottom of its box', function () {
    $markdown = (new PowerPointToMarkdown(writtenDeck(
        "# One\n\nText.\n\n:::content{left=6% top=40% width=88% height=30% vertical-align=bottom}\nWords.\n:::\n",
    ), new Options(mediaDirectory: null, preserveGeometry: true)))->convert();

    expect($markdown)->toContain('vertical-align=bottom');
});

it('writes the stroke of a shape the reader gives as an autoshape', function () {
    // An autoshape's outline says whether it has a stroke at all, so a shape that was drawn
    // without one comes back without one.
    $markdown = (new PowerPointToMarkdown(writtenDeck(
        "# One\n\nText.\n\n::roundrect{left=6% top=40% width=88% height=30% fill=panel stroke=accent1 stroke-width=2}\n",
    ), new Options(mediaDirectory: null, preserveGeometry: true)))->convert();

    expect($markdown)->toContain('::roundrect{')
        ->and($markdown)->toContain('stroke=4472C4')
        ->and($markdown)->toContain('stroke-width=2.25');
});

it('writes the alignment of a free text block back', function () {
    $markdown = (new PowerPointToMarkdown(writtenDeck(
        "# One\n\nText.\n\n:::content{left=6% top=40% width=88% height=30% align=center vertical-align=middle}\nWords.\n:::\n",
    ), new Options(mediaDirectory: null, preserveGeometry: true)))->convert();

    expect($markdown)->toContain('align=center')
        ->and($markdown)->toContain('vertical-align=middle');
});

it('writes a shape with no fill without one, and a turned shape with its turn', function () {
    // A shape with no fill is drawn with none, and a shape turned is drawn turned. Both
    // are things the deck asked for, so both travel.
    $markdown = (new PowerPointToMarkdown(writtenDeck(
        "# One\n\nText.\n\n::rectangle{left=10% top=40% width=30% height=20% rotate=45}\n",
    ), new Options(mediaDirectory: null, preserveGeometry: true)))->convert();

    expect($markdown)->toContain('rotate=45')
        ->and($markdown)->not->toContain('fill=');
});

it('writes the words an ink shape holds as its label', function () {
    $markdown = (new PowerPointToMarkdown(writtenDeck(
        "# One\n\nText.\n\n::rectangle{left=10% top=40% width=30% height=20% label=\"A word\"}\n",
    ), new Options(mediaDirectory: null, preserveGeometry: true)))->convert();

    expect($markdown)->toContain('label="A word"');
});

it('leaves a shape it does not draw out, because it cannot draw it back', function () {
    // A star is a shape PowerPoint draws and this library does not, so there is no
    // directive to write it back as.
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createAutoShape(AutoShape::TYPE_5_POINT_STAR);
        $shape->setOffsetX(10);
        $shape->setOffsetY(10);
        $shape->setWidth(100);
        $shape->setHeight(100);
    });

    $markdown = (new PowerPointToMarkdown($path, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    expect($markdown)->not->toContain('::');
});

it('leaves a placeholder out, because the layout put it there', function () {
    // A placeholder is the layout's own, and the layout is not in the deck.
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('content');
        $shape->createText('Words');
        $shape->setPlaceHolder(new Placeholder('body'));
    });

    $markdown = (new PowerPointToMarkdown($path, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    expect($markdown)->not->toContain('::content');
});

it('writes no label for a shape whose words are more than one paragraph', function () {
    // A label is one run of text. A shape with paragraphs in it is a body, and a body is
    // not a label.
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createRichTextShape();
        $shape->setName('rectangle');
        $shape->createText('One.');
        $shape->createParagraph()->createTextRun()->setText('Two.');
    });

    $markdown = (new PowerPointToMarkdown($path, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    expect($markdown)->toContain('::rectangle{')
        ->and($markdown)->not->toContain('label=');
});

it('writes nothing for a shape it was handed without one', function () {
    // The writer is public API: a caller can hand it a block of a kind it does not know,
    // and it writes nothing rather than a shape that is not there.
    $writer = new DeckWriter(new Options(mediaDirectory: null));
    $properties = new DocumentProperties();
    $properties->setTitle('');
    $properties->setCreator('');

    expect($writer->write([new Slide(0, null, [new Block(Block::SHAPE)], null)], $properties))
        ->not->toContain('::');
});

it('brings the free shapes of a gallery deck back with the same boxes and the same paint', function () {
    // The gallery's own deck and not one the test wrote: the claim is about the decks the
    // library ships, and a deck a test made is a deck nobody else has.
    $source = dirname(__DIR__, 2) . '/examples/markdown/12-free-shapes.md';

    $first = Scratch::file('gallery.pptx', '');
    $second = Scratch::file('gallery-again.pptx', '');

    $renderer = new PresentationRenderer(dirname(__DIR__, 2) . '/examples/markdown');
    $renderer->render((new DeckParser())->parse((string) file_get_contents($source)), $first);

    $markdown = (new PowerPointToMarkdown($first, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    (new PresentationRenderer(dirname(__DIR__, 2) . '/examples/markdown'))->render(
        (new DeckParser())->parse($markdown),
        $second,
    );

    $before = freeShapesOf($first);
    $after = freeShapesOf($second);

    expect($before)->not->toBe([]);

    foreach ($before as $slide => $shapes) {
        foreach ($shapes as $name => $shape) {
            // A picture placed by the deck comes back as a picture — its bytes and its alt
            // text — and not as a placed picture, because where a picture sits is part of the
            // design and not part of the picture.
            if ($name === ShapeKind::Image->value) {
                expect($after[$slide][$name] ?? null)->toBeNull();

                continue;
            }

            expect($after[$slide][$name] ?? null)->toBe($shape, sprintf(
                'The "%s" of slide %d came back differently.',
                $name,
                $slide + 1,
            ));
        }
    }
});

it('reads a shape of another program by its geometry', function () {
    // A deck written by another program has names of its own, and its geometry is what says
    // what it drew.
    $path = deckWithShapes(static function (AbstractSlide $slide): void {
        $shape = $slide->createAutoShape();
        $shape->setType(AutoShape::TYPE_OVAL);
        $shape->setName('A shape of somebody else');
        $shape->setOffsetX(10);
        $shape->setOffsetY(20);
        $shape->setWidth(100);
        $shape->setHeight(80);
    });

    $markdown = (new PowerPointToMarkdown($path, new Options(
        mediaDirectory: null,
        preserveGeometry: true,
    )))->convert();

    expect($markdown)->toContain('::ellipse{');
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
    // 2 and back to 1.
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
    $properties = new DocumentProperties();
    $properties->setTitle('A deck');

    $markdown = $writer->write([
        new Slide(0, null, [
            Block::paragraph([]),
            Block::list([Block::item([Block::paragraph([])])]),
            Block::table([]),
            Block::image(new Image('A dot', '', '')),
        ]),
    ], $properties);

    // The deck's own frontmatter and nothing after it: every block holds nothing, so
    // every block writes nothing.
    expect(trim(str_replace('---', '', $markdown)))->toBe('title: "A deck"');
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
