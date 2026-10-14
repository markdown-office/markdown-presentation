<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnreadablePackage;
use ZipArchive;

/**
 * Writes the click-triggered build for a slide whose steps are shapes on one slide.
 *
 * A step used to be a slide of its own, which meant a build of twenty steps was twenty copies
 * of the same slide in the package and twenty clicks to walk through it. PowerPoint builds a
 * slide up without that, by naming the shapes that appear at each click in a `<p:timing>`
 * element on the one slide — and the shape is then on the slide from the start, hidden until
 * its click arrives.
 *
 * The markup below was written from what PowerPoint writes, not from a description of the
 * format: `tests/fixtures/powerpoint/entrance-animation.pptx` is a deck PowerPoint authored,
 * and `tests/Unit/timing.php` reads the timing back out of it. Three things in it are the
 * whole of what this file has to get right, and none of them can be reasoned out from the
 * others:
 *
 * - A **step** is one `<p:par>` whose `<p:cond delay="indefinite"/>` waits for a click, and it
 *   holds one `<p:par>` per **shape**. A shape's effect is `nodeType="clickEffect"` when it
 *   opens the step and `nodeType="withEffect"` when it joins one already open, which is the
 *   difference between a step and one click per shape. PowerPoint writes both as the same
 *   effect; only that attribute separates them.
 * - `<p:cTn>` ids are unique per slide and are allocated in document order: two for the step
 *   that waits, then two per shape. Nothing in the file has to agree with them, so they are
 *   counted here rather than looked up.
 * - A shape is named by its own id in `<p:spTgt spid="N">` and listed in `<p:bldLst>` as
 *   `<p:bldP spid="N" grpId="0"/>`. PHPPresentation numbers shapes by position — its
 *   `writeShapeCollection()` starts at 1 and increments before it uses the number, and the
 *   shape tree's own group is what takes that 1 — so a shape's id is where it sits in the
 *   slide's shape collection. {@see PlaceholderPass::FIRST_ID} is the same number, for the
 *   same reason.
 *
 * `appear` is the effect written: `presetID="1" presetClass="entr"`, which is a `<p:set>` of
 * `style.visibility` to `visible` and nothing else. Nothing moves and nothing fades, so the
 * thing a reader sees is a shape that was not there and then is.
 *
 * Nothing here draws. A slide with no steps is left as the writer wrote it, and so is one that
 * already carries a `<p:timing>` — this library's writer emits neither, and a pass that
 * doubled either would be a slide a reader opens to a repair prompt.
 */
final class TimingPass
{
    /**
     * The first `<p:cTn>` id a step may use: 1 is the root and 2 the main sequence, and both
     * are written by {@see self::timing()} whether or not anything else is.
     */
    private const FIRST_ID = 3;

    /**
     * What advances the sequence. Written on the sequence rather than on any step, because a
     * step's own `<p:cond delay="indefinite"/>` is what a click waits on.
     */
    private const ADVANCE = '<p:prevCondLst><p:cond evt="onPrev" delay="0"><p:tgtEl><p:sldTgt/></p:tgtEl>'
        . '</p:cond></p:prevCondLst><p:nextCondLst><p:cond evt="onNext" delay="0"><p:tgtEl><p:sldTgt/>'
        . '</p:tgtEl></p:cond></p:nextCondLst>';

    /**
     * Rewrites the package in place, and reports how many parts it changed.
     *
     * @param array<int, list<list<int>>> $builds Slide number, counting from 1, to the steps on
     *        that slide, each step to the ids of the shapes it shows. A slide no entry names is
     *        left alone, which is how a deck that builds up only some of its slides comes out
     *        with timing on those and none on the rest.
     *
     * @param ZipArchive|null $archive An archive to work in rather than one this opens, and
     *        the way a test reaches the refusal below: a damaged payload is not a substitute,
     *        because whether one refuses to inflate is the build's own business. See
     *        {@see NotesColourMapPass::applyTo()} for why the reader has to be told.
     *
     * @throws UnreadablePackage When the presentation cannot be opened for writing, or a slide
     *         cannot be read out of it. The same refusals {@see PlaceholderPass::applyTo()}
     *         makes on the same file, so a caller catches one type for every pass rather than
     *         one for each.
     */
    public static function applyTo(string $path, array $builds, ?ZipArchive $archive = null): int
    {
        $zip = $archive ?? new ZipArchive();

        if ($zip->open($path) !== true) {
            throw UnreadablePackage::cannotOpen($path);
        }

        // Every part is read before any of them is written, for the reason
        // {@see NotesColourMapPass::applyTo()} gives at length: `deleteName()` is deferred, so
        // a rewrite inside the walk grows the archive under the index being walked and cannot
        // end. The count is taken before the loop for the same reason.
        $pending = [];

        for ($index = 0, $count = $zip->numFiles; $index < $count; $index++) {
            $part = (string) $zip->getNameIndex($index);

            if (preg_match('~^ppt/slides/slide(\d+)\.xml$~', $part, $match) !== 1) {
                continue;
            }

            $xml = self::contents($zip, $index);

            if ($xml === false) {
                $zip->close();

                throw UnreadablePackage::cannotRead($path, $part);
            }

            $patched = self::addTiming($xml, $builds[(int) $match[1]] ?? []);

            if ($patched !== $xml) {
                $pending[$part] = $patched;
            }
        }

        foreach ($pending as $part => $patched) {
            $zip->deleteName((string) $part);
            $zip->addFromString((string) $part, $patched);
        }

        $zip->close();

        return count($pending);
    }

