<?php

declare(strict_types=1);

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Render\PlaceholderPass;
use MarkdownPresentation\Render\PresentationRenderer;
use MarkdownPresentation\Render\SlideGeometry;
use MarkdownPresentation\Tests\Support\Pptx;
use MarkdownPresentation\Tests\Support\Scratch;
use MarkdownPresentation\Theme\LayoutRole;

/*
 * What a layout part is for, read back off the file.
 *
 * A layout part whose shape tree is empty is a name and nothing else: *Reset Slide* has
 * nothing to restore, the outline view reads no title, and the layout picker offers five
 * blank rectangles. Every assertion here builds a deck and asks the `.pptx`, because a
 * layout's meaning is entirely in the markup — there is nothing else it could be checked
 * against.
 */

/**
 * @return Pptx
 */
function placeholderDeck(string $markdown): Pptx
{
    $deck = (new DeckParser())->parse($markdown);
    $path = Scratch::file('placeholders.pptx', '');

    (new PresentationRenderer())->render($deck, $path);

    return Pptx::open($path);
}

it('gives a layout part a placeholder for its title and one for its body', function () {
    // The name on the part was always there; this is the other half. Without a placeholder
    // there is nothing for a slide to refer to and nothing for Reset to restore.
    expect(placeholderDeck("# One\n\nText.\n")->layoutPlaceholders())
        ->toHaveKeys(['title', 'body 1']);
});

it('gives a two-column layout a body placeholder for each of its columns', function () {
    // Two columns are the reason the role exists, so the layout has to say so in the one
    // place PowerPoint looks: `idx` 1 and `idx` 2.
    expect(placeholderDeck("---\nlayout: two-column\n---\n# One\n\nLeft.\n\n## Right\n\nRight side.\n")
        ->layoutPlaceholders())
        ->toHaveKeys(['title', 'body 1', 'body 2']);
});

it('writes a layout\'s placeholders where the slides are drawn', function () {
    // The claim *Reset* makes good on: restoring the layout's box has to restore the box the
    // slide already has, or every Reset in the application moves the type.
    $pptx = placeholderDeck("# One\n\nText.\n");
    $placeholder = $pptx->layoutPlaceholders()['title'];
    $drawn = $pptx->positionOf('title');

    expect($drawn['x'])->toBe($placeholder['x'])
        ->and($drawn['y'])->toBe($placeholder['y'])
        ->and($drawn['width'])->toBe($placeholder['width'])
        ->and($drawn['height'])->toBe($placeholder['height']);
});

it('gives each role\'s layout its own placeholders rather than one layout\'s', function () {
    // A pass that numbered the layouts wrongly would put `two-column`'s two columns in the
    // part the content slides are bound to, and every slide would inherit the wrong one.
    $pptx = placeholderDeck("---\nlayout: content\n---\n# One\n\nText.\n\n---\nlayout: two-column\n---\n# Two\n\nLeft.\n\n## Right\n\nRight side.\n");

    expect(array_keys($pptx->layoutPlaceholders(1)))->toBe(['title', 'body 1'])
        ->and(array_keys($pptx->layoutPlaceholders(2)))->toBe(['title', 'body 1', 'body 2'])
        ->and($pptx->layoutOf(1))->toBe('1')
        ->and($pptx->layoutOf(2))->toBe('2');
});

it('points a slide\'s title at the layout\'s title placeholder', function () {
    // This is the step that makes the layout mean anything: type and index 0 on both sides,
    // which is how Office writes the pair.
    expect(placeholderDeck("# One\n\nText.\n")->placeholderOf('title'))->toBe('type="title"');
});

it('leaves a blank slide with nothing to refer to a title placeholder to', function () {
    // `blank` has no title box, so it has no title shape to mark. A `<p:ph>` on some other
    // shape would be the library inventing a title the role does not have.
    $pptx = placeholderDeck("---\nlayout: blank\n---\n\nJust body.\n");

    expect($pptx->placeholderOf('heading'))->toBeNull()
        ->and(array_keys($pptx->layoutPlaceholders()))->toBe(['body 1']);
});

