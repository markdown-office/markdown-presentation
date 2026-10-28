<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Document\Slide;
use MarkdownPresentation\Exception\Exception as MarkdownException;
use MarkdownPresentation\Exception\MalformedChrome;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\UnknownFont;
use MarkdownPresentation\Exception\UnknownTemplate;
use MarkdownPresentation\Render\Furniture;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Template\ChromeFile;

/**
 * Slide templates: a piece of furniture written once and drawn on every slide that names it.
 *
 * The deck this came from wrote the same wordmark and the same rule on all twenty-eight of
 * its slides, and the repetition was the fault: change the wordmark and twenty-eight slides
 * had to be found and changed. A template is that furniture written once.
 *
 * Split into the three things that have to agree about it — what a definition is, which
 * slides are slides, and what a slide that names a template gets — because each of them is
 * wrong on its own in a way the others do not catch.
 */

/** A deck with a template definition in it and two slides that name it. */
const WITH_TEMPLATE = <<<'MARKDOWN'
    ---
    template: brand
    ---
    ::line{left=5% top=72.889% width=30% height=0.185%}

    ---
    layout: blank
    template: brand
    ---

    ---
    layout: blank
    template: brand
    ---
    MARKDOWN;

it('does not count a template definition as a slide of the deck', function () {
    // The whole of what a definition is: it is furniture, it is not a slide, and it does
    // not have a number. A deck that counted it would be a deck whose third slide is the
    // page number of the second.
    $deck = (new DeckParser())->parse(WITH_TEMPLATE);

    expect($deck->count())->toBe(2)
        ->and($deck->slides())->toHaveCount(2)
        ->and($deck->definitions())->toHaveCount(1);
});

it('numbers the slides of a deck over the slides and not over the definitions', function () {
    // The numbering is the deck's own: a definition takes no number and the slide after it
    // carries on as though the definition were not there.
    $deck = (new DeckParser())->parse(WITH_TEMPLATE);

    expect(array_map(static fn (Slide $slide): int => $slide->index(), $deck->slides()))->toBe([0, 1]);
});

it('reads a template definition as the template it defines', function () {
    $deck = (new DeckParser())->parse(WITH_TEMPLATE);
    $definition = $deck->definitions()[0];

    expect($definition->name())->toBe('brand')
        ->and($definition->body())->toContain('::line{');
});

it('reads a slide that names a template with another directive as a slide', function () {
    // A slide that names a template and says nothing else defines one; a slide that names
    // it along with the layout it is drawn in draws it.
    $deck = (new DeckParser())->parse(WITH_TEMPLATE);

    expect($deck->slides()[0]->frontmatter())->toBe(['layout' => 'blank', 'template' => 'brand']);
});

it('refuses a template definition whose name is not a name', function () {
    expect(fn () => (new DeckParser())->parse("---\ntemplate: [a, b]\n---\n"))
        ->toThrow(MalformedFrontmatter::class, 'A template definition names the template it defines');
});

it('reads the deck frontmatter out of the first slide that is one', function () {
    // A deck that opens with a definition has no frontmatter of its own until the first
    // slide that is one, and the theme it names is the deck's theme.
    $deck = (new DeckParser())->parse("---\ntemplate: brand\n---\n::line{}\n\n---\ntitle: Deck\n---\n\n# One\n");

    expect($deck->frontmatter())->toBe(['title' => 'Deck'])
        ->and($deck->count())->toBe(1);
});

/**
 * A deck with a template that draws a wordmark and a rule, and two slides that name it.
 */
const WITH_CHROME = <<<'MARKDOWN'
    ---
    template: brand
    ---
    :::content{left=4.5% top=3.913% width=29.996% height=4.969% fill=none padding=0 color=FFFFFF font-size=11 font-family=Calibri align=left vertical-align=middle}
    Resilience
    :::

    ::line{left=5% top=72.889% width=30% height=0.185% stroke=00B4D8 stroke-width=1}

    ---
    layout: blank
    template: brand
    ---

    ---
    layout: blank
    template: brand
    ---
    MARKDOWN;

/** A rendered deck, opened for reading its parts. */
function templateDeck(string $markdown): Pptx
{
    $path = Scratch::file('template.pptx', '');

    (new PresentationRenderer(directory: Scratch::directory()))
        ->render((new DeckParser())->parse($markdown), $path);

    return Pptx::open($path);
}