    /**
     * The part itself, with the build written into it — and returned unchanged if there are no
     * steps, if it already carries a `<p:timing>`, or if it is not a slide part this can find
     * the end of. Running this twice is therefore harmless, and so is running it on a deck
     * that has no build at all.
     *
     * @param list<list<int>> $steps
     */
    public static function addTiming(string $xml, array $steps): string
    {
        // A step nothing is written on is not a step: it would be a click that reveals nothing,
        // which is the same reason a marker on a slide's last line does not make a slide of its
        // own. Dropped rather than written as an empty branch, which is legal and does nothing
        // at all — the reader spends a click on it and sees no change.
        $steps = array_values(array_filter(
            $steps,
            static fn (array $shapes): bool => $shapes !== [],
        ));

        // `CT_Slide` orders its children `cSld`, `clrMapOvr`, `transition`, `timing`, `extLst`,
        // and this library's writer emits only the first two, so the end of the element is the
        // only place a `<p:timing>` can go. Written anywhere else it is a file that is invalid
        // for a stricter reason than the one being fixed.
        $end = $steps === [] || str_contains($xml, '<p:timing')
            ? false
            : strrpos($xml, '</p:sld>');

        if ($end === false) {
            return $xml;
        }

        return substr_replace($xml, self::timing($steps), $end, 0);
    }

    /**
     * The whole `<p:timing>` element for a slide, as PowerPoint writes one.
     *
     * @param list<list<int>> $steps
     */
    private static function timing(array $steps): string
    {
        $branches = '';
        $listed = '';
        $id = self::FIRST_ID;

        foreach ($steps as $shapes) {
            // The step's own ids come first because it is written before them, and ids are
            // allocated in document order.
            $step = $id;
            $id += 2;
            $effects = '';

            foreach ($shapes as $at => $shape) {
                $effects .= self::effect($id, $shape, $at === 0);
                $id += 2;
            }

            $branches .= self::step($step, $effects);

            foreach ($shapes as $shape) {
                $listed .= sprintf('<p:bldP spid="%d" grpId="0"/>', $shape);
            }
        }

        return '<p:timing><p:tnLst><p:par>'
            . '<p:cTn id="1" dur="indefinite" restart="never" nodeType="tmRoot"><p:childTnLst>'
            . '<p:seq concurrent="1" nextAc="seek">'
            . '<p:cTn id="2" dur="indefinite" nodeType="mainSeq"><p:childTnLst>'
            . $branches
            . '</p:childTnLst></p:cTn>'
            . self::ADVANCE
            . '</p:seq></p:childTnLst></p:cTn></p:par></p:tnLst>'
            . '<p:bldLst>' . $listed . '</p:bldLst></p:timing>';
    }

    /**
     * One step: the branch that waits for the click, and the one branch inside it that the
     * shapes are written into. Two ids, which is why the effects above take the ids below
     * theirs rather than the other way round.
     */
    private static function step(int $id, string $effects): string
    {
        return sprintf(
            '<p:par><p:cTn id="%1$d" fill="hold"><p:stCondLst><p:cond delay="indefinite"/></p:stCondLst>'
            . '<p:childTnLst><p:par><p:cTn id="%2$d" fill="hold">'
            . '<p:stCondLst><p:cond delay="0"/></p:stCondLst><p:childTnLst>%3$s</p:childTnLst>'
            . '</p:cTn></p:par></p:childTnLst></p:cTn></p:par>',
            $id,
            $id + 1,
            $effects,
        );
    }

    /**
     * One shape appearing, which takes two ids: the effect's own and the one its behaviour
     * runs on.
     *
     * `nodeType` is the only thing that makes a shape part of the click before it rather than
     * one of its own — PowerPoint writes `clickEffect` and `withEffect` identically apart from
     * it, and a build whose second bullet waits for a second click is a build that is wrong in
     * a way no reader of the markup can see.
     */
    private static function effect(int $id, int $shape, bool $opens): string
    {
        return sprintf(
            '<p:par><p:cTn id="%1$d" presetID="1" presetClass="entr" presetSubtype="0" fill="hold" grpId="0"'
            . ' nodeType="%3$s"><p:stCondLst><p:cond delay="0"/></p:stCondLst><p:childTnLst>'
            . '<p:set><p:cBhvr><p:cTn id="%2$d" dur="1" fill="hold">'
            . '<p:stCondLst><p:cond delay="0"/></p:stCondLst></p:cTn>'
            . '<p:tgtEl><p:spTgt spid="%4$d"/></p:tgtEl>'
            . '<p:attrNameLst><p:attrName>style.visibility</p:attrName></p:attrNameLst></p:cBhvr>'
            . '<p:to><p:strVal val="visible"/></p:to></p:set></p:childTnLst></p:cTn></p:par>',
            $id,
            $id + 1,
            $opens ? 'clickEffect' : 'withEffect',
            $shape,
        );
    }

    /**
     * A part's contents, or false where the archive cannot produce them.
     *
     * An entry whose payload does not decompress raises a warning before `ZipArchive` answers
     * false, and the refusal above is the answer a caller gets either way: the warning is noise
     * about a file this method is about to refuse.
     */
    private static function contents(ZipArchive $zip, int $index): string|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $zip->getFromIndex($index);
        } finally {
            restore_error_handler();
        }
    }
}
