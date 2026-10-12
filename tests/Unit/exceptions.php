<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\ContentOverflow;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\FencedFrontmatter;
use MarkdownPresentation\Exception\ImageNotFound;
use MarkdownPresentation\Exception\InvalidInput;
use MarkdownPresentation\Exception\MalformedFrontmatter;
use MarkdownPresentation\Exception\MalformedTemplate;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\TooManySlides;
use MarkdownPresentation\Exception\UnknownDirective;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownMarker;
use MarkdownPresentation\Exception\UnknownStyleSlot;
use MarkdownPresentation\Exception\UnreadablePackage;
use MarkdownPresentation\Exception\UnsupportedColumn;
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Exception\UnsupportedLink;
use MarkdownPresentation\Render\NotesColourMapPass;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Doctest;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\UnreadableArchive;

/**
 * The two paths the messages below name, and the reason they are paths rather than
 * anything real.
 *
 * Every one of these failures is built by calling the constructor directly rather than by
 * provoking it, so that the suite can assert on the message whatever the machine it runs on
 * would have done. That is also why the paths cannot be the ones a render writes: a reader
 * who sees `/tmp/deck.pptx` here should not go looking for the deck that made it.
 */
const DECK_PATH = '/tmp/deck.pptx';
const LOGO_PATH = '/tmp/logo.png';

/**
 * Every failure this library reports, found by reading the directory rather than by
 * a list written here.
 *
 * A list in a test is a list that stops being true the moment somebody adds an
 * exception to it, which is the failure this file exists to prevent: an exception
 * that extends `RuntimeException` directly works perfectly and breaks the promise
 * the README makes about one `catch` covering everything.
 *
 * @return list<array{0: class-string}>
*/
function reportedExceptions(): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(Doctest::root() . '/src/Exception', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'MarkdownPresentation\\Exception\\' . $file->getBasename('.php');

        if (class_exists($class) || interface_exists($class)) {
            $found[] = $class;
        }
    }

    sort($found);

    return $found;
}

it('reports every failure under one base class', function (string $class) {
    // The base class is not a subclass of itself, so it is asked about separately.
    expect($class === Exception::class || is_subclass_of($class, Exception::class))->toBeTrue();
})->with(reportedExceptions());

it('reports what a deck author can fix under the class that says so', function (string $class) {
    // The base is for everything; the one below it is for the failures a deck author
    // can fix by naming something else.
    $authorFixable = [
        ContentOverflow::class,
        FencedFrontmatter::class,
        ImageNotFound::class,
        MalformedFrontmatter::class,
        MalformedTemplate::class,
        MalformedTheme::class,
        ThemeNotFound::class,
        TooManySlides::class,
        UnknownDirective::class,
        UnknownLayoutRole::class,
        UnknownMarker::class,
        UnknownStyleSlot::class,
        UnsupportedColumn::class,
        UnsupportedImageFormat::class,
        UnsupportedLink::class,
    ];

    // The failures that are not the deck's to fix, which is the day this file was written
    // for: a package this process cannot open is a disk or a download that went wrong, and a
    // deck author told to go and rename something would go and rename something.
    $callerSide = [UnreadablePackage::class];

    if ($class === Exception::class || $class === InvalidInput::class) {
        // Neither is a subclass of the other, so the two are asked about as they are.
        expect($class === Exception::class || is_subclass_of($class, Exception::class))->toBeTrue();

        return;
    }

    if (in_array($class, $callerSide, true)) {
        expect(is_subclass_of($class, Exception::class))->toBeTrue()
            ->and(is_subclass_of($class, InvalidInput::class))->toBeFalse();

        return;
    }

    expect($authorFixable)->toContain($class)
        ->and(is_subclass_of($class, InvalidInput::class))->toBeTrue();
})->with(reportedExceptions());