/** A rendered deck that failed, and the message it failed with. */
function templateFailure(string $markdown): string
{
    try {
        templateDeck($markdown);
    } catch (MarkdownException $failure) {
        return $failure->getMessage();
    }

    return '';
}

/**
 * A rendered deck and the renderer that rendered it.
 *
 * The renderer rather than the file, because the claims about the faces it could not
 * measure are claims about its report.
 */
function templateRenderer(string $markdown, bool $strict = false): PresentationRenderer
{
    $renderer = new PresentationRenderer(directory: Scratch::directory(), strict: $strict);
    $renderer->render((new DeckParser())->parse($markdown), Scratch::file('template.pptx', ''));

    return $renderer;
}

it('draws the chrome of a template on every slide that names it', function () {
    // The whole of what a template is: the wordmark and the rule are written once and are
    // on every slide that names them.
    $file = templateDeck(WITH_CHROME);

    expect($file->shapeNames(1))->toBe(['content', 'line'])
        ->and($file->shapeNames(2))->toBe(['content', 'line'])
        ->and($file->slideText(1))->toContain('Resilience');
});

it('draws the chrome of two templates on a slide that names both', function () {
    $deck = <<<'MARKDOWN'
        ---
        template: brand
        ---
        :::content{left=4.5% top=3.913% width=29.996% height=4.969% fill=none padding=0 color=FFFFFF font-size=11 font-family=Calibri align=left vertical-align=middle}
        Resilience
        :::

        ---
        template: page
        ---
        ::line{left=5% top=72.889% width=30% height=0.185% stroke=00B4D8 stroke-width=1}

        ---
        layout: blank
        template: [brand, page]
        ---
        MARKDOWN;

    $file = templateDeck($deck);

    expect($file->shapeNames(1))->toBe(['content', 'line']);
});

it('keeps the layout of a slide and the chrome of its template apart', function () {
    // A blank layout draws no furniture of its own, so the furniture on the slide is the
    // template's and the template's alone; a content layout draws its title as well, and
    // the template is on top of it rather than instead of it.
    $deck = <<<'MARKDOWN'
        ---
        template: brand
        ---
        :::content{left=4.5% top=3.913% width=29.996% height=4.969% fill=none padding=0 color=FFFFFF font-size=11 font-family=Calibri align=left vertical-align=middle}
        Resilience
        :::

        ---
        layout: blank
        template: brand
        ---

        ---
        layout: content
        template: brand
        ---
        # A title
        MARKDOWN;

    $file = templateDeck($deck);

    expect($file->shapeNames(1))->toBe(['content'])
        ->and($file->shapeNames(2))->toBe(['title', 'content']);
});

it('refuses a template whose body holds ordinary Markdown', function () {
    // A template is drawn on every slide that names it, so the words of a slide written
    // into one would be on every one of them.
    $deck = "---\ntemplate: brand\n---\n# A heading\n\n---\nlayout: blank\ntemplate: brand\n---\n";

    expect(templateFailure($deck))
        ->toContain('The template "brand" holds a heading')
        ->toContain('A template holds chrome, not content');
});

it('refuses a template whose body holds ordinary Markdown, and says what it found', function (string $body, string $what) {
    $deck = "---\ntemplate: brand\n---\n" . $body . "\n\n---\nlayout: blank\ntemplate: brand\n---\n";

    expect(templateFailure($deck))
        ->toContain(sprintf('The template "brand" holds %s', $what));
})->with([
    'a heading' => ["# A heading\n", 'a heading'],
    'a paragraph' => ["A paragraph.\n", 'a paragraph'],
    'a list' => ["- One\n- Two\n", 'a list'],
    'a quotation' => ["> A quotation.\n", 'a quotation'],
    'a code block' => ["```php\n\$a = 1;\n```\n", 'a code block'],
    'a table' => ["| a | b |\n| --- | --- |\n| 1 | 2 |\n", 'a table'],
    'a rule' => ["***\n", 'a rule'],
]);

