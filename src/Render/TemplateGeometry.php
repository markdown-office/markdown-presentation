<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use DOMDocument;
use DOMElement;
use MarkdownPresentation\Exception\MalformedTemplate;
use ZipArchive;

/**
 * The placeholder boxes a theme's own `template.pptx` carries, keyed by the layout name it
 * gives them.
 *
 * A theme that ships a template is saying where its layouts put their boxes. This reads that
 * and {@see SlideGeometry::with()} takes it, so the deck is drawn in the template's geometry
 * and the layout parts carry the same boxes — which is what makes *Reset* a no-op rather
 * than a move.
 *
 * EMU to pixels, and why that is a ratio rather than a division. A box in a template is a
 * fraction of *that template's* page: a stock 16:9 PowerPoint theme is 12192000 × 6858000
 * EMU, which is 1280 × 720 of the pixels {@see Box} counts in, not 960 × 540. Dividing by
 * the 9525 EMU a pixel would put every placeholder of a stock template off the right and the
 * bottom of a 960-wide slide. Scaling each axis by the ratio of the template's page to this
 * one's keeps the position the template's designer chose and puts the template's whole
 * extent on the page this library writes.
 *
 * What this does not take from a template, and does not lose by accident:
 *
 * - The type sizes and the alignments. A template states them in a *master*'s `p:txStyles`,
 *   which is a whole typographic system rather than a layout, and adopting one would resize
 *   the type of every deck that shipped a template. {@see SlideGeometry} keeps those.
 * - The master's own drawings — a logo, a rule, a decorated background. A deck's page is
 *   painted by this library from `theme.yml`, and copying a master's decorations onto it
 *   would put the same artwork on slides whose themes disagree about what is on them.
 * - A placeholder this library cannot draw. A footer, a date and a slide number are
 *   placeholders too, and a deck that wants a slide number has to say so in a feature this
 *   library does not have; they are left out rather than laid out as body text.
 */
final class TemplateGeometry
{
    private const PRESENTATION = 'ppt/presentation.xml';

    private const PRESENTATIONML = 'http://schemas.openxmlformats.org/presentationml/2006/main';
    private const DRAWINGML = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * `ctrTitle` as well as `title`: Office's own *Title Slide* layout centres its title,
     * and a template that says so is saying where the box is, not what to call it.
     */
    private const TITLE_TYPES = ['title', 'ctrTitle'];

    /**
     * `subTitle` is a column because that is what it is on the layout a `title` role maps
     * to, `obj` because a template's content placeholder is as often one of those as a
     * `body`, and `pic` because the layouts this library gives a picture to carry the
     * picture in one of their body columns — a template's *Picture Left* is two `body`
     * placeholders under another name, and reading only the ones called `body` would take
     * its text column and leave its picture column behind.
     */
    private const BODY_TYPES = ['body', 'subTitle', 'obj', 'pic'];