it('leaves the body blocks of a slide out of it', function () {
    // Stated rather than left implicit: a slide's body is a stack of differently shaped
    // blocks — a table, a picture, a code block — and promoting one of them to a placeholder
    // would make Reset move that block and leave the rest of the column floating. The layout
    // has the placeholder; nothing on the slide refers to it.
    $pptx = placeholderDeck("# One\n\nText.\n\n- An item.\n\n```php\n\$line = 1;\n```\n");

    expect($pptx->layoutPlaceholders())->toHaveKey('body 1')
        ->and($pptx->placeholderOf('paragraph'))->toBeNull()
        ->and($pptx->placeholderOf('list'))->toBeNull()
        ->and($pptx->placeholderOf('code'))->toBeNull();
});

it('leaves the type where the layout puts it when the title becomes a placeholder', function () {
    // PHPPresentation's own placeholder support writes an empty `<a:bodyPr/>`, which drops
    // the `normAutofit` and the insets and hands the shape back to the reader to resize. The
    // body properties are asserted because they are the mechanism, not because a pixel here
    // is easy to read off a file.
    $slide = placeholderDeck("# One\n\nText.\n")->part('ppt/slides/slide1.xml');

    expect($slide)->toContain('<a:normAutofit/>')
        ->and($slide)->toContain('lIns="91440"')
        ->and($slide)->toContain('name="title"');
});

it('leaves a layout part it has already written to itself', function () {
    $xml = PlaceholderPass::layoutShapes(
        "<p:sldLayout>\n  <p:cSld>\n    <p:spTree>\n      <p:ph type=\"title\"/>\n    </p:spTree>\n  </p:cSld>\n</p:sldLayout>",
        new SlideGeometry(),
        LayoutRole::Content,
    );

    expect(substr_count($xml, '<p:ph '))->toBe(1);
});

it('leaves a layout with no shape tree to put a shape in', function () {
    // Repairing a part by guessing where its shapes belong would put a placeholder in a part
    // this does not understand.
    $xml = '<p:sldLayout><p:cSld name="Empty"/></p:sldLayout>';

    expect(PlaceholderPass::layoutShapes($xml, new SlideGeometry(), LayoutRole::Content))->toBe($xml);
});

it('leaves a layout whose shape tree is all on one line', function () {
    // The placeholders go after the last line already in the tree, so a part with no line to
    // go after is one this has to leave rather than reshape.
    $xml = '<p:sldLayout><p:cSld><p:spTree></p:spTree></p:cSld></p:sldLayout>';

    expect(PlaceholderPass::layoutShapes($xml, new SlideGeometry(), LayoutRole::Content))->toBe($xml);
});

it('leaves a deck with no layout part for a role it was handed', function () {
    // The renderer only ever hands over the roles it built a layout for. A caller that hands
    // over one the file has no part for gets nothing patched for it rather than an error,
    // because the parts that *are* there are still worth patching.
    $path = Scratch::directoryPath('package') . '/deck.pptx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('ppt/slides/slide1.xml', "<p:sld><p:cSld><p:spTree><p:nvGrpSpPr><p:nvPr/></p:nvGrpSpPr></p:spTree></p:cSld></p:sld>");
    $zip->close();

    expect(PlaceholderPass::applyTo($path, new SlideGeometry(), [LayoutRole::Content]))->toBe(0);
});

it('leaves a title shape with nowhere to put the reference', function () {
    $xml = '<p:sp><p:cNvPr id="2" name="title" descr=""/></p:sp>';

    expect(PlaceholderPass::titleShape($xml))->toBe($xml);
});

it('changes nothing the second time it runs', function () {
    $path = Scratch::file('twice.pptx', '');

    (new PresentationRenderer())->render((new DeckParser())->parse("# One\n\nText.\n"), $path);

    expect(PlaceholderPass::applyTo($path, new SlideGeometry(), [LayoutRole::Content]))->toBe(0);
});

it('says so when the presentation cannot be read at all', function () {
    expect(fn () => PlaceholderPass::applyTo(
        Scratch::file('not-a-deck.pptx', 'not a zip'),
        new SlideGeometry(),
        [LayoutRole::Content],
    ))->toThrow(RuntimeException::class);
});