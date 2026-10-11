<?php

declare(strict_types=1);

/*
 * The failure the fixture builder reports: a `.pptx` it could not write, or could not read back
 * clean.
 *
 * Extends this package's own {@see \MarkdownPresentation\Exception\Exception} rather than
 * RuntimeException, so the one catch the README documents covers a failure of the tool as well as
 * a failure of the library. It lives here rather than in `src/Exception/` because it is the build
 * tool's failure and not the library's: nothing a consumer of this package can catch, because
 * nothing a consumer runs loads this file. `src/` is measured for coverage and this is not run by
 * the suite at all, so a class there would be a permanently uncovered one.
 *
 * Required by path rather than autoloaded — PSR-4 would expect it under `tests/Fixtures/...` and
 * this directory is `tests/fixtures/...`, and a file that only resolves on a case-insensitive
 * filesystem is a file that stops resolving on the next one.
 */
final class PowerPointFixtureFailed extends MarkdownPresentation\Exception\Exception
{
}
