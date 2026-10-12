# Contributing

How this library is developed and verified. The README says what the library does; this says
what has to be true of a change to it.

## Conformance

Splitting is derived from `parseSync` in `slidev/packages/parser/src/core.ts`, pinned
at commit `11fe3f79`. Every deck in `tests/fixtures/slidev/expected.json` was run
through that real parser and recorded, so parity is checked against the thing rather
than against a reading of it. `tools/slidev-parity/regenerate.sh` re-records it.

```
94 decks · 77 split identically · 17 declared divergences
```

The 17 are in `tests/fixtures/slidev/divergences.json`, each with the reason and the
output this library produces instead. The test fails if a declared divergence stops
being one, so a strictness cannot quietly change.

What is kept, because it is the grammar worth having:

| Behaviour | Kept |
| --- | --- |
| `---` closes a slide | yes |
| `***` works as a thematic break inside a slide | yes |
| Deck frontmatter only at the top; later blocks are per-slide | yes |
| Speaker notes as trailing comments | yes |

What is not, because Slidev gets it wrong rather than choosing differently:

| Behaviour | Slidev | Here |
| --- | --- | --- |
| `~~~` as a fence | not a fence | a fence |
| Closing fence indented less than the opener | does not close | closes |
| Closing fence carrying anything after it | closes the block | does not |
| A fence that is never closed | leaves the block; a `---` inside splits the deck | runs to the end of the deck |

The last one is the one that matters. A missing backtick is a typo, and under
Slidev's rule it silently splits the deck and leaves the next slide opening with a
literal `---`. These four are CommonMark implemented incorrectly, not a dialect worth
preserving, so taking them would have cost a correctness bug for the sake of a parity
number.

