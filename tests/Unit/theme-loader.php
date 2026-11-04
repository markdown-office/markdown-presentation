<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\RetiredThemeFile;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Filesystem\PathScope;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\ThemeLoader;

it('reads a theme from a directory with nothing but a theme.yml in it', function () {
    $loaded = ThemeDirectory::load(ThemeDirectory::minimal('acme'));

    expect($loaded->theme()->name())->toBe('acme')
        ->and($loaded->directory())->toEndWith('/' . basename($loaded->directory()))
        ->and($loaded->template())->toBeNull();
});

it('maps every role the library maps when the theme names no layouts', function () {
    // A theme with no `layouts:` section is drawn with the library's own mapping, which is
    // the one every theme shipping with this library carried in a file of its own.
    $loaded = ThemeDirectory::load(ThemeDirectory::minimal('acme'));

    expect($loaded->layouts())->toBe([
        'title' => 'Title Slide',
        'content' => 'Title and Content',
        'two-column' => 'Two Content',
        'section' => 'Section Header',
        'blank' => 'Blank',
        'image-text' => 'Picture Left',
        'image-title' => 'Picture with Caption',
        'image-slide' => 'Full Bleed Picture',
    ])
        ->and($loaded->roles())->toBe([
            'title', 'content', 'two-column', 'section', 'blank', 'image-text', 'image-title', 'image-slide',
        ]);
});

it('reads the layout roles a theme\'s own file carries', function () {
    $loaded = ThemeDirectory::load(ThemeDirectory::withManifest(
        "name: Acme\nlayouts:\n  title: Deck Title\n  content: Body Text\n",
    ));

    expect($loaded->layouts())->toBe(['title' => 'Deck Title', 'content' => 'Body Text'])
        ->and($loaded->roles())->toBe(['title', 'content']);
});

it('reads the named styles a theme\'s own file carries', function () {
    $loaded = ThemeDirectory::load(ThemeDirectory::withManifest(
        "name: Acme\ntextStyles:\n  brand:\n    color: accent1\n    bold: true\n",
    ));

    expect($loaded->theme()->textStyles()->names())->toBe(['brand'])
        ->and($loaded->theme()->textStyles()->get('brand')?->colour)->toBe('accent1')
        ->and($loaded->theme()->textStyles()->get('brand')?->bold)->toBeTrue();
});

it('draws a deck with the roles and the named styles a theme\'s own file carries', function () {
    // The two sections are read out of the theme's own file and reach the deck: the layout
    // part is named by the theme's mapping, and a word written in a named style is drawn
    // in it.
    $directory = ThemeDirectory::withManifest(
        "name: Acme\nlayouts:\n  content: Body Text\ntextStyles:\n  brand:\n    color: \"#00B4D8\"\n",
    );

    $deck = (new DeckParser())->parse(sprintf(
        "---\ntheme: %s\n---\n\n:::content{color=FFFFFF}\nA :brand[word]\n:::\n",
        $directory,
    ));

    $path = Scratch::file('deck.pptx', '');

    (new PresentationRenderer())->render($deck, $path);

    $pptx = Pptx::open($path);

    expect($pptx->layoutNames())->toBe([1 => 'Body Text'])
        ->and($pptx->part('ppt/slides/slide1.xml'))->toContain('<a:srgbClr val="00B4D8">');
});

it('refuses a layouts section that names a role which is not one', function () {
    ThemeDirectory::load(ThemeDirectory::withManifest("name: Acme\nlayouts:\n  two-columns: Two Content\n"));
})->throws(UnknownLayoutRole::class, '"two-columns" is not a layout role. The roles are: title, content, two-column, section, blank, image-text, image-title, image-slide');

it('refuses a layouts section that does not hold the mappings', function () {
    ThemeDirectory::load(ThemeDirectory::withManifest("name: Acme\nlayouts: Two Content\n"));
})->throws(UnknownLayoutRole::class, '"layouts" is not a mapping of roles to layout names');

