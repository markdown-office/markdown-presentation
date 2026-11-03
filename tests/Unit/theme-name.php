<?php

declare(strict_types=1);

use MarkdownPresentation\Exception\UnreadablePackage;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\ThemeNamePass;
use MarkdownPresentation\ThemePart;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Tests\Support\UnreadableArchive;

/**
 * The name a deck carries of the theme it was drawn in.
 *
 * A `.pptx` holds the theme's colours and its fonts and nothing else about it: the name a
 * deck's `theme:` names is a property of the Markdown the deck was written from, and
 * nothing in the package said which directory it came from. So the name is written into the
 * theme part — where PowerPoint writes the name of a theme — and read back out of it.
 *
 * Every path through this is a path a package can take: a package that is not one, a
 * package with no theme part, a theme part that cannot be read, and a theme part that
 * names nothing.
 */

/**
 * A package with a theme part, built by hand rather than by the writer.
 *
 * By hand because the paths this file is about are the ones a writer's own package cannot
 * take: a theme part that names nothing, and relationships that point at nothing.
 */
function themePackage(string $path, string $theme, string $relationships): string
{
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $relationships);
    $zip->addFromString('ppt/theme/theme1.xml', $theme);
    $zip->close();

    return $path;
}

/** The relationships of a slide master that name a theme part. */
const THEME_RELATIONSHIP = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Target="../theme/theme1.xml" '
    . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme"/>'
    . '</Relationships>';

it('names the theme a deck was drawn in', function () {
    $path = Scratch::file('named.pptx', '');

    (new PresentationRenderer())->render(
        (new \MarkdownPresentation\Document\DeckParser())->parse("# One\n\nText.\n"),
        $path,
    );

    expect(ThemeNamePass::applyTo($path, 'pitch'))->toBe(1);

    $zip = new ZipArchive();
    $zip->open($path);
    $theme = (string) $zip->getFromName('ppt/theme/theme1.xml');
    $zip->close();

    expect($theme)->toContain('<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="pitch"');
});

it('writes a name that looks like a backreference as itself', function () {
    // The name is a directory name, and a directory may be called `sale$1`. A replacement
    // string reads `$1` as the group it captured and `\1` as the same, and the group would
    // have been written into the theme part in the name's place — which is not well-formed
    // XML, and a deck carrying it is a deck PowerPoint repairs.
    $xml = '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Theme32"/>';

    foreach (['sale$1', 'sale$2', 'sale\\1', 'sale$0'] as $name) {
        $renamed = ThemeNamePass::rename($xml, $name);

        expect($renamed)->toContain(sprintf('name="%s"', $name));

        $document = new DOMDocument();

        libxml_use_internal_errors(true);

        expect($document->loadXML($renamed))->toBeTrue();
    }
});

it('leaves a package with no theme part alone', function () {
    // A package this library did not write has no theme part, and a name it does not
    // carry is a name that is not written.
    $path = Scratch::file('no-theme.pptx', '');

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('ppt/presentation.xml', '<p:presentation/>');
    $zip->close();

    expect(ThemeNamePass::applyTo($path, 'pitch'))->toBe(0);
});

it('says so when the package cannot be opened at all', function () {
    $path = Scratch::file('not-a-deck.pptx', '');

    file_put_contents($path, 'not a package');

    expect(fn () => ThemeNamePass::applyTo($path, 'pitch'))->toThrow(UnreadablePackage::class);
});

it('says so when the theme part cannot be read', function () {
    $path = Scratch::file('unreadable-theme.pptx', '');

    (new PresentationRenderer())->render(
        (new \MarkdownPresentation\Document\DeckParser())->parse("# One\n\nText.\n"),
        $path,
    );

    $archive = new UnreadableArchive('ppt/theme/theme1.xml');
    $archive->open($path);

    expect(fn () => ThemeNamePass::applyTo($path, 'pitch', $archive))->toThrow(UnreadablePackage::class);
});

it('finds no theme in a package whose relationships cannot be read', function () {
    $path = Scratch::file('unreadable-relationships.pptx', '');

    (new PresentationRenderer())->render(
        (new \MarkdownPresentation\Document\DeckParser())->parse("# One\n\nText.\n"),
        $path,
    );

    $archive = new UnreadableArchive('ppt/slideMasters/_rels/slideMaster1.xml.rels');
    $archive->open($path);

    expect(ThemePart::of($archive))->toBeNull();
});

it('finds no name in a theme part that names nothing', function () {
    $path = themePackage(
        Scratch::file('nameless.pptx', ''),
        '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"/>',
        THEME_RELATIONSHIP,
    );

    $zip = new ZipArchive();
    $zip->open($path);

    expect(ThemePart::nameOf($zip))->toBeNull();

    $zip->close();
});

it('finds no name in a theme part that cannot be read', function () {
    $path = Scratch::file('unreadable-name.pptx', '');

    (new PresentationRenderer())->render(
        (new \MarkdownPresentation\Document\DeckParser())->parse("# One\n\nText.\n"),
        $path,
    );

    $archive = new UnreadableArchive('ppt/theme/theme1.xml');
    $archive->open($path);

    expect(ThemePart::nameOf($archive))->toBeNull();
});