    /**
     * @param string $path The theme's `template.pptx`.
     */
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Every layout in the template, by the name it carries, and the boxes it says.
     *
     * A layout with nothing this library can draw in it contributes nothing: Office's own
     * *Blank* has no placeholders at all, and a role that maps to one keeps the geometry
     * {@see SlideGeometry} puts it at. That is the precedence — a template fills in the roles
     * it covers and says nothing about the rest — and it is the rule `layouts.yml` already
     * follows, where a theme that maps two roles is a theme that works rather
     * than one that fails on the three.
     *
     * Two layouts with the same name would collide; the last one in the archive wins, which
     * is what a reader of the file would see too. A layout that carries no name is filed
     * under the empty string, which no theme can ask for: `ThemeLoader` refuses an empty
     * layout name in a `layouts.yml`.
     *
     * @return array<string, array{title?: Box, body?: non-empty-list<Box>}>
     *
     * @throws MalformedTemplate When the file is not a presentation this can read.
     */
    public function boxes(): array
    {
        $zip = new ZipArchive();

        if ($zip->open($this->path) !== true) {
            throw MalformedTemplate::notAPackage($this->path);
        }

        try {
            return $this->read($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<string, array{title?: Box, body?: non-empty-list<Box>}>
     *
     * @throws MalformedTemplate
     */
    private function read(ZipArchive $zip): array
    {
        $page = $this->page($zip);
        $masters = [];
        $boxes = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $part = (string) $zip->getNameIndex($index);

            if (preg_match('~^ppt/slideLayouts/slideLayout(\d+)\.xml$~', $part) !== 1) {
                continue;
            }

            $document = $this->document($zip, $part);
            $placeholders = $this->placeholders($document, $page);
            $master = $this->master($part, $zip);

            // A placeholder that states no position takes its master's, which is what
            // PowerPoint writes for a layout that does not move the one it inherited. The
            // master is read once per template rather than once per layout: there may be
            // several, and each layout belongs to whichever one its relationships name.
            foreach ($placeholders as $key => $placeholder) {
                if ($placeholder['box'] !== null || $master === '') {
                    continue;
                }

                $inherited = $masters[$master] ??= $this->placeholders(
                    $this->document($zip, $master),
                    $page,
                );

                $placeholders[$key]['box'] = $inherited[$key]['box'] ?? null;
            }

            $cover = $this->cover($placeholders);

            if ($cover !== []) {
                $name = self::first($document->documentElement, self::PRESENTATIONML, 'cSld')?->getAttribute('name') ?? '';
                $boxes[$name] = $cover;
            }
        }

        return $boxes;
    }

    /**
     * How big the template's slides are, in EMU.
     *
     * @return array{cx: int, cy: int}
     *
     * @throws MalformedTemplate
     */
    private function page(ZipArchive $zip): array
    {
        $size = self::first($this->document($zip, self::PRESENTATION)->documentElement, self::PRESENTATIONML, 'sldSz');
        $cx = (int) ($size?->getAttribute('cx') ?? 0);
        $cy = (int) ($size?->getAttribute('cy') ?? 0);

        if ($cx < 1 || $cy < 1) {
            throw MalformedTemplate::noPageSize($this->path);
        }

        return ['cx' => $cx, 'cy' => $cy];
    }

    /**
     * Every placeholder in a layout or a master, by the type and index it states.
     *
     * @param array{cx: int, cy: int} $page
     *
     * @return array<string, array{type: string, idx: int, box: ?Box}>
     */
    private function placeholders(DOMDocument $document, array $page): array
    {
        $found = [];
        $tree = self::first(
            self::first($document->documentElement, self::PRESENTATIONML, 'cSld'),
            self::PRESENTATIONML,
            'spTree',
        );

        foreach (self::children($tree, self::PRESENTATIONML, 'sp') as $shape) {
            $placeholder = self::first(
                self::first(self::first($shape, self::PRESENTATIONML, 'nvSpPr'), self::PRESENTATIONML, 'nvPr'),
                self::PRESENTATIONML,
                'ph',
            );

            if ($placeholder === null) {
                continue;
            }

            // Both attributes are optional and both have a default: `body` and `0`. Office
            // writes a title with neither, which is why they are read rather than required.
            //
            // The default is applied before the key as well as before the value, because the
            // key is what a placeholder is matched to its master's by. PowerPoint writes the
            // ordinary content placeholder of *Title and Content* — the layout a theme maps
            // `content` to more often than any other — as a bare `<p:ph idx="1"/>`, and a key
            // built from the attribute as written is `/1`, which matches nothing in a master
            // that says `body/1`. The layout then states no position, inherits nothing, and
            // loses its body box to {@see self::cover()}, which drops every placeholder it
            // cannot place.
            $type = $placeholder->getAttribute('type') === '' ? 'body' : $placeholder->getAttribute('type');
            $idx = (int) $placeholder->getAttribute('idx');

            $found[$type . '/' . $idx] = [
                'type' => $type,
                'idx' => $idx,
                'box' => $this->box($shape, $page),
            ];
        }

        return $found;
    }

    /**
     * The rectangle a placeholder occupies in pixels, or null where it states none.
     *
     * @param array{cx: int, cy: int} $page
     */
    private function box(DOMElement $shape, array $page): ?Box
    {
        $transform = self::first(self::first($shape, self::PRESENTATIONML, 'spPr'), self::DRAWINGML, 'xfrm');
        $offset = self::first($transform, self::DRAWINGML, 'off');
        $extent = self::first($transform, self::DRAWINGML, 'ext');

        // No `a:xfrm` at all is how a placeholder says "wherever the master says", and that is
        // what PowerPoint writes for a layout that does not move the one it inherited.
        if ($offset === null || $extent === null) {
            return null;
        }

        $cx = (int) $extent->getAttribute('cx');
        $cy = (int) $extent->getAttribute('cy');

        // The other way to state no position: an `a:xfrm` left behind by a shape that was
        // dragged off the page. A deck laid out inside one gets a column a pixel wide.
        if ($cx < 1 || $cy < 1) {
            return null;
        }

        return new Box(
            self::scale((int) $offset->getAttribute('x'), $page['cx'], SlideGeometry::WIDTH),
            self::scale((int) $offset->getAttribute('y'), $page['cy'], SlideGeometry::HEIGHT),
            self::scale($cx, $page['cx'], SlideGeometry::WIDTH),
            self::scale($cy, $page['cy'], SlideGeometry::HEIGHT),
        );
    }

    /**
     * What a layout's placeholders say about the roles it can stand for.
     *
     * @param array<string, array{type: string, idx: int, box: ?Box}> $placeholders
     *
     * @return array{title?: Box, body?: non-empty-list<Box>}
     */
    private function cover(array $placeholders): array
    {
        $title = null;
        $body = [];

        foreach ($placeholders as $placeholder) {
            $box = $placeholder['box'];

            if ($box === null) {
                continue;
            }

            if (in_array($placeholder['type'], self::TITLE_TYPES, true)) {
                $title ??= $box;

                continue;
            }

            if (in_array($placeholder['type'], self::BODY_TYPES, true)) {
                $body[$placeholder['idx']] = $box;
            }
        }

        // Ordered by `idx`, which is what says which column is which — a template is free to
        // write its placeholders in any order.
        ksort($body);
        $columns = array_values($body);

        return match (true) {
            $title !== null && $columns !== [] => ['title' => $title, 'body' => $columns],
            $title !== null => ['title' => $title],
            $columns !== [] => ['body' => $columns],
            default => [],
        };
    }

    /**
     * The master part a layout's relationships name, resolved against the layout's own.
     *
     * Read rather than guessed: a presentation may have more than one master and a layout of
     * the second belongs to it. An empty answer is a layout whose relationships name no
     * master — PowerPoint gives every layout exactly one — and it inherits nothing rather
     * than this going looking for one.
     *
     * @throws MalformedTemplate
     */
    private function master(string $part, ZipArchive $zip): string
    {
        $target = '';

        foreach (self::children(
            $this->document($zip, dirname($part) . '/_rels/' . basename($part) . '.rels')->documentElement,
            self::RELATIONSHIPS,
            'Relationship',
        ) as $relationship) {
            if (str_ends_with($relationship->getAttribute('Type'), '/slideMaster')) {
                $target = $relationship->getAttribute('Target');

                break;
            }
        }

        return $target === '' ? '' : self::resolve(dirname($part), $target);
    }

    /** `../slideMasters/slideMaster1.xml` against `ppt/slideLayouts`, as `ppt/slideMasters/…`. */
    private static function resolve(string $base, string $target): string
    {
        $path = $base;

        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                $path = dirname($path);

                continue;
            }

            if ($segment !== '' && $segment !== '.') {
                $path .= '/' . $segment;
            }
        }

        return $path;
    }

