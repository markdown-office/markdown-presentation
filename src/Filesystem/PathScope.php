<?php

declare(strict_types=1);

namespace MarkdownPresentation\Filesystem;

/**
 * The directories a path a deck wrote may point into, and the one question about it.
 *
 * A deck is text, and two of the things it writes are paths: `![alt](…)` and `theme:`.
 * Both used to be resolved by string concatenation — `rtrim($directory, '/') . '/' . $written`,
 * or the same with an absolute path taken as it stands — so `../` walked out of the deck's own
 * directory and any absolute path was read from wherever it pointed. The deck did not have to
 * be trying: a deck written by a model, from a file it was given, is enough.
 *
 * So a path a deck writes is resolved and has to land inside one of the roots here. The
 * roots are the renderer's directory by default, and whatever else the calling program
 * allowed — a curated image library is a real case, and it is the *caller's* decision
 * rather than the deck's, which is the whole distinction.
 *
 * `realpath()` rather than a string prefix because a prefix is not containment: `..` and a
 * symlink both defeat one, and a symlink pointing at `/etc/shadow` is the ordinary way a
 * path that looks harmless turns into a read of the wrong file. The one root `realpath()`
 * cannot serve is a directory inside the archive this library has been bundled into,
 * where the built-in themes live; that one is resolved by walking its own segments
 * instead. See {@see self::literal()}.
 */
final class PathScope
{
    /**
     * A `scheme://` prefix, which is a URL to `getimagesize()` and to `is_file()` rather than
     * a path at all.
     *
     * PHP hands either of those to the stream wrapper the name gives it: `phar://` reads an
     * archive out of the file system and unpacks it, `http://` makes the render a request,
     * and `expect://`/`zip://` do the rest of what a wrapper does. A deck cannot write a path
     * like that and mean it, so it is refused before the name reaches any of them.
     */
    private const SCHEME = '#\A[a-zA-Z][a-zA-Z0-9+.-]*://#';

    /** The archive prefix, which is the one wrapper this library can find itself inside. */
    private const PHAR = 'phar://';

    /**
     * @var list<string>
     */
    private readonly array $roots;

    /**
     * @param list<string> $roots The directories a deck-written path may resolve into, and
     *                           resolved once here rather than on every path: a directory that
     *                           does not exist when the scope is built is not one of them,
     *                           which is what keeps the roots and the paths they are compared
     *                           against in one spelling.
     */
    public function __construct(array $roots = [])
    {
        $resolved = [];

        foreach ($roots as $root) {
            if (($real = self::root($root)) !== null) {
                $resolved[] = $real;
            }
        }

        $this->roots = array_values(array_unique($resolved));
    }

    /**
     * A root in the spelling paths will be compared against.
     *
     * {@see self::canonical()} for a directory on disk, and {@see self::literal()} for one
     * inside the archive this is running from — which is what the built-in themes are when
     * this library has been bundled into a `.phar`, and is the case
     * {@see self::canonical()} cannot serve because `realpath()` does not resolve a
     * `phar://` path at all.
     */
    private static function root(string $path): ?string
    {
        return self::canonical($path) ?? self::literal($path);
    }

    /**
     * A path resolved by walking its own segments, for one the file system cannot resolve.
     *
     * Only for a path inside this library's own archive, and that restriction is the
     * reason this is safe: a deck cannot hand us a `phar://` path, because
     * {@see self::isUrl()} refuses every `scheme://` name before it gets here, so the only
     * archive paths this ever sees are the ones `__DIR__` produced.
     *
     * The `..` handling is what keeps it a boundary rather than a formality. Without it a
     * theme named `../../../etc` would resolve lexically to a path outside the archive —
     * inside a `phar://` URL, so not a filesystem read, but still a root that holds things
     * it must not.
     */
    private static function literal(string $path): ?string
    {
        if (!str_starts_with($path, self::PHAR)) {
            return null;
        }

        // `phar://` plus the archive's own path, which may itself contain slashes, plus
        // the path inside it. The split point is the first slash after the archive.
        $rest = substr($path, strlen(self::PHAR));
        $split = explode('/', $rest, 2);
        $archive = $split[0];
        $segments = [];

        foreach (explode('/', $split[1] ?? '') as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : self::PHAR . $archive . '/' . implode('/', $segments);
    }

    /**
     * A path this library's own directory resolved, however it had to be resolved.
     *
     * For the built-in themes, which are on disk under a checkout and inside the archive
     * when the library has been bundled into a `.phar`. Null where neither resolved,
     * which every caller here reads as "there is no such thing".
     */
    public static function resolve(string $path): ?string
    {
        return self::root($path);
    }

    /**
     * Whether a path a deck wrote names a stream wrapper rather than a file.
     */
    public static function isUrl(string $written): bool
    {
        return preg_match(self::SCHEME, $written) === 1;
    }

    /**
     * A path with `..` and symlinks resolved, or null where there is no such file.
     *
     * Null rather than the path itself on failure, because every caller here is asking
     * whether a real file is there: a caller that only wanted a canonical spelling would be
     * asking `realpath()` and catching `ValueError`, which is what it throws for a path
     * holding a null byte — and a deck can write one.
     */
    public static function canonical(string $path): ?string
    {
        try {
            $real = realpath($path);
        } catch (\ValueError) {
            // A null byte in the name, which is a `ValueError` rather than a false and is
            // the one input here that is a deck rather than a file system.
            return null;
        }

        return $real === false ? null : $real;
    }

    /**
     * Whether a canonical path is inside one of the roots.
     *
     * The roots themselves count as inside: a caller may allow one file rather than a
     * directory, and a theme directory that *is* the working directory is a strange but
     * contained thing to be named.
     */
    public function holds(string $path): bool
    {
        foreach ($this->roots as $root) {
            if ($path === $root || str_starts_with($path, self::prefixed($root))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The roots as they were resolved, for a failure that has to name what would have
     * worked.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /** The root with a separator on the end, so `/srv/deck` cannot contain `/srv/deck-elsewhere`. */
    private static function prefixed(string $root): string
    {
        return str_ends_with($root, '/') ? $root : $root . '/';
    }
}
