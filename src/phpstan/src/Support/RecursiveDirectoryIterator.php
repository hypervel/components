<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use RecursiveDirectoryIterator as SplRecursiveDirectoryIterator;

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