    private static function scale(int $emu, int $page, int $ours): int
    {
        return (int) round($emu * $ours / $page);
    }

    /**
     * The first child element with this name in this namespace, or null.
     *
     * Children rather than descendants, which `DOMElement::getElementsByTagNameNS()` gives:
     * a `p:sp` carries a `p:ph` of its own and none other, and reading past the element asked
     * for is how a reader ends up taking the box off the shape beside it.
     */
    private static function first(?DOMElement $parent, string $namespace, string $name): ?DOMElement
    {
        return self::children($parent, $namespace, $name)[0] ?? null;
    }

    /**
     * @return list<DOMElement>
     */
    private static function children(?DOMElement $parent, string $namespace, string $name): array
    {
        $found = [];

        foreach ($parent === null ? [] : $parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === $namespace && $node->localName === $name) {
                $found[] = $node;
            }
        }

        return $found;
    }

    /**
     * The XML in a part, as a document.
     *
     * The part goes in the failure rather than the path alone: a template is a zip, so "the
     * XML is broken" is only actionable when it says which of the parts inside it is.
     * `LIBXML_NONET` keeps a file a theme's author handed us from reaching out to anything
     * while it is parsed, and the errors are collected rather than raised as warnings so that
     * a part this cannot read is the named failure below and not a notice in the middle of a
     * render.
     *
     * @throws MalformedTemplate
     */
    private function document(ZipArchive $zip, string $part): DOMDocument
    {
        $quiet = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML((string) $zip->getFromName($part), LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($quiet);

        if ($loaded === false) {
            throw MalformedTemplate::unreadable($this->path, $part);
        }

        return $document;
    }
}