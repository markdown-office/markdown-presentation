<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\Exception;
use MarkdownPresentation\Exception\MalformedTheme;
use MarkdownPresentation\Exception\ThemeNotFound;
use MarkdownPresentation\Exception\UnknownLayoutRole;
use MarkdownPresentation\Exception\UnknownStyleSlot;
use MarkdownPresentation\Tests\Support\ThemeDirectory;
use MarkdownPresentation\Theme\ThemeLoader;

$loader = new ThemeLoader();

it('reads a theme from a directory with nothing but a theme.yml in it', function () use ($loader) {
    $loaded = $loader->load(ThemeDirectory::minimal('acme'));

    expect($loaded->theme()->name())->toBe('acme')
        ->and($loaded->directory())->toEndWith('/' . basename($loaded->directory()))
        ->and($loaded->styles())->toBe([])
        ->and($loaded->layouts())->toBe([])
        ->and($loaded->template())->toBeNull();
});

it('reads the style slots and the layout roles beside a theme.yml', function () use ($loader) {
    $loaded = $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'styles.yml' => "heading.1: ReportTitle\nparagraph: BodyText\ncodeFont:\n  name: Fira Code\n  size: 10\n",
        'layouts.yml' => "layouts:\n  title: Title Slide\n  two-column: Two Content\n",
    ]));

    expect($loaded->styles())->toBe([
        'heading.1' => 'ReportTitle',
        'paragraph' => 'BodyText',
        'codeFont' => ['name' => 'Fira Code', 'size' => 10],
    ])->and($loaded->layouts())->toBe(['title' => 'Title Slide', 'two-column' => 'Two Content'])
        ->and($loaded->roles())->toBe(['title', 'two-column']);
});

it('reads a layouts file whose mappings stand on their own', function () use ($loader) {
    $loaded = $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'layouts.yml' => "title: Title Slide\nblank: Blank\n",
    ]));

    expect($loaded->layout('blank'))->toBe('Blank');
});

it('says a directory with no theme.yml is not a theme', function () use ($loader) {
    $loader->load(ThemeDirectory::create(['layouts.yml' => "title: Title Slide\n"], 'not-a-theme'));
})->throws(ThemeNotFound::class, 'is not a theme: it has no theme.yml');

it('hands a style value back as it was written, tokens and all', function () use ($loader) {
    // A `styles.yml` may already reference a theme token. Resolving them is not
    // written yet, so the value passes through exactly as the file has it rather
    // than losing the reference.
    $loaded = $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'styles.yml' => "linkFont:\n  color: \"{accent1}\"\n  name: \"{fonts.mono}\"\n",
    ]));

    expect($loaded->styles())->toBe(['linkFont' => ['color' => '{accent1}', 'name' => '{fonts.mono}']]);
});

it('refuses a styles.yml slot that is not a style slot, and names the slots that are', function () use ($loader) {
    // The shipped fixture, so the README can show the same failure from the same
    // file rather than a directory built for the occasion.
    $loader->load(dirname(__DIR__) . '/fixtures/themes/broken');
})->throws(UnknownStyleSlot::class, '"blockquote" is not a style slot. The slots are: heading.1, heading.2');

it('refuses a layouts.yml role that is not a role, and names the roles that are', function () use ($loader) {
    $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'layouts.yml' => "layouts:\n  two-columns: Two Content\n",
    ]));
})->throws(UnknownLayoutRole::class, '"two-columns" is not a layout role. The roles are: title, content, two-column, section, blank, image-text, image-title, image-slide');

it('refuses a key beside the mappings that is not one', function () use ($loader) {
    $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'layouts.yml' => "layouts:\n  title: Title Slide\nlayout: Two Content\n",
    ]));
})->throws(UnknownLayoutRole::class, '"layout" is not a key of a layouts file. Valid keys: layouts');

it('refuses a layouts key that does not hold the mappings', function () use ($loader) {
    $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'layouts.yml' => "layouts: Two Content\n",
    ]));
})->throws(UnknownLayoutRole::class, '"layouts" is not a mapping of roles to layout names');

it('refuses a role mapped to nothing', function () use ($loader) {
    $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'layouts.yml' => "layouts:\n  title:\n",
    ]));
})->throws(UnknownLayoutRole::class, 'the "title" role is not a layout name');

it('refuses a styles file that is not YAML', function () use ($loader) {
    $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'styles.yml' => "heading.1: \"unclosed\n",
    ]));
})->throws(Exception::class, 'is not valid YAML');

it('refuses a styles file that is not a mapping of slots', function () use ($loader) {
    $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'styles.yml' => "- heading.1\n",
    ]));
})->throws(MalformedTheme::class, 'The file does not contain a mapping of style slots to styles');

it('refuses a layouts file that is not a mapping of roles', function () use ($loader) {
    $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'layouts.yml' => "- title\n",
    ]));
})->throws(MalformedTheme::class, 'The file does not contain a mapping of layout roles to layout names');

it('says a file it cannot read is a file it cannot read', function () use ($loader) {
    skipWithoutPermissions('The unreadable file');

    $directory = ThemeDirectory::create(['theme.yml' => "name: Acme\n"]);

    chmod($directory . '/theme.yml', 0o000);

    $loader->load($directory);
})->throws(Exception::class, 'Unable to read');

it('refuses a theme.yml that is not a theme', function () use ($loader) {
    $loader->load(ThemeDirectory::create(['theme.yml' => "- one\n- two\n"]));
})->throws(MalformedTheme::class, 'The file does not contain a theme — it is a list');

it('refuses a theme.yml that is a bare string rather than a mapping of keys', function () use ($loader) {
    $loader->load(ThemeDirectory::create(['theme.yml' => "Corporate Dark\n"]));
})->throws(MalformedTheme::class, 'The file does not contain a theme — it is a string');

it('reads a file with nothing in it as a file with nothing in it', function () use ($loader) {
    // An empty styles file is a theme that overrides nothing, which is a fact about
    // the theme rather than a mistake in it.
    $loaded = $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'styles.yml' => '',
    ]));

    expect($loaded->styles())->toBe([]);
});

it('names a theme that has no layouts.yml when a role cannot be resolved', function () use ($loader) {
    $loaded = $loader->load(ThemeDirectory::minimal('acme'));

    $loaded->layout('content');
})->throws(UnknownLayoutRole::class, 'The theme "acme" maps no "content" layout. It maps: (none — the theme has no layouts.yml)');

it('names the roles a theme does have when the one asked for is not one of them', function () use ($loader) {
    $loaded = $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'layouts.yml' => "layouts:\n  title: Title Slide\n  blank: Blank\n",
    ]));

    $loaded->layout('section');
})->throws(UnknownLayoutRole::class, 'The theme "Acme" maps no "section" layout. It maps: title, blank');

it('refuses a layout name that is not a role at all', function () use ($loader) {
    $loaded = $loader->load(ThemeDirectory::minimal('acme'));

    $loaded->layout('Two Content');
})->throws(UnknownLayoutRole::class, '"Two Content" is not a layout role. The roles are: title, content, two-column, section, blank, image-text, image-title, image-slide');

it('turns a role into the layout name of the theme\'s own template', function () use ($loader) {
    $loaded = $loader->load(ThemeDirectory::create([
        'theme.yml' => "name: Acme\n",
        'layouts.yml' => "layouts:\n  two-column: Two Content\n",
        'template.pptx' => 'not really a template',
    ]));

    expect($loaded->layout('two-column'))->toBe('Two Content')
        ->and($loaded->template())->toBe($loaded->directory() . '/template.pptx');
});
