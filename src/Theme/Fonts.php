<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

/**
 * The faces a theme draws its words in.
 *
 * A theme names three of them — `heading`, `body` and `mono` — and a name that is none of
 * those is a family as written: a deck that writes `font-family: Inter` is drawn in Inter
 * whether or not the theme names it.
 *
 * Out of {@see Theme} because a face is not a theme: it is one of the parts a theme is made
 * of, and the rule that turns a name into one belongs with the faces rather than with the
 * colours and the metadata beside them.
 */
final class Fonts
{
    /**
     * @param array<string, string> $fonts Font slot to family name.
     */
    public function __construct(private readonly array $fonts)
    {
    }

    /**
     * Every face, keyed by the slot it fills.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->fonts;
    }

    /** One face, or null when the theme does not set the slot. */
    public function get(string $slot): ?string
    {
        return $this->fonts[$slot] ?? null;
    }

    /**
     * The face a name stands for: a slot of this theme's own, or the family as written.
     *
     * One place, because three things name a face the same way — a text style's `font`, a
     * placed box's `font-family` and a style written on a word — and three copies of the
     * rule are three answers to one question: a name that is a slot here and a literal
     * there is a face in one deck and a missing table in the next.
     */
    public function face(string $written): string
    {
        return $this->get($written) ?? $written;
    }
}
