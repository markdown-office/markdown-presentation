<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render;

use MarkdownPresentation\Exception\UnreadablePackage;
use MarkdownPresentation\ThemePart;
use ZipArchive;

/**
 * Names the theme a deck was drawn in, in the theme part of the package.
 *
 * A `.pptx` carries the theme's colours and its fonts and nothing else about it: the name a
 * deck's `theme:` names is a property of the Markdown the deck was written from, and nothing
 * in the package said which directory it came from. So a deck read back into Markdown came
 * back in the library's own theme rather than the one it was drawn in, and the difference is
 * everything the theme is.
 *
 * The name is written where PowerPoint writes the name of a theme: the `name` attribute of
 * the `a:theme` element in the theme part. PHPPresentation writes a random one there —
 * `Theme32` — because it has no theme to name, and this pass replaces it with the name the
 * deck was drawn in.
 *
 * The theme's *title* is not what goes here. A theme directory is called `pitch` whatever its
 * `theme.yml` says it is called, and a deck that says `theme: pitch` means the directory
 * called `pitch` — so the name that travels is the one a deck can use again.
 *
 * Only the slide master's theme is named. A deck carries a second theme for its notes master,
 * which is a copy of the first, and a name on the copy would say the notes master was drawn
 * in a theme of its own.
 */
final class ThemeNamePass
{
    /**
     * The `name` attribute of the `a:theme` element.
     *
     * The first one only: the element carries one, and the colour scheme inside it carries
     * another, and the theme's name is the first.
     */
    private const NAME = '~(<a:theme\b[^>]*\bname=")[^"]*(")~';

    /**
     * Names the theme in the package at `$path`, and reports whether it changed.
     *
     * @param ZipArchive|null $archive An archive to work in rather than one this opens.
     *        {@see NotesColourMapPass::applyTo()} is where that is explained.
     *
     * @throws UnreadablePackage When the presentation cannot be opened for writing, or a part
     *         cannot be read out of it.
     */
    public static function applyTo(string $path, string $name, ?ZipArchive $archive = null): int
    {
        $zip = $archive ?? new ZipArchive();

        if ($zip->open($path) !== true) {
            throw UnreadablePackage::cannotOpen($path);
        }

        $part = ThemePart::of($zip);

        if ($part === null) {
            $zip->close();

            return 0;
        }

        $xml = self::contentsOf($zip, $part);

        if ($xml === false) {
            $zip->close();

            throw UnreadablePackage::cannotRead($path, $part);
        }

        $patched = self::rename($xml, $name);

        if ($patched !== $xml) {
            $zip->deleteName($part);
            $zip->addFromString($part, $patched);
        }

        $zip->close();

        return $patched === $xml ? 0 : 1;
    }

    /**
     * The theme part with the theme's name on it.
     *
     * Public so that the naming can be stated on its own; {@see self::applyTo()} is the only
     * caller outside tests.
     */
    public static function rename(string $xml, string $name): string
    {
        $escaped = htmlspecialchars($name, ENT_QUOTES | ENT_XML1);

        $renamed = preg_replace(self::NAME, '$1' . $escaped . '$2', $xml, 1);

        return $renamed ?? $xml;
    }

    /**
     * A part's contents, or false where the archive cannot produce them.
     *
     * {@see NotesColourMapPass::contents()} is where the suppressed warning is explained.
     */
    private static function contentsOf(ZipArchive $zip, string $name): string|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $zip->getFromName($name);
        } finally {
            restore_error_handler();
        }
    }
}