it('reports a failure under one catch, whatever it is a failure of', function () {
    // The promise the README makes, asserted rather than described: one `catch` on the base
    // covers everything this library throws, including the failures that are not the deck's.
    expect(UnreadablePackage::cannotOpen(DECK_PATH))->toBeInstanceOf(Exception::class)
        ->and(UnreadablePackage::cannotRead(DECK_PATH, 'ppt/notesSlides/notesSlide1.xml'))
        ->toBeInstanceOf(Exception::class);
});

it('throws a named type out of the support code too', function () {
    // `FixtureFailed` is there for the same reason the library's own types are: a caller
    // that wants to skip a test whose fixture could not be built has to be able to tell
    // that apart from a render that came out wrong, and `RuntimeException` says neither.
    //
    // The half of the promise nobody reads is the half that rots — the directory is only
    // walked when somebody adds a file to it — so it is walked here rather than trusted.
    $generic = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(Doctest::root() . '/tests/Support', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || !$file->isFile()) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('#new\s+\\\\?(?:Runtime|Logic)?Exception\s*\(#', $line) === 1) {
                $generic[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
            }
        }
    }

    expect($generic)->toBe([]);
});

it('refuses a package with a part it cannot read', function () {
    // The half of the promise above nobody reaches by describing it: a package that opens,
    // with one part in it this process cannot decompress. What used to happen instead is
    // that `false` became `''` under a cast and `'' === false` was not true, so the entry
    // was deleted and added back as zero bytes — a deck that opens, with a corrupt speaker
    // note and nothing anywhere saying so. What that does to the file on disk is asserted
    // beside the pass that causes it, in `tests/Unit/notes-colour-map.php`.
    //
    // The reader is one this test writes rather than a corrupted file. Whether a damaged
    // payload refuses to inflate is the build's own business: one zlib rejects a reserved
    // deflate block type and another decodes it into different bytes, and neither a wrong
    // CRC nor a compressed size past the end of the file is refused on all of them.
    $path = Scratch::file('unreadable.pptx', '');
    (new PresentationRenderer())->render(
        (new DeckParser())->parse("# One\n\nThe slide.\n\n::note\na note\n::\n"),
        $path,
    );

    // Each run gets its own archive: the pass closes the one it is given, and a second
    // `applyTo()` over a closed handle is not the same refusal.
    $refuses = function () use ($path): void {
        $archive = new UnreadableArchive('ppt/notesSlides/notesSlide1.xml');
        $archive->open($path);

        NotesColourMapPass::applyTo($path, $archive);
    };

    expect($refuses)
        ->toThrow(UnreadablePackage::class, 'so the package was left as it was found rather than rewritten with the part lost')
        ->and($refuses)->toThrow(Exception::class);
});

it('names a slide and a file in every failure, because the fix is in the message', function () {
    // A message that says what is wrong but not where leaves the author hunting, and
    // hunting is what the loud failure was for.
    expect(FencedFrontmatter::at(3)->getMessage())->toContain('Slide 3')
        ->and(MalformedFrontmatter::wrongType(4, 'title', 'a string')->getMessage())->toContain('slide 4')
        ->and(UnknownDirective::comment(5, '<!-- v-if -->')->getMessage())->toContain('Slide 5')
        ->and(UnknownDirective::element(6, '<v-click>')->getMessage())->toContain('Slide 6')
        ->and(UnknownDirective::container(7, ':::')->getMessage())->toContain('Slide 7')
        ->and(MalformedTheme::unknownKey('themes/acme/theme.yml', 'colour', ['name'])->getMessage())
        ->toContain('themes/acme/theme.yml')
        ->and(ImageNotFound::at(2, 'logo.png', LOGO_PATH)->getMessage())
        ->toContain(LOGO_PATH)
        ->and(ContentOverflow::on(3, 2, 0, 'code', 1150, 396)->getMessage())
        ->toContain('Slide 3')
        ->and(ContentOverflow::on(3, 2, 0, 'code', 1150, 396)->getMessage())->toContain('code')
        ->and(UnsupportedColumn::beyond(4, 'content', 1)->getMessage())->toContain('Slide 4');
});

