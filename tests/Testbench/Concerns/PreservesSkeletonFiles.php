<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

trait PreservesSkeletonFiles
{
    /**
     * Files preserved from the shared runtime skeleton.
     *
     * @var array<string, null|string>
     */
    private array $preservedFiles = [];

    /**
     * Preserve files that may already exist in the shared runtime skeleton.
     *
     * @param array<int, string> $paths
     */
    private function preserveFiles(array $paths): void
    {
        foreach ($paths as $path) {
            $this->preservedFiles[$path] = $this->filesystem->isFile($path)
                ? $this->filesystem->get($path)
                : null;
        }
    }

    /**
     * Restore files that existed before the command test.
     */
    private function restorePreservedFiles(): void
    {
        foreach ($this->preservedFiles as $path => $contents) {
            if ($contents === null) {
                $this->deletePath($path);

                continue;
            }

            $this->filesystem->ensureDirectoryExists(dirname($path));
            $this->filesystem->put($path, $contents);
        }

        $this->preservedFiles = [];
    }

    /**
     * Delete a file, directory, or symlink if it exists.
     */
    private function deletePath(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if ($this->filesystem->isDirectory($path)) {
            $this->filesystem->deleteDirectory($path);

            return;
        }

        if ($this->filesystem->exists($path)) {
            $this->filesystem->delete($path);
        }
    }
}
