<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnreadablePackage;
use ZipArchive;

/**
 * Completes the chain a speaker note hangs from: a notes slide to its slide, and every notes
 * slide to a notes master.
 *
 * PHPPresentation writes `ppt/notesSlides/notesSlideN.xml` and nothing that holds it up. The
 * slide names its notes slide, and the notes slide names nothing: there is no
 * `ppt/notesSlides/_rels/` directory in the package at all and no `ppt/notesMasters/` either,
 * so the presentation's `notesMasterIdLst` has nothing to list and the notes master a notes
 * slide inherits its text formatting from does not exist.
 *
 * PowerPoint opens a deck in that state and prints the note, which is why the omission survived
 * as long as it did: the file works and is nevertheless not the `.pptx` it claims to be, and a
 * consumer that resolves a part through the relationship that points at it finds nothing. This
 * pass writes the links that close it: a notes slide back to its own slide and on to a notes
 * master, a notes master in its own right with a theme of its own, and the presentation's list
 * naming it.
 *
 * The notes master takes its colour map from the slide master rather than carrying a second
 * copy, so a note resolves its colours the way the rest of the deck does. Its theme is a *copy*
 * of that theme rather than the same part: one part serving two masters is legal OPC, and
 * PowerPoint repairs every deck that does it — see {@see self::notesTheme()} for why that is
 * written at the length it is.
 */
final class NotesChainPass
{
    private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    private const NOTES_MASTER = 'ppt/notesMasters/notesMaster1.xml';

    private const NOTES_MASTER_RELS = 'ppt/notesMasters/_rels/notesMaster1.xml.rels';

    private const NOTES_MASTER_TYPE = 'application/vnd.openxmlformats-officedocument.presentationml.notesMaster+xml';

    private const THEME_TYPE = 'application/vnd.openxmlformats-officedocument.theme+xml';

    /**
     * `CT_NotesMaster` orders its children `cSld`, `clrMap`, `notesStyle`, and the last two are
     * both required — the colour map is what resolves a note's `schemeClr`, and the style is
     * what it falls back to. `notesStyle` may be empty: every one of its children is optional.
     *
     * The two placeholders are not decoration. A notes slide names its shapes by placeholder
     * type and takes their position and size from the master's shape of that type, so a master
     * with an empty `spTree` is a master that has nothing to give: PowerPoint opens such a deck
     * by repairing it, and reports nothing until it does. Schema validation does not see this —
     * an empty shape tree is a valid `spTree` — which is why it has to be probed in PowerPoint
     * rather than argued about from the XSD.
     *
     * The geometry is the one PowerPoint itself writes for a `notesSz` of 6858000 by 9144000
     * EMU, which is the `p:notesSz` PHPPresentation emits: the slide image above, the note
     * below it, both inset by 685800 from either side.
     */
    private const NOTES_MASTER_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<p:notesMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
        . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
        . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/>'
        . '<a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
        . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Slide Image Placeholder 1"/>'
        . '<p:cNvSpPr><a:spLocks noGrp="1" noRot="1" noChangeAspect="1"/></p:cNvSpPr>'
        . '<p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr>'
        . '<p:spPr><a:xfrm><a:off x="685800" y="457200"/><a:ext cx="5486400" cy="3086100"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:sp>'
        . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes Placeholder 2"/>'
        . '<p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr>'
        . '<p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>'
        . '<p:spPr><a:xfrm><a:off x="685800" y="4000500"/><a:ext cx="5486400" cy="4343400"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr>'
        . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></p:txBody>'
        . '</p:sp></p:spTree></p:cSld>%s<p:notesStyle/>'
        . '</p:notesMaster>';

    /**
     * The colour map a notes master falls back to when the package holds no slide master to
     * read one from. All ten attributes are required, so there is no smaller thing to say; this
     * is the mapping the format defines rather than one chosen here.
     */
    private const DEFAULT_COLOUR_MAP = '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2"'
        . ' accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>';

