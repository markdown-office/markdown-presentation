<?php

declare(strict_types=1);

namespace MarkdownPresentation\Tests\Support;

use MarkdownPresentation\Document\DeckParser;
use MarkdownPresentation\Theme\Theme;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The README's examples, as tests.
 *
 * A fenced block immediately followed by a fenced block of expected output is a
 * claim: *this input converts to that*. The input block is run and the two are
 * compared exactly, so a change on either side of the page fails the suite — which
 * is the drift a hand-maintained example list cannot catch, and the reason this
 * exists rather than a file that lists the examples and what they do.
 *
 * Three input languages, all of them this library's own entry points:
 *
 * | Block      | Becomes                                            |
 * | ---------- | -------------------------------------------------- |
 * | `markdown` | a split deck, rendered by {@see DeckHtml}          |
 * | `php`      | the standard output of a script run in a subprocess |
 * | `yaml`     | a theme, and its colours and fonts                  |
 *
 * `php` runs out of process rather than inline so that a `throw` in an example is
 * a failure with a message rather than the end of the test run.
*/
final class Doctest
{
    private const INPUTS = ['markdown', 'php', 'yaml'];
    private const OUTPUTS = ['html', 'text'];

    /**
     * @return array<string, array{language: string, code: string, expected: string}>
    */
    public static function cases(string $readme): array
    {
        $blocks = self::blocks($readme);
        $cases = [];

        foreach ($blocks as $index => $block) {
            $next = $blocks[$index + 1] ?? null;

            if ($next === null || !in_array($block['language'], self::INPUTS, true)) {
                continue;
            }

            if (!in_array($next['language'], self::OUTPUTS, true)) {
                continue;
            }

            // A yaml block that documents a file the library ships is checked against
            // that file rather than converted, so the page cannot show a theme the
            // library does not have.
            if ($block['language'] === 'yaml' && $block['path'] !== null) {
                continue;
            }

            $cases[sprintf('%s block at line %d', $block['language'], $block['line'])] = [
                'language' => $block['language'],
                'code' => $block['code'],
                'expected' => $next['code'],
            ];
        }

        return $cases;
    }

    /**
     *
     * The YAML blocks that show a file the library ships, and the file each is read
     * from, for {@see \MarkdownPresentation\Tests\Readme\Examples::it()} to compare.
     *
     * @return array<string, array{code: string, file: string}>
    */
    public static function fileClaims(string $readme): array
    {
        $claims = [];

        foreach (self::blocks($readme) as $block) {
            if ($block['language'] !== 'yaml' || $block['path'] === null) {
                continue;
            }

            $claims[$block['path']] = ['code' => $block['code'], 'file' => self::root() . '/' . $block['path']];
        }

        return $claims;
    }

    /**
     *
     * What an input block converts to, as a string.
     *
     * The project root is written `<root>` in the output, so that an example
     * printing a path says the same thing on every machine and the page holds no
     * absolute path of anybody's checkout.
    */
    public static function run(string $language, string $code): string
    {
        $result = match ($language) {
            'markdown' => DeckHtml::render((new DeckParser())->parse($code)),
            'yaml' => self::theme($code),
            'php' => self::script($code),
            default => throw new RuntimeException(sprintf('No example language "%s".', $language)),
        };

        return str_replace(self::root(), '<root>', $result);
    }

    /**
     * A theme as a compact report of what it resolved to: the names, and the colours
     * and fonts the file named, in the spellings the library uses.
    */
    private static function theme(string $yaml): string
    {
        $data = Yaml::parse($yaml);
        $theme = Theme::fromArray(is_array($data) ? $data : []);

        $report = sprintf("name: %s\n", $theme->name());

        foreach ($theme->colours() as $slot => $colour) {
            $report .= sprintf("colors.%s: %s\n", $slot, $colour);
        }

        foreach ($theme->fonts() as $slot => $font) {
            $report .= sprintf("fonts.%s: %s\n", $slot, $font);
        }

        return $report;
    }

    private static function script(string $code): string
    {
        $path = Scratch::file('readme.php', "<?php\n\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n\n" . $code);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $path],
            $descriptors,
            $pipes,
            null,
            ['MARKDOWN_PRESENTATION_ROOT' => self::root()] + $_ENV,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to run the example.');
        }

        $out = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException('The example failed: ' . trim($errors));
        }

        return $out;
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     *
     * Every fenced block of a Markdown document, in order.
     *
     * A fence is closed by a run of at least as many backticks carrying no
     * information string, which is what lets a block contain a shorter fence — the
     * README shows a deck whose slide contains a code block, and the block around
     * it uses four backticks for exactly that reason.
     *
     * @return list<array{language: string, code: string, path: string|null, line: int}>
    */
    private static function blocks(string $markdown): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $blocks = [];

        for ($index = 0; $index < count($lines); $index++) {
            if (preg_match('/\A {0,3}(`{3,})([^`]*)\z/', $lines[$index], $open) !== 1) {
                continue;
            }

            $fence = $open[1];
            $info = trim($open[2]);
            $code = [];

            while (++$index < count($lines)) {
                if (preg_match('/\A {0,3}`{' . strlen($fence) . ',}\z/', rtrim($lines[$index])) === 1) {
                    break;
                }

                $code[] = $lines[$index];
            }

            [$language, $path] = self::info($info);

            $blocks[] = [
                'language' => $language,
                'code' => implode("\n", $code),
                'path' => $path,
                'line' => $index - count($code),
            ];
        }

        return $blocks;
    }

    /**
     *
     * The information string of a fence: its language, and a file path if the block
     * is introduced as `yaml path=themes/minimal/theme.yml`.
     *
     * @return array{0: string, 1: string|null}
    */
    private static function info(string $info): array
    {
        if ($info === '') {
            return ['', null];
        }

        if (preg_match('/\A(\w+)\s+path=(\S+)\z/', $info, $match) === 1) {
            return [$match[1], $match[2]];
        }

        return [explode(' ', $info)[0], null];
    }
}
