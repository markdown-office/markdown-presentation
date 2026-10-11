<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

use RuntimeException;

/**
 * The harness could not get at what a test asked it for.
 *
 * Every throw of these is the suite talking about itself: a scratch directory
 * that would not be created, a fixture that would not be written, a README
 * example that would not run, and an archive that is not the presentation the
 * test was written to read. None of them is a failure of the library, so none
 * of them is a {@see \MarkdownPresentation\Exception\Exception} — a test that
 * caught the base would be reporting a passing render as a broken one.
 *
 * A dedicated type rather than `RuntimeException` so that the two are told apart
 * at the catch. `Pest` reports an uncaught throw as a test failure either way,
 * and the message says which of these it was; what the type buys is a caller
 * that wants to skip a test whose fixture could not be built rather than fail it.
 *
 * It lives here and not in `src/Exception/` for the reason
 * {@see \MarkdownPresentation\Tests\Fixtures\PowerPointFixtureFailed} gives:
 * `src/` is measured for coverage at 100% and nothing a consumer of this
 * package runs loads this file, so a class there would be one no test could
 * reach.
 */
final class FixtureFailed extends RuntimeException
{
    /** A path the test expected the library to have written a presentation to. */
    public static function noPresentationAt(string $path): self
    {
        return new self(sprintf('No presentation was written to "%s".', $path));
    }

    /**
     * @param list<string> $parts What the archive does hold, which is in the message
     *                            because a test that expected a slide and got none
     *                            should not have to go looking for which are there.
     */
    public static function noPart(string $name, string $path, array $parts): self
    {
        return new self(sprintf(
            'No part "%s" in "%s". The parts are: %s.',
            $name,
            basename($path),
            implode(', ', $parts),
        ));
    }

    public static function notAZip(string $path): self
    {
        return new self(sprintf('"%s" is not a readable zip.', $path));
    }

    public static function noDirectory(string $directory): self
    {
        return new self(sprintf('Unable to create the scratch directory "%s".', $directory));
    }

    public static function noTemplateAt(string $path): self
    {
        return new self(sprintf('Unable to write the template at "%s".', $path));
    }

    /** A fence in the README the {@see Doctest} does not know how to run. */
    public static function noExampleLanguage(string $language): self
    {
        return new self(sprintf('No example language "%s".', $language));
    }

    public static function noProcess(): self
    {
        return new self('Unable to run the example.');
    }

    public static function exampleFailed(string $errors): self
    {
        return new self('The example failed: ' . trim($errors));
    }
}