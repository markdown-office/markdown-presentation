<?php

declare(strict_types=1);

namespace MarkdownPresentation\Theme;

/**
 * The master a theme carries: the `template.pptx` its layouts are drawn in.
 *
 * A source that holds the master as a file gives a path, and the master is opened where it
 * is. A source that holds only the bytes has them staged into a file of the library's own
 * — a `.pptx` is a package, and a package is opened as a file — and that file is the
 * library's to remove.
 *
 * The staged file belongs to the render it was staged for, and the render is over when it
 * goes: the master gives it back in {@see self::release()}, and a caller who lets the
 * master go without saying so has it said for them.
 */
final class Master
{
    /** The prefix of a staged master, so a file in flight is recognisable as one. */
    private const PREFIX = 'mdpres_master_';

    private function __construct(
        private readonly string $path,
        private readonly string $name,
        private bool $owned,
    ) {
    }

    /**
     * The theme's own master, opened where it is.
     *
     * @param string $name What a failure calls it, where the path is not the name it is
     *                     known by.
     */
    public static function at(string $path, string $name): self
    {
        return new self($path, $name, false);
    }

    /**
     * The source's bytes, staged into a file of the library's own.
     *
     * The file is made with `tempnam()`, so its name is one nobody guessed and its mode is
     * one nobody else on the machine can read. A file that could not be written is left as
     * it is: the reader that opens the master refuses it and says so, which is a better
     * sentence than one about a temporary directory.
     */
    public static function of(string $bytes, string $name): self
    {
        // A file that could not be made leaves a path that is not there, and the reader
        // that opens the master refuses it and says so.
        $path = @tempnam(sys_get_temp_dir(), self::PREFIX) ?: sys_get_temp_dir() . '/' . self::PREFIX . bin2hex(random_bytes(8));

        @file_put_contents($path, $bytes);

        return new self($path, $name, true);
    }

    /** The path the master is opened at. */
    public function path(): string
    {
        return $this->path;
    }

    /** What a failure calls the master. */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Give back the file the library staged, where it staged one.
     *
     * The theme's own master is not the library's to remove, so it stays where it is.
     */
    public function release(): void
    {
        if ($this->owned) {
            // Given back once: the render asks for it and the destructor asks again, and a
            // second removal of a file that is gone is a warning nobody can act on.
            $this->owned = false;

            if (is_file($this->path)) {
                @unlink($this->path);
            }
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
