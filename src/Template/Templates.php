<?php

declare(strict_types=1);

namespace MarkdownPresentation\Template;

/**
 * The templates a deck has, and the rule that decides which of them it has.
 *
 * A template may be written in the deck or imported from a file beside it, and the two are
 * told apart by where they come from: the deck's own templates override the imported ones
 * of the same name. It is the rule the theme resolver applies by search order — the first
 * one found under a name is the one that is used — and it is here rather than in the
 * resolver because a templates file is not a theme and is not found by looking through
 * directories: a deck names the one file it wants.
 */
final class Templates
{
    /**
     * @param array<string, Definition> $definitions
     */
    private function __construct(private readonly array $definitions)
    {
    }

    /**
     * The templates a deck has: its own over the imported ones.
     *
     * The imported ones first and the deck's own after, so that the deck's own is the one
     * that is left under a name both of them use.
     *
     * @param list<Definition> $local    The templates the deck defines.
     * @param list<Definition> $imported The templates a templates file defines.
     */
    public static function of(array $local, array $imported): self
    {
        $definitions = [];

        foreach ([...$imported, ...$local] as $definition) {
            $definitions[$definition->name()] = $definition;
        }

        return new self($definitions);
    }

    /**
     * Every template the deck has, by name.
     *
     * @return array<string, Definition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * The names of the templates the deck has, sorted, for a message that has to name them.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->definitions);
        sort($names);

        return $names;
    }

    /**
     * Whether the deck has a template under this name.
     */
    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }
}