    /**
     * Rewrites the package in place, and reports how many notes slides it linked.
     *
     * A deck with no speaker notes in it has no notes slide to hang, so nothing is written at
     * all and the file comes out the way the writer wrote it — rather than carrying a notes
     * master that leads to nothing. Running this twice changes nothing the second time, because
     * every relationship and element this adds is looked for before it is added.
     *
     * @param ZipArchive|null $archive An archive to work in rather than one this opens.
     *        {@see NotesColourMapPass::applyTo()} is where that is explained.
     *
     * @throws UnreadablePackage When the presentation cannot be opened for writing, or a part
     *         cannot be read out of it.
     */
    public static function applyTo(string $path, ?ZipArchive $archive = null): int
    {
        $zip = $archive ?? new ZipArchive();

        if ($zip->open($path) !== true) {
            throw UnreadablePackage::cannotOpen($path);
        }

        $names = [];

        for ($index = 0, $count = $zip->numFiles; $index < $count; $index++) {
            $names[] = (string) $zip->getNameIndex($index);
        }

        $pending = [];
        $linked = 0;

        foreach (self::ownersOf($zip, $path) as $notesSlide => $slide) {
            $rels = self::relsPathFor($notesSlide);
            $existing = self::read($zip, $rels, $path);

            if ($existing !== null && str_contains($existing, self::RELATIONSHIPS . 'notesMaster')) {
                continue;
            }

            $pending[$rels] = self::relsFor($slide);
            $linked++;
        }

        if ($linked === 0) {
            $zip->close();

            return 0;
        }

        $presentation = self::required($zip, 'ppt/presentation.xml', $path);
        $presentationRels = self::required($zip, 'ppt/_rels/presentation.xml.rels', $path);
        $id = self::freeRelationshipId($presentationRels);
        [$theme, $themeXml] = self::notesTheme($zip, $names, $path);

        $pending[self::NOTES_MASTER] = sprintf(
            self::NOTES_MASTER_XML,
            self::colourMap($zip, $names, $path),
        );
        $pending[self::NOTES_MASTER_RELS] = self::relationships([
            'rId1' => ['theme', '../theme/' . basename($theme)],
        ]);
        $pending[$theme] = $themeXml;
        $pending['ppt/_rels/presentation.xml.rels'] = self::addRelationship(
            $presentationRels,
            $id,
            'notesMaster',
            'notesMasters/notesMaster1.xml',
        );
        $pending['ppt/presentation.xml'] = self::addNotesMasterId($presentation, $id);
        $pending['[Content_Types].xml'] = self::addContentTypes(
            self::required($zip, '[Content_Types].xml', $path),
            [
                self::NOTES_MASTER => self::NOTES_MASTER_TYPE,
                $theme => self::THEME_TYPE,
            ],
        );

        foreach ($pending as $name => $xml) {
            if (in_array($name, $names, true)) {
                $zip->deleteName($name);
            }

            $zip->addFromString($name, $xml);
        }

        $zip->close();

        return $linked;
    }

    /**
     * Which slide owns each notes slide, as notes slide part => slide part.
     *
     * Read out of the slides' own relationships rather than from the number in the file name:
     * `notesSlide4.xml` belongs to whichever slide names it, and the relationship is the only
     * place in the package that says which.
     *
     * @return array<string, string>
     */
    private static function ownersOf(ZipArchive $zip, string $path): array
    {
        $owners = [];

        for ($index = 0, $count = $zip->numFiles; $index < $count; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match('~^ppt/slides/(slide\d+)\.xml$~', $name, $slide) !== 1) {
                continue;
            }

            $rels = self::read($zip, 'ppt/slides/_rels/' . $slide[1] . '.xml.rels', $path);

            if ($rels === null || preg_match('~notesSlides/(notesSlide\d+\.xml)~', $rels, $found) !== 1) {
                continue;
            }

            $owners['ppt/notesSlides/' . $found[1]] = 'ppt/slides/' . $slide[1] . '.xml';
        }

