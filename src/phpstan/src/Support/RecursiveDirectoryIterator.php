<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator as SplRecursiveDirectoryIterator;
use SplFileInfo;

use function dirname;
use function realpath;
use function strlen;

/**
 * A \RecursiveDirectoryIterator that skips the redundant first rewind().
 *
 * SPL directory iterators read a first buffer of entries when the handle is
 * constructed, and iteration then opens with a rewind(). On filesystems
 * whose rewinddir() silently ignores a partially read handle — the 9p
 * mounts used by WSL2 and Docker Desktop on Windows
 * (https://github.com/microsoft/WSL/issues/5074) — that rewind does not
 * seek back but the already-buffered chunk is discarded anyway, so files
 * silently go missing from the scan.
 *
 * A freshly constructed iterator is already positioned at the first entry,
 * so that first rewind() has nothing to do and skipping it is safe on every
 * filesystem. next() restores normal rewind behavior, so an iterator that
 * is reused still starts over from the top. Children created by
 * getChildren() are instances of this class and skip their own first
 * rewind() the same way.
 *
 * This is the approach symfony/finder takes for non-rewindable streams —
 * see Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator.
 */
class RecursiveDirectoryIterator extends SplRecursiveDirectoryIterator
{
    private bool $ignoreFirstRewind = true;

    /**
     * Create an iterator over a directory that also enters its symlinked directories, as PHP's file functions do.
     *
     * A link to a directory the walk is already inside would be entered forever, so it is skipped. Every
     * other linked directory is listed under the link's path, so one linked twice is listed under both.
     */
    public static function followingLinks(string $directory): RecursiveCallbackFilterIterator
    {
        return new RecursiveCallbackFilterIterator(
            new self($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            static function (SplFileInfo $file) use ($directory): bool {
                if (! $file->isLink() || ! $file->isDir()) {
                    return true;
                }

                for ($path = $file->getPath(); realpath($path) !== $file->getRealPath(); $path = dirname($path)) {
                    if (strlen($path) <= strlen($directory)) {
                        return true;
                    }
                }

                return false;
            },
        );
    }

    /**
     * Move to the next entry.
     */
    public function next(): void
    {
        $this->ignoreFirstRewind = false;

        parent::next();
    }

    /**
     * Rewind to the first entry, except on the first call.
     */
    public function rewind(): void
    {
        if ($this->ignoreFirstRewind) {
            $this->ignoreFirstRewind = false;

            return;
        }

        parent::rewind();
    }
}