it('refuses a template whose body holds a picture', function () {
    // The picture is a file the deck names, so it has to be there before the refusal:
    // a picture that is not a file is a different failure, and a louder one.
    $picture = Scratch::file('logo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    ));

    $deck = sprintf(
        "---\ntemplate: brand\n---\n![a picture](%s)\n\n---\nlayout: blank\ntemplate: brand\n---\n",
        $picture,
    );

    expect(templateFailure($deck))->toContain('The template "brand" holds a picture');
});

it('refuses a template whose body holds a column marker', function () {
    // A column is where a block of a slide is drawn, and a template draws no blocks.
    $deck = "---\ntemplate: brand\n---\n::column\n::line{}\n\n---\nlayout: blank\ntemplate: brand\n---\n";

    expect(templateFailure($deck))
        ->toContain('The template "brand" holds a column marker');
});

it('refuses a template that draws nothing', function () {
    $deck = "---\ntemplate: brand\n---\n\n---\nlayout: blank\ntemplate: brand\n---\n";

    expect(templateFailure($deck))
        ->toContain('The template "brand" draws nothing');
});

it('refuses a template that cannot be drawn, and names it', function () {
    $deck = "---\ntemplate: brand\n---\n::rectangle{colour=red}\n\n---\nlayout: blank\ntemplate: brand\n---\n";

    expect(templateFailure($deck))
        ->toContain('The template "brand" cannot be drawn')
        ->toContain('which it does not read');
});

it('draws the chrome of a template over the shapes the slide placed itself', function () {
    // A full-bleed picture is behind the wordmark and not over it: the furniture belongs to
    // the page, and a picture the deck placed is on the page.
    $deck = <<<'MARKDOWN'
        ---
        template: brand
        ---
        :::content{left=4.5% top=3.913% width=29.996% height=4.969% fill=none padding=0 color=FFFFFF font-size=11 font-family=Calibri align=left vertical-align=middle}
        Resilience
        :::

        ---
        layout: blank
        template: brand
        ---
        ::rectangle{left=0% top=0% width=100% height=100% fill=panel}
        MARKDOWN;

    $file = templateDeck($deck);

    expect($file->shapeNames(1))->toBe(['rectangle', 'content']);
});

it('refuses a slide that names a template there is not, and names the templates there are', function () {
    // The slide is the deck's first slide, because the definition above it is not one:
    // the number in the message is the number the slide is drawn under.
    $deck = "---\ntemplate: brand\n---\n::line{}\n\n---\nlayout: blank\ntemplate: wordmark\n---\n";

    expect(templateFailure($deck))
        ->toContain('Slide 1 names the template "wordmark", which is not one')
        ->toContain('The templates are: brand');
});

it('refuses a slide that carries more shapes than one may', function () {
    $chrome = str_repeat("::line{left=5% top=10% width=30% height=1%}\n", Furniture::MAX_SHAPES + 1);

    $deck = "---\ntemplate: brand\n---\n" . $chrome . "\n---\nlayout: blank\ntemplate: brand\n---\n";

    expect(templateFailure($deck))
        ->toContain(sprintf(
            'Slide 1 carries %d shapes, and a slide may carry at most %d',
            Furniture::MAX_SHAPES + 1,
            Furniture::MAX_SHAPES,
        ));
});

it('draws a slide that carries as many shapes as one may', function () {
    // The other side of the ceiling: a slide at it is drawn rather than refused, because a
    // ceiling that refused the shapes it allows is a ceiling nobody could reach.
    $chrome = str_repeat("::line{left=5% top=10% width=30% height=1%}\n", Furniture::MAX_SHAPES);

    $deck = "---\ntemplate: brand\n---\n" . $chrome . "\n---\nlayout: blank\ntemplate: brand\n---\n";

    expect(templateDeck($deck)->shapeNames(1))->toHaveCount(Furniture::MAX_SHAPES);
});

it('pays for a picture in a template once, however many slides draw it', function () {
    $picture = Scratch::file('logo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    ));

    $deck = sprintf(
        "---\ntemplate: brand\n---\n::image{src=\"%s\" left=0 top=0 width=10%% height=10%%}\n\n"
        . "---\nlayout: blank\ntemplate: brand\n---\n\n"
        . "---\nlayout: blank\ntemplate: brand\n---\n",
        basename($picture),
    );

    $file = templateDeck($deck);

    $media = array_values(array_filter(
        $file->parts(),
        static fn (string $part): bool => str_starts_with($part, 'ppt/media/'),
    ));

    expect($media)->toHaveCount(1)
        ->and($file->shapeNames(1))->toBe(['image'])
        ->and($file->shapeNames(2))->toBe(['image']);
});

it('draws a picture the deck placed on two slides as two pictures', function () {
    // Two of them are two pictures in the package, each of them in the box it was given.
    $picture = Scratch::file('logo.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    ));

    $deck = sprintf(
        "---\nlayout: blank\n---\n::image{src=\"%s\" left=0 top=0 width=10%% height=10%%}\n\n"
        . "---\nlayout: blank\n---\n::image{src=\"%s\" left=50%% top=50%% width=20%% height=20%%}\n",
        basename($picture),
        basename($picture),
    );

    $file = templateDeck($deck);

    $media = array_values(array_filter(
        $file->parts(),
        static fn (string $part): bool => str_starts_with($part, 'ppt/media/'),
    ));

    expect($media)->toHaveCount(2)
        ->and($file->positionOf('image', 1))->not->toBe($file->positionOf('image', 2));
});

it('imports the templates of a chrome file beside the deck', function () {
    $chrome = Scratch::file('chrome.md', "---\ntemplate: brand\n---\n::line{left=5% top=72.889% width=30% height=0.185%}\n");

    $deck = sprintf("---\nchrome: %s\nlayout: blank\n---\n\n---\nlayout: blank\ntemplate: brand\n---\n", $chrome);

    $file = templateDeck($deck);

    expect($file->shapeNames(2))->toBe(['line']);
});

it('lets the deck\'s own template override an imported one of the same name', function () {
    // The same rule the theme resolver applies by search order: the deck's own wins.
    $chrome = Scratch::file('chrome.md', "---\ntemplate: brand\n---\n::line{left=5% top=72.889% width=30% height=0.185%}\n");

    $deck = sprintf(
        "---\nchrome: %s\nlayout: blank\n---\n\n---\ntemplate: brand\n---\n:::content{left=5%% top=5%% width=30%% height=5%%}\nDeck\n:::\n\n---\nlayout: blank\ntemplate: brand\n---\n",
        $chrome,
    );

    $file = templateDeck($deck);

    expect($file->shapeNames(2))->toBe(['content']);
});

it('refuses a chrome file that is not there', function () {
    $deck = "---\nchrome: ./nowhere.md\nlayout: blank\n---\n";

    expect(templateFailure($deck))->toContain('There is no chrome file at');
});

it('refuses a chrome file that is a URL', function () {
    $deck = "---\nchrome: phar://chrome.md\nlayout: blank\n---\n";

    expect(templateFailure($deck))->toContain('is a URL rather than a path');
});

it('refuses a chrome file that is larger than the library will read', function () {
    $chrome = Scratch::file('chrome.md', str_repeat('a', ChromeFile::MAX_BYTES + 1));

    $deck = sprintf("---\nchrome: %s\nlayout: blank\n---\n", $chrome);

    expect(templateFailure($deck))
        ->toContain(sprintf(
            'is %d bytes, and a chrome file may be at most %d',
            ChromeFile::MAX_BYTES + 1,
            ChromeFile::MAX_BYTES,
        ));
});

it('refuses a chrome file this render may not read', function () {
    $chrome = Scratch::file('chrome.md', "---\ntemplate: brand\n---\n::line{}\n");
    $outside = sys_get_temp_dir() . '/chrome-' . bin2hex(random_bytes(6)) . '.md';

    file_put_contents($outside, "---\ntemplate: brand\n---\n::line{}\n");

    $deck = sprintf("---\nchrome: %s\nlayout: blank\n---\n", $outside);

    expect(templateFailure($deck))->toContain('rather than one this render may read');

    unlink($outside);
});

it('refuses a chrome file that holds a slide', function () {
    $chrome = Scratch::file('chrome.md', "# A slide\n");

    $deck = sprintf("---\nchrome: %s\nlayout: blank\n---\n", $chrome);

    expect(templateFailure($deck))->toContain('holds a slide that is not a template definition');
});

it('refuses a chrome file it cannot read', function () {
    skipWithoutPermissions('The unreadable file');

    $chrome = Scratch::file('chrome.md', "---\ntemplate: brand\n---\n::line{}\n");

    chmod($chrome, 0o000);

    $deck = sprintf("---\nchrome: %s\nlayout: blank\n---\n", $chrome);

    expect(templateFailure($deck))->toContain('There is no chrome file at');
});

it('refuses a chrome file named on a later slide', function () {
    $deck = "---\nlayout: blank\n---\n\n---\nchrome: ./chrome.md\n---\n";

    expect(templateFailure($deck))
        ->toContain('The frontmatter of slide 2 names "chrome", which is a deck directive');
});

it('refuses a chrome file that is not a path', function () {
    $deck = "---\nchrome: 42\nlayout: blank\n---\n";

    expect(templateFailure($deck))
        ->toContain('The frontmatter of slide 1 has a "chrome" that is not a path to a file of chrome');
});

it('refuses a template that is named by something other than a name', function () {
    $deck = "---\nlayout: blank\ntemplate: 42\n---\n";

    expect(templateFailure($deck))
        ->toContain('The frontmatter of slide 1 has a "template" that is not a template name or a list of them');
});

it('refuses a list of templates that names one that is not a name', function () {
    $deck = "---\nlayout: blank\ntemplate: [brand, 42]\n---\n";

    expect(templateFailure($deck))
        ->toContain('The frontmatter of slide 1 has a "template" that is not a template name or a list of them');
});

it('reads the chrome of a template no slide draws', function () {
    // A template nothing draws is still a template, and a mistake in it is still a mistake:
    // it is read against the first slide of the deck, because there is no other to name.
    $deck = "---\ntemplate: brand\n---\n::rectangle{colour=red}\n\n---\nlayout: blank\n---\n";

    expect(templateFailure($deck))
        ->toContain('The template "brand" cannot be drawn');
});

/**
 * The page number a template draws, written the way a deck would write it.
 */
const PAGE_NUMBER = '::slide-number{left=94% top=92% width=5% height=6% color=94A3B8 font-size=10 font-family=Calibri align=right vertical-align=middle}';

it('writes the number of the slide on the slide number', function () {
    $deck = "---\ntemplate: page\n---\n" . PAGE_NUMBER . "\n\n---\nlayout: blank\ntemplate: page\n---\n\n---\nlayout: blank\ntemplate: page\n---\n";

    $file = templateDeck($deck);

    expect($file->shapeNames(1))->toBe(['slide-number'])
        ->and($file->slideText(1))->toBe('1')
        ->and($file->slideText(2))->toBe('2');
});

it('writes the number of the deck after the number of the slide', function () {
    // "2 / 3": the count is the deck's slides, and a template definition is not one.
    $deck = "---\ntemplate: page\n---\n::slide-number{left=94% top=92% width=5% height=6% of=true}\n\n---\nlayout: blank\ntemplate: page\n---\n\n---\nlayout: blank\ntemplate: page\n---\n";

    $file = templateDeck($deck);

    expect($file->slideText(1))->toBe('1 / 2')
        ->and($file->slideText(2))->toBe('2 / 2');
});

it('agrees about which slides are slides, between the count, the report and the page number', function () {
    // One definition, three slides and a block that does not fit: the report names the
    // slide the page number is on, and neither of them counts the definition.
    $tall = "```\n" . str_repeat("line;\n", 60) . "```\n";

    $deck = "---\nlayout: blank\ntemplate: page\n---\n\n---\ntemplate: page\n---\n" . PAGE_NUMBER . "\n\n---\nlayout: content\ntemplate: page\n---\n" . $tall . "\n---\nlayout: blank\ntemplate: page\n---\n";

    $renderer = new PresentationRenderer(directory: Scratch::directory());
    $path = $renderer->render((new DeckParser())->parse($deck), Scratch::file('agree.pptx', ''));

    $overflows = $renderer->overflows();
    $file = Pptx::open($path);
    $texts = $file->texts(2);

    expect($overflows)->toHaveCount(1)
        ->and($overflows[0]['slide'])->toBe(2)
        ->and($file->slideCount())->toBe(3)
        ->and($texts[count($texts) - 1])->toBe('2')
        ->and($file->slideText(3))->toBe('3');
});

it('writes the number of the slide on a slide that carries the page number itself', function () {
    // The page number is a shape like any other, and a slide that writes one gets it: the
    // number is filled in when the slide is drawn, so the shape a slide carries and the
    // shape a template carries are drawn the same way.
    $deck = "---\nlayout: blank\n---\n" . PAGE_NUMBER . "\n\n---\nlayout: blank\n---\n" . PAGE_NUMBER . "\n";

    $file = templateDeck($deck);

    expect($file->shapeNames(1))->toBe(['slide-number'])
        ->and($file->slideText(1))->toBe('1')
        ->and($file->slideText(2))->toBe('2');
});

/**
 * A template whose chrome names a face there is no table for.
 */
const WORDMARK_IN_A_FACE = "---\ntemplate: brand\n---\n"
    . ":::content{left=4.5% top=3.913% width=29.996% height=4.969% fill=none padding=0 color=FFFFFF font-size=11 font-family=\"Comic Sans MS\" align=left vertical-align=middle}\n"
    . "Resilience\n:::\n\n---\nlayout: blank\ntemplate: brand\n---\n";

it('reports a face the chrome of a template names that there is no table for', function () {
    // The chrome of a template is held apart from the shapes of the slides: it is read
    // once for the deck and drawn on every slide that names it. A face it names is drawn
    // in that face and measured with the reference face's widths, and the report is where
    // that is said.
    expect(templateRenderer(WORDMARK_IN_A_FACE)->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => 'the "content" box of the template "brand"'],
    ]);
});

it('reports a face the number of a slide names that there is no table for', function () {
    // The page number carries a style of its own, and it is the only other shape that
    // does: a walk that looked for the text a deck wrote would never see it.
    $deck = "---\ntemplate: page\n---\n::slide-number{left=94% top=92% width=5% height=6% font-family=\"Comic Sans MS\"}\n\n---\nlayout: blank\ntemplate: page\n---\n";

    expect(templateRenderer($deck)->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => 'the "slide-number" box of the template "page"'],
    ]);
});