        return $owners;
    }

    /**
     * A notes slide's relationships: back to its own slide, and on to the notes master.
     *
     * Both, because a relationship is stored once per direction and the slide's side of this
     * one already exists — that is how the pairing above was found. Without this part the notes
     * slide is a leaf that names nothing.
     */
    private static function relsFor(string $slide): string
    {
        return self::relationships([
            'rId1' => ['slide', '../slides/' . basename($slide)],
            'rId2' => ['notesMaster', '../notesMasters/notesMaster1.xml'],
        ]);
    }

    /**
     * A `.rels` part carrying the given relationships.
     *
     * @param array<string, array{string, string}> $relationships Id => type and target.
     */
    private static function relationships(array $relationships): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach ($relationships as $id => [$type, $target]) {
            $xml .= sprintf('<Relationship Id="%s" Type="%s%s" Target="%s"/>', $id, self::RELATIONSHIPS, $type, $target);
        }

        return $xml . '</Relationships>';
    }

    /**
     * Where a part's relationships live: the same directory, one down, under `_rels`.
     */
    private static function relsPathFor(string $part): string
    {
        return dirname($part) . '/_rels/' . basename($part) . '.rels';
    }

    /**
     * The colour map the notes master carries: the slide master's, or the format's own.
     *
     * Taken from the slide master rather than written out here because the two have to agree: a
     * notes master that maps a colour the slide master does not resolves every note through a
     * mapping nothing else in the deck uses.
     *
     * @param list<string> $names
     */
    private static function colourMap(ZipArchive $zip, array $names, string $path): string
    {
        foreach ($names as $name) {
            if (preg_match('~^ppt/slideMasters/slideMaster\d+\.xml$~', $name) !== 1) {
                continue;
            }

            $master = self::read($zip, $name, $path);

            if ($master !== null && preg_match('~<p:clrMap[^>]*/>~', $master, $found) === 1) {
                return $found[0];
            }
        }

        return self::DEFAULT_COLOUR_MAP;
    }

    /**
     * A relationship id no other relationship in the part is using.
     *
     * One above the highest already in use rather than one above the count of them, because
     * PHPPresentation does not number them consecutively: `presentation.xml.rels` holds nine
     * entries numbered `rId1` to `rId9` today and `rId3` to `rId9` tomorrow.
     */
    private static function freeRelationshipId(string $rels): string
    {
        preg_match_all('~Id="rId(\d+)"~', $rels, $found);

        $highest = 0;

        foreach ($found[1] as $id) {
            $highest = max($highest, (int) $id);
        }

        return 'rId' . ($highest + 1);
    }

    /**
     * A relationship added, or the part left as it was where it is already there.
     *
     * Guarded for the reason {@see self::addNotesMasterId()} is, and it needs to be: a package
     * can already name a notes master while its notes slide is not linked to one, which is the
     * half-linked state this pass exists to finish. Without the guard that package comes out
     * naming the same notes master twice — once from what it already had and once from here —
     * while `notesMasterIdLst`, which is guarded and so stays as it was, names only one.
     */
    private static function addRelationship(string $rels, string $id, string $type, string $target): string
    {
        $existing = self::parse($rels);

        foreach ($existing as $relationship) {
            if ($relationship === [$type, $target]) {
                return $rels;
            }
        }

        return self::relationships([...$existing, $id => [$type, $target]]);
    }

    /**
     * The relationships a `.rels` part already holds, as Id => type and target.
     *
     * Rebuilt rather than appended to so that a part this pass has written before is replaced
     * wholesale instead of gaining a second copy of everything it already had.
     *
     * @return array<string, array{string, string}>
     */
    private static function parse(string $rels): array
    {
        $found = [];

        preg_match_all(
            '~<Relationship Id="([^"]+)" Type="([^"]+)/(\w+)" Target="([^"]+)"/>~',
            $rels,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $found[$match[1]] = [$match[3], $match[4]];
        }

        return $found;
    }

    /**
     * `<p:notesMasterIdLst>` between the slide master list and the slide list.
     *
     * In that order because `CT_Presentation` is a sequence and not a choice: the element is
     * valid there and nowhere else, and a file that lists it after the slides has a presentation
     * element out of order however correct each list inside it is.
     */
    private static function addNotesMasterId(string $presentation, string $id): string
    {
        if (str_contains($presentation, '<p:notesMasterIdLst>')) {
            return $presentation;
        }

        return str_replace(
            '<p:sldIdLst>',
            sprintf('<p:notesMasterIdLst><p:notesMasterId r:id="%s"/></p:notesMasterIdLst><p:sldIdLst>', $id),
            $presentation,
        );
    }

    /**
     * `<Override>` entries for parts this pass adds, keyed by part name.
     *
     * Keyed on the part rather than the type because the theme type is already in the file for
     * `theme1.xml`: a check for the type would see it there and skip the notes theme's own entry,
     * which leaves a part with no content type and a deck that will not open.
     *
     * @param array<string, string> $types part name => content type
     */
    private static function addContentTypes(string $contentTypes, array $types): string
    {
        foreach ($types as $part => $type) {
            if (str_contains($contentTypes, sprintf('PartName="/%s"', $part))) {
                continue;
            }

            $contentTypes = str_replace(
                '</Types>',
                sprintf('<Override PartName="/%s" ContentType="%s"/></Types>', $part, $type),
                $contentTypes,
            );
        }

        return $contentTypes;
    }

    /**
     * A theme for the notes master to have, as a part name and the theme to put in it.
     *
     * Copied rather than shared, because this is what PowerPoint requires and nothing in the
     * package says so. A notes master whose theme relationship points at the slide master's
     * theme is well-formed, passes the ECMA-376 schemas, and is refused by PowerPoint on open —
     * and it is refused silently enough that the schema check reports the deck clean. What the
     * bisection that found this established: every other link in the chain, in every order and
     * spelling, left PowerPoint repairing the deck; giving the notes master a theme of its own
     * is what made it open. The copy is the same theme, so the note is still drawn in the deck's
     * fonts, which is the only reason sharing was ever tempting.
     *
     * A second copy is paid for rather than avoided because the alternative is a deck that does
     * not open. `ppt/theme/theme1.xml` is assumed because PHPPresentation writes exactly one
     * theme, and the file is named from the slide master's own relationship rather than assumed
     * so that a package with two themes cannot end up with the notes master's copy overwriting
     * one of them.
     *
     * @param list<string> $names
     *
     * @return array{0: string, 1: string}
     */
    private static function notesTheme(ZipArchive $zip, array $names, string $path): array
    {
        $source = 'ppt/theme/theme1.xml';

        foreach ($names as $name) {
            if (preg_match('~^ppt/slideMasters/_rels/slideMaster\d+\.xml\.rels$~', $name) === 1) {
                $rels = self::read($zip, $name, $path);

                if ($rels !== null && preg_match('~Target="\.\./(theme/theme\d+\.xml)"~', $rels, $found) === 1) {
                    $source = 'ppt/' . $found[1];
                }

                break;
            }
        }

        // Refused rather than written empty: a notes master pointing at a zero-byte theme is a
        // package PowerPoint repairs, and the repair is silent everywhere else.
        $theme = self::required($zip, $source, $path);
        $copy = self::freeThemeName($names);

        return [$copy, $theme];
    }

    /**
     * A theme part name nothing in the package is using.
     *
     * One above the highest already there, for the reason {@see self::freeRelationshipId()} gives
     * and not because themes are numbered consecutively by anything.
     *
     * @param list<string> $names
     */
    private static function freeThemeName(array $names): string
    {
        $highest = 0;

        foreach ($names as $name) {
            if (preg_match('~^ppt/theme/theme(\d+)\.xml$~', $name, $found) === 1) {
                $highest = max($highest, (int) $found[1]);
            }
        }

        return sprintf('ppt/theme/theme%d.xml', $highest + 1);
    }

    /**
     * A part this pass has to rewrite, refused rather than invented where it is not there.
     *
     * Writing an empty part in its place produces a package that is missing the very part the
     * link belongs in — a notes master with a relationship to a zero-byte theme, a presentation
     * with no slide list, a content-types part declaring nothing — and the failure surfaces in
     * PowerPoint rather than here. A deck in that state is already broken, so the honest answer
     * is to say so and leave it alone.
     *
     * @throws UnreadablePackage When the part is not in the package at all.
     */
    private static function required(ZipArchive $zip, string $part, string $path): string
    {
        $contents = self::read($zip, $part, $path);

        if ($contents === null) {
            throw UnreadablePackage::cannotRead($path, $part);
        }

        return $contents;
    }

    /**
     * A part's contents, or null where the archive holds no such part.
     *
     * Null and refusal are kept apart because they are different facts: a package with no
     * `presentation.xml.rels` has nothing to add a relationship to and nothing went wrong,
     * while a part that is present and cannot be decompressed is refused — a pass that wrote
     * over it would replace content it never read. {@see NotesColourMapPass::contents()} is
     * where the suppressed warning is explained.
     *
     * @throws UnreadablePackage When the part is in the package but cannot be read out of it.
     */
    private static function read(ZipArchive $zip, string $part, string $path): ?string
    {
        $index = $zip->locateName($part);

        if ($index === false) {
            return null;
        }

        set_error_handler(static fn (): bool => true);

        try {
            $contents = $zip->getFromIndex($index);
        } finally {
            restore_error_handler();
        }

        if ($contents === false) {
            throw UnreadablePackage::cannotRead($path, $part);
        }

        return $contents;
    }
}