it('refuses a role mapped to nothing', function () {
    ThemeDirectory::load(ThemeDirectory::withManifest("name: Acme\nlayouts:\n  title:\n"));
})->throws(UnknownLayoutRole::class, 'the "title" role is not a layout name');

it('names the roles a theme does have when the one asked for is not one of them', function () {
    // The section is the whole mapping rather than a layer over the library's own, so a
    // role it leaves out is a role the theme does not map — and the failure names the ones
    // it does, because the fix is in the message.
    $loaded = ThemeDirectory::load(ThemeDirectory::withManifest(
        "name: Acme\nlayouts:\n  title: Title Slide\n  blank: Blank\n",
    ));

    $loaded->layout('section');
})->throws(UnknownLayoutRole::class, 'The theme "Acme" maps no "section" layout. It maps: title, blank');

it('says a theme whose layouts section names no role maps none', function () {
    $loaded = ThemeDirectory::load(ThemeDirectory::withManifest("name: Acme\nlayouts: {}\n"));

    $loaded->layout('content');
})->throws(UnknownLayoutRole::class, 'The theme "Acme" maps no "content" layout. It maps: (none — its "layouts" section maps no role)');

it('refuses a layouts.yml beside a theme, and names the section to move it into', function () {
    ThemeDirectory::load(ThemeDirectory::acme([
        'layouts.yml' => "layouts:\n  title: Title Slide\n",
    ]));
})->throws(RetiredThemeFile::class, 'has a "layouts.yml" beside it. A theme is one file: move its content into the "layouts:" section of its "theme.yml", and delete the file.');

it('refuses a text-styles.yml beside a theme, and names the section to move it into', function () {
    ThemeDirectory::load(ThemeDirectory::acme([
        'text-styles.yml' => "brand:\n  color: accent1\n",
    ]));
})->throws(RetiredThemeFile::class, 'has a "text-styles.yml" beside it. A theme is one file: move its content into the "textStyles:" section of its "theme.yml", and delete the file.');

it('refuses the file beside a theme, not the content it holds', function () {
    // The message asks for two things — the content moved and the file deleted — because
    // the refusal is about the file being there: a theme that already carries the section
    // is read, and the same theme with the file beside it is refused as well.
    $manifest = "name: Acme\nlayouts:\n  title: Title Slide\n";

    $read = ThemeDirectory::load(ThemeDirectory::withManifest($manifest));

    expect($read->layouts())->toBe(['title' => 'Title Slide']);

    expect(fn () => ThemeDirectory::load(ThemeDirectory::withManifest($manifest, [
        'layouts.yml' => "layouts:\n  title: Title Slide\n",
    ])))->toThrow(RetiredThemeFile::class, 'has a "layouts.yml" beside it.');
});

it('refuses a styles.yml beside a theme, and says which half the file belongs to', function () {
    // The file is the Word half's, and this half has never drawn a slide with it: the
    // slots name Word styles, and a deck of slides has no use for them.
    ThemeDirectory::load(ThemeDirectory::acme([
        'styles.yml' => "heading.1: Heading1\n",
    ]));
})->throws(RetiredThemeFile::class, 'has a "styles.yml" beside it. That file is the Word half\'s, and this half does not read it');

it('refuses a key in the theme\'s own file that is neither a value nor a section', function () {
    ThemeDirectory::load(ThemeDirectory::withManifest("name: Acme\ncolourz:\n  accent1: \"#123456\"\n"));
})->throws(MalformedTheme::class, '"colourz" is not a key of a theme. Valid keys: name, description, author, dark, colors, fonts, style, layouts, textStyles.');

it('refuses the Word half\'s styles section, which this half does not know', function () {
    ThemeDirectory::load(ThemeDirectory::withManifest("name: Acme\nstyles:\n  heading.1: Heading1\n"));
})->throws(MalformedTheme::class, '"styles" is not a key of a theme. Valid keys: name, description, author, dark, colors, fonts, style, layouts, textStyles.');

