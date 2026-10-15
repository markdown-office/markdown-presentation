<?php

declare(strict_types=1);

namespace MarkdownPresentation\Render\Free;

/**
 * The names this format draws in the body of a slide, and what each one is.
 *
 * The vocabulary is a directory of {@see ShapeKind} cases rather than a table here, so the
 * name a deck writes, the kind it draws and the shape the kind becomes are one fact rather
 * than three that have to agree. {@see self::names()} reads the cases back for a refusal
 * message, which is why it can never name a shape this library does not draw — or miss one it
 * does.
 *
 * Names are matched as they are read elsewhere in the body of a slide: folded and trimmed, so
 * `::Rectangle` and `::rectangle ` are the same directive. A deck cannot be asked to care
 * about case, and a refusal about the spelling of a name is a failure about the wrong thing.
 */
final class FreeShapes
{
    /**
     * The kind a name asks for, or null where nothing here draws it.
     */
    public static function kindOf(?string $name): ?ShapeKind
    {
        return $name === null ? null : ShapeKind::tryFrom(strtolower(trim($name)));
    }

    /**
     * Every name this format draws, sorted, for a message that has to name them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_map(static fn (ShapeKind $kind): string => $kind->value, ShapeKind::cases());
        sort($names);

        return $names;
    }
}