it('reports a face the number of a slide names on a slide of the deck', function () {
    // The same shape on a slide of the deck rather than in the chrome of a template, and
    // the place is the slide it is on.
    $deck = "---\nlayout: blank\n---\n::slide-number{left=94% top=92% width=5% height=6% font-family=\"Comic Sans MS\"}\n";

    expect(templateRenderer($deck)->unknownFonts())->toBe([
        ['face' => 'Comic Sans MS', 'where' => 'the "slide-number" box on slide 1'],
    ]);
});

it('reports the face a template names once, however many slides draw it', function () {
    // The chrome is read once for the deck and drawn on every slide that names the
    // template, so the face is named in one place: in the chrome. A report of one entry
    // per slide would say the same thing twenty-eight times.
    $deck = "---\ntemplate: brand\n---\n"
        . ":::content{left=4.5% top=3.913% width=29.996% height=4.969% fill=none padding=0 color=FFFFFF font-size=11 font-family=\"Comic Sans MS\" align=left vertical-align=middle}\n"
        . "Resilience\n:::\n\n"
        . "---\nlayout: blank\ntemplate: brand\n---\n\n"
        . "---\nlayout: blank\ntemplate: brand\n---\n\n"
        . "---\nlayout: blank\ntemplate: brand\n---\n";

    expect(templateRenderer($deck)->unknownFonts())->toHaveCount(1);
});

it('refuses a deck whose template names a face it cannot measure when the renderer is strict', function () {
    // Strict is for a caller that would rather have no file than a file whose text was
    // measured with another face's widths, and the message says where the face was named.
    expect(fn () => templateRenderer(WORDMARK_IN_A_FACE, strict: true))
        ->toThrow(UnknownFont::class, 'The face "Comic Sans MS" has no table of advance widths')
        ->toThrow(UnknownFont::class, 'It is named by the "content" box of the template "brand"');
});

it('refuses a deck whose number of a slide names a face it cannot measure when the renderer is strict', function () {
    $deck = "---\ntemplate: page\n---\n::slide-number{left=94% top=92% width=5% height=6% font-family=\"Comic Sans MS\"}\n\n---\nlayout: blank\ntemplate: page\n---\n";

    expect(fn () => templateRenderer($deck, strict: true))
        ->toThrow(UnknownFont::class, 'The face "Comic Sans MS" has no table of advance widths')
        ->toThrow(UnknownFont::class, 'It is named by the "slide-number" box of the template "page"');
});