it('refuses a textStyles section that is not a mapping of styles', function () {
    ThemeDirectory::load(ThemeDirectory::withManifest("name: Acme\ntextStyles:\n  - brand\n"));
})->throws(MalformedTheme::class, '"textStyles" is not a mapping of style names to text styles');

it('says a directory with no theme.yml is not a theme', function () {
    ThemeDirectory::load(ThemeDirectory::create(['notes.txt' => 'not a theme'], 'not-a-theme'));
})->throws(ThemeNotFound::class, 'is not a theme: it has no theme.yml');

it('reads a theme from the manifest file that describes it', function () {
    // The manifest is the theme, and the rest of the theme is read from beside it: a
    // theme is a directory, and the manifest is the one file that directory cannot do
    // without.
    $directory = ThemeDirectory::withManifest("name: Acme\nlayouts:\n  content: Title and Content\n");

    $loaded = ThemeDirectory::loadManifest($directory);

    expect($loaded->theme()->name())->toBe('Acme')
        ->and($loaded->directory())->toBe($directory)
        ->and($loaded->layouts())->toBe(['content' => 'Title and Content']);
});

it('says a file that is not a theme manifest is not one', function () {
    $directory = ThemeDirectory::create(['notes.txt' => 'not a theme'], 'not-a-manifest');

    ThemeDirectory::loadManifest($directory, 'notes.txt');
})->throws(ThemeNotFound::class, 'is not a theme: it has no theme.yml');

it('says a manifest file that is not there is not one', function () {
    $directory = ThemeDirectory::create([], 'empty');

    ThemeDirectory::loadManifest($directory);
})->throws(ThemeNotFound::class, 'is not a theme: it has no theme.yml');

it('refuses a theme.yml that is not YAML', function () {
    ThemeDirectory::load(ThemeDirectory::create(['theme.yml' => "heading.1: \"unclosed\n"]));
})->throws(Exception::class, 'is not valid YAML');

it('says a file it cannot read is a file it cannot read', function () {
    skipWithoutPermissions('The unreadable file');

    $directory = ThemeDirectory::acme();

    chmod($directory . '/theme.yml', 0o000);

    ThemeDirectory::load($directory);
})->throws(Exception::class, 'Unable to read');

it('refuses a theme.yml that is not a theme', function () {
    ThemeDirectory::load(ThemeDirectory::create(['theme.yml' => "- one\n- two\n"]));
})->throws(MalformedTheme::class, 'The file does not contain a theme — it is a list');

it('refuses a theme.yml that is a bare string rather than a mapping of keys', function () {
    ThemeDirectory::load(ThemeDirectory::create(['theme.yml' => "Corporate Dark\n"]));
})->throws(MalformedTheme::class, 'The file does not contain a theme — it is a string');

it('refuses a theme.yml with nothing in it, because a theme has a name', function () {
    ThemeDirectory::load(ThemeDirectory::create(['theme.yml' => ''], 'empty-theme'));
})->throws(MalformedTheme::class, 'A theme needs a "name".');

it('reads a theme whose own file names no text styles as a theme with none', function () {
    // An empty section is a theme that names no styles, which is a fact about the theme
    // rather than a mistake in it.
    $loaded = ThemeDirectory::load(ThemeDirectory::withManifest("name: Acme\ntextStyles: {}\n"));

    expect($loaded->theme()->textStyles()->names())->toBe([]);
});

it('refuses a layout name that is not a role at all', function () {
    $loaded = ThemeDirectory::load(ThemeDirectory::minimal('acme'));

    $loaded->layout('Two Content');
})->throws(UnknownLayoutRole::class, '"Two Content" is not a layout role. The roles are: title, content, two-column, section, blank, image-text, image-title, image-slide');