it('names the alternatives wherever a failure is a name the author can change', function (string $message, string $alternative) {
    // The point of refusing rather than ignoring: the message is the fix. A refusal that
    // says only what is wrong leaves the author to look up what is right, which is the
    // search the loud failure was supposed to save.
    expect($message)->toContain($alternative);
})->with([
    'a directive nobody reads' => [
        UnknownDirective::named(3, 'transition', ['title', 'name', 'level', 'layout', 'theme', 'author', 'description'])->getMessage(),
        'The directives are: title, name, level, layout, theme, author, description.',
    ],
    'a deck directive on a later slide' => [
        UnknownDirective::misplaced(4, 'theme')->getMessage(),
        'belongs in the frontmatter of slide 1',
    ],
    'a comment that is neither a step nor a note' => [
        UnknownDirective::comment(3, '<!-- v-if false -->')->getMessage(),
        'A step is "::click" on a line of its own',
    ],
    'an element a slide cannot draw' => [
        UnknownDirective::element(2, '<v-click>')->getMessage(),
        'which is not something a slide draws',
    ],
    'a container fence a slide cannot draw' => [
        UnknownDirective::container(4, '::: note')->getMessage(),
        'Three colons open a step region as ":::click"',
    ],
    'a note nothing closes' => [
        UnknownDirective::unclosedNote(3)->getMessage(),
        'A note is "::note" on a line of its own',
    ],
    'a region nothing closes' => [
        UnknownMarker::unclosed(2, ':::click')->getMessage(),
        'A region ends at a ":::" on a line of its own',
    ],
    'a closer with nothing to close' => [
        UnknownMarker::strayCloser(1)->getMessage(),
        'A region opens at ":::click"',
    ],
    'a step that goes backwards' => [
        UnknownMarker::backwards(2, 1, 3)->getMessage(),
        'Steps run forwards',
    ],
    'a step larger than a slide could carry' => [
        UnknownMarker::tooLarge(2, '2147483647', 500)->getMessage(),
        'name one up to 500',
    ],
    'a deck of more slides than a presentation carries' => [
        TooManySlides::in(1200, 1000)->getMessage(),
        'one presentation may carry 1000',
    ],
    'a step that is not a number' => [
        UnknownMarker::notANumber(2, 'two')->getMessage(),
        'which is not a number',
    ],
    'an equals with nothing after it' => [
        UnknownMarker::empty(2, '::click=')->getMessage(),
        'with no number',
    ],
    'a column the layout has none to move a block into' => [
        UnsupportedColumn::beyond(3, 'content', 1)->getMessage(),
        'Slide 3 has a "::column", and the "content" layout has 1 column. '
        . '"::column" moves the blocks after it into the next column, so a slide that uses one '
        . 'needs a layout with more columns than this.',
    ],
    'an image that is not a file' => [
        ImageNotFound::at(1, 'logo.png', LOGO_PATH)->getMessage(),
        'which is not a file',
    ],
    'an image no package can carry and nothing can decode' => [
        UnsupportedImageFormat::undecodable(3, 'chart.tiff', 'tiff')->getMessage(),
        'Convert it to GIF, JPEG, PNG or SVG first',
    ],
    'a package this process cannot open at all' => [
        UnreadablePackage::cannotOpen(DECK_PATH)->getMessage(),
        'It is not a package this PHP can read, or the process cannot write it.',
    ],
    'a link target that is a scheme a reader would act on' => [
        UnsupportedLink::target('javascript:alert(1)')->getMessage(),
        'Write it as "https://…", "http://…" or "mailto:…"',
    ],
    'a file that is not an image at all' => [
        UnsupportedImageFormat::notAnImage(1, 'diagram.pdf')->getMessage(),
        'cannot read as an image at all',
    ],
]);
