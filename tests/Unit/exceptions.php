<?php

declare(strict_types=1);

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
use MarkdownPresentation\Exception\UnsupportedImageFormat;
use MarkdownPresentation\Tests\Support\Doctest;

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
    // can fix by naming something else. Every type in this package is one or the
    // other today, and the day one is not, this says which it is.
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
        UnsupportedImageFormat::class,
    ];

    if ($class === Exception::class || $class === InvalidInput::class) {
        // Neither is a subclass of the other, so the two are asked about as they are.
        expect($class === Exception::class || is_subclass_of($class, Exception::class))->toBeTrue();

        return;
    }

    expect($authorFixable)->toContain($class)
        ->and(is_subclass_of($class, InvalidInput::class))->toBeTrue();
})->with(reportedExceptions());

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
        ->and(ImageNotFound::at(2, 'logo.png', '/tmp/logo.png')->getMessage())
        ->toContain('/tmp/logo.png')
        ->and(ContentOverflow::on(3, 2, 0, 'code', 1150, 396)->getMessage())
        ->toContain('Slide 3')
        ->and(ContentOverflow::on(3, 2, 0, 'code', 1150, 396)->getMessage())->toContain('code');
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
        'A step is "<!-- v-click -->" on a line of its own',
    ],
    'an element a slide cannot draw' => [
        UnknownDirective::element(2, '<v-click>')->getMessage(),
        'which is not something a slide draws',
    ],
    'a container fence a slide cannot draw' => [
        UnknownDirective::container(4, '::: note')->getMessage(),
        'Divide a deck with a "---" line',
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
        UnknownMarker::empty(2)->getMessage(),
        'with no number',
    ],
    'an image that is not a file' => [
        ImageNotFound::at(1, 'logo.png', '/tmp/logo.png')->getMessage(),
        'which is not a file',
    ],
    'an image no package can carry and nothing can decode' => [
        UnsupportedImageFormat::undecodable(3, 'chart.tiff', 'tiff')->getMessage(),
        'Convert it to GIF, JPEG, PNG or SVG first',
    ],
    'a file that is not an image at all' => [
        UnsupportedImageFormat::notAnImage(1, 'diagram.pdf')->getMessage(),
        'cannot read as an image at all',
    ],
]);