it('turns a role into the layout name of the theme\'s own template', function () {
    $loaded = ThemeDirectory::load(ThemeDirectory::withManifest(
        "name: Acme\nlayouts:\n  two-column: Two Content\n",
        ['template.pptx' => 'not really a template'],
    ));

    expect($loaded->layout('two-column'))->toBe('Two Content')
        ->and($loaded->template()?->path())->toBe($loaded->directory() . '/template.pptx');
});

it('refuses a file of a theme that resolves outside the scope', function () {
    // The loader's own scope, for a caller that reads a theme without the resolver.
    // The file is resolved and held, and one that reaches out of the scope is
    // refused by name.
    $outside = ThemeDirectory::create(['styles.yml' => "heading.1: ReportTitle\n"], 'outside');
    $directory = ThemeDirectory::acme();
    ThemeDirectory::link($directory . '/styles.yml', $outside . '/styles.yml');
    $scope = new PathScope([$directory]);

    expect(fn () => ThemeDirectory::load($directory, new ThemeLoader($scope)))
        ->toThrow(ThemeNotFound::class, 'is at "' . realpath($outside . '/styles.yml') . '" rather than one this render may read');
});

it('resolves the path of a theme\'s files, with a scope or without one', function () {
    // The Word half's loader resolves the path whether or not it has a scope, and the two
    // loaders are meant to be interchangeable: a theme read through a link is the theme
    // the link reaches, whichever half reads it.
    $real = ThemeDirectory::create(['theme.yml' => "name: Linked\n"], 'real');
    $holder = Scratch::directoryPath('holder');

    if (!@symlink($real, $holder . '/house')) {
        test()->markTestSkipped('symlinks are not available here');
    }

    $loaded = ThemeDirectory::load($holder . '/house');

    expect($loaded->directory())->toBe(realpath($real));
});

it('takes the files of a theme on trust when it has no scope', function () {
    // The scope is what makes the check; without one the loader reads what it is
    // given, which is what a theme this library ships is and what a caller naming a
    // directory of its own is asking for.
    $outside = ThemeDirectory::create(['theme.yml' => "name: Linked\n"], 'outside');
    $directory = ThemeDirectory::acme();
    ThemeDirectory::link($directory . '/theme.yml', $outside . '/theme.yml');

    expect(ThemeDirectory::load($directory)->theme()->name())->toBe('Linked');
});

it('reads a manifest the deck named, whatever the file it reached is called', function () {
    // The resolver resolves a path before it hands it over, so the loader is given the
    // file the path reached, whose name is the target's rather than the one the deck
    // wrote. The name the deck wrote is what makes a file the manifest of a theme, so the
    // file is read rather than refused for the name it happens to have.
    $directory = ThemeDirectory::create(['other.yml' => "name: Linked\n"], 'manifest');
    ThemeDirectory::link($directory . '/theme.yml', $directory . '/other.yml');

    $loaded = ThemeDirectory::loadManifest($directory, 'theme.yml', new ThemeLoader(new PathScope([$directory])));

    expect($loaded->theme()->name())->toBe('Linked');
});

it('refuses a manifest that resolves outside the scope', function () {
    // The scope is about where the file is, not what it is called: a manifest reaching
    // out of the scope is refused by the scope, whatever the deck called it.
    $outside = ThemeDirectory::create(['other.yml' => "name: Stolen\n"], 'outside');
    $directory = ThemeDirectory::create(['theme.yml' => "name: Acme\n"], 'theme');
    ThemeDirectory::link($directory . '/theme.yml', $outside . '/other.yml');

    expect(fn () => ThemeDirectory::loadManifest($directory, 'theme.yml', new ThemeLoader(new PathScope([$directory]))))
        ->toThrow(ThemeNotFound::class, 'is at "' . realpath($outside . '/other.yml') . '" rather than one this render may read');
});