The remaining divergences are all strictness, all one-way: malformed YAML, a
frontmatter block that is not a mapping, a `title` or `level` of the wrong type, and
a slide opening with a fenced ```` ```yaml ```` block. Those refuse rather than guess.

## The rendering slice

Splitting has an oracle. Rendering has none — there is no other implementation to be
parity-checked against — so every claim it makes is checked against a part of the file it
wrote, and each row below is a test in `tests/Unit/rendering.php` or
`tests/Unit/layouts-and-steps.php`.

Nothing compares two `.pptx`. A zip stores two-second timestamps in every entry and
`docProps/core.xml` carries `dcterms:created` and `dcterms:modified`, so two correct
renders of one deck differ; the parts are what a test reads.

| Claim | Read off |
| --- | --- |
| One slide part per slide of the deck, and every one declared | `[Content_Types].xml`, `ppt/slides/` |
| One slide holds every step, and each click reveals the shapes of one | `<p:timing>` |
| A `layout:` role becomes a slide layout part named by the theme | `ppt/slideLayouts/`, `ppt/slides/_rels/` |
| One shape per run of text and per block of every other kind, named after it | `<p:cNvPr name="…">` |
| The theme's twelve colours become the colour scheme | `ppt/theme/theme1.xml` |
| A dark theme swaps the colour map, and its shapes follow it | `ppt/slideMasters/slideMaster1.xml`, `ppt/slides/slideN.xml` |
| The theme's `heading`, `body` and `mono` reach the runs that use them | `<a:latin typeface="…">` |
| `styles.yml` reaches no shape, because a `.pptx` has no use for a `.docx` style id | — |

The last row is the only one asserted by what is *absent*. `styles.yml` is not read, and
a slot that does not exist still fails when the theme is loaded, so a typo in one is never
rendered as silence — which is a test, but a test of the theme loader rather than of a
rendered file.

Reading has the opposite problem, and it is where the fixtures earn their place. Everything
above is checked against files this library wrote, which agree with the reader about every
convention that matters. `tests/fixtures/powerpoint/` holds two decks that PowerPoint itself
authored and saved — one designed, one on A4 with a table on a layout that has no table in it —
and `tests/Unit/powerpoint-fixtures.php` reads those instead. A reader that has only ever seen
this library's own templates is a reader nobody has shown works on a file a person made, and the
first of those tests is the bug that proved the point: PowerPoint writes *Title and Content*'s
content placeholder as a bare `<p:ph idx="1"/>`, so a reader matching the `type` attribute as
written inherited nothing from the master and left the layout with a title box and no body.

What the renderer refuses is refused loudly, and each refusal names what would have
worked instead: a directive nobody reads, a theme that does not resolve, a layout role
that is not one, a step marker that does not go forwards, an image that is not a file,
and an image no `.pptx` can carry. Those messages are listed one by one in
`tests/Unit/exceptions.php`, which finds the exception classes by reading the directory
rather than by a list that would quietly stop being true.

## Rendering in the real PowerPoint

Everything above is checked against the parts of the file this library wrote. There is no
other implementation to check a render against, and LibreOffice — which `examples/build.php`
uses for its previews — is a *different* implementation of the same format, so it renders what
it believes the format means rather than what PowerPoint means.

For the one question that asks for PowerPoint itself, `tools/render-with-powerpoint.applescript`
opens each deck in Microsoft PowerPoint and exports it to PDF:

```
osascript tools/render-with-powerpoint.applescript examples/out/*.pptx --out build/powerpoint
```

It is a developer tool and nothing in the test suite requires it: continuous integration has no
PowerPoint, and a test that needed one would be a test that could only be run on one person's
machine. Every step either produces the file it promised or exits non-zero with the reason —
a deck that opened and exported nothing is a failure, and so is a directory that cannot be
written, a deck that is not there and a `--out` that is missing.

Three things about it are worth knowing before running it:

- **It stages each deck inside PowerPoint's own sandbox container.** Office applications are
  sandboxed and refuse to read a path the user has not granted them with error `-9074`, so the
  deck is copied in, exported there and copied back out. The scratch directory is removed on
  every path through the script, including every failure.
- **It closes only the decks it opened.** A deck somebody had open before the script ran is
  still open afterwards.
- **It can fail because PowerPoint is in a bad state.** `-9074` from a deck already staged
  inside the container means PowerPoint could not open *any* document — most often a window it
  had not finished starting. Quitting PowerPoint and running it again is the fix, and the
  script says so rather than reporting a permission problem.

`build/` is the output directory in the command above, and it is git-ignored. Nothing this
writes lands in a deck.

### Word is not an alternative

`/Applications/Microsoft Word.app` is Microsoft 365 Word, and Word cannot open a
presentation. A `.pptx` is OOXML from Office 2007 and Word was never a PowerPoint product, so
it would import the file through a lossy converter and report nothing about whether the deck
is valid. PowerPoint is the only application on this machine that will tell the truth about
whether these decks open.

## What a change has to pass

```
composer validate
vendor/bin/pest
XDEBUG_MODE=coverage vendor/bin/pest --coverage --min=100
sh tools/phpstan.sh
php examples/build.php
```

`composer validate` is run **without** `--strict` on purpose: the manifest pins a commit of
PHPPresentation's `master` branch rather than a tag, and `--strict` refuses that.

Coverage is 100% and PHPStan is level 9. Neither a baseline nor an `ignoreErrors` entry nor a
suppression comment: a finding is fixed or left red with a reason. The analyser is fetched by
checksum by `tools/install-phpstan.php` rather than added as a dependency, and the hash is
recorded in that script and in the job that uses it.

### PHP 8.2

The library promises PHP 8.2, so that is what every change is linted with, whatever version
happens to be first on `PATH`:

```
/opt/homebrew/opt/php@8.2/bin/php -l src/SomeClass.php
```

This is not hypothetical. One line of PHP 8.4-only syntax shipped on this branch and broke
282 tests on the 8.2 job with a green local run. `new X()->y()` on one expression is the syntax
in question: it parses under a tool that was not asked to check the version, and not at all
under 8.2.

### Comments

The policy is in `AGENTS.md` and `tests/Unit/dead-code.php` enforces the part of it a test can
check. Three of its rules are tests rather than notes, and a change that trips one of them is
wrong rather than merely untidy:

- no divider lines above a run of methods;
- no file in a test suite directory importing a global-namespace name, because under PCOV that
  costs the whole run its coverage report — every test passes and the job exits non-zero with
  no table;
- no constant declared in two test files, for the same reason.

A fourth test is the one to read before editing a comment: `tests/Unit/dead-code.php` holds
phrases this package has been wrong about, and a change that reintroduces one fails the suite.
Matching is case-insensitive, because a guard that a capital letter walks past is not a guard.

### Tests

- **A new test must fail without its fix.** Run it against the reverted change. A test that
  passes either way proves nothing, and it reads as coverage, which is worse than having none.
- **Never compare two `.pptx`.** Zips carry timestamps; compare parts.
- **Assert preservation, not defaults, for option objects.** Set every property away from the
  default, change one, assert the rest survived.
- **A method with no caller outside `tests/` is dead, or public API nobody documented.** Say
  which.
- **A claim about how output looks is not established by reading markup.** Render it and look.

### Worktrees

Put a worktree inside its own repository, at `<repo>/.worktrees/<name>`, and give each its own
branch: two `composer` or `pest` runs in one checkout share `vendor/`, `.phpunit.cache` and the
autoloader's class map, so they fail each other in ways that read as real failures. Remove it
when the branch is merged.

## Licence

MIT. See [LICENSE](./LICENSE).
