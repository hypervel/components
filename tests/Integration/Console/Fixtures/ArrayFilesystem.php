<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Fixtures;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;
use SensitiveParameter;

class ArrayFilesystem extends Filesystem
{
    /**
     * The file contents, keyed by path.
     *
     * @var array<string, string>
     */
    public array $files = [];

    /**
     * The file permission modes, keyed by path. Null means the default mode.
     *
     * @var array<string, null|int>
     */
    public array $modes = [];

    /**
     * The paths written through put() or replace(), in order.
     *
     * @var array<int, string>
     */
    public array $writes = [];

    /**
     * The paths read through get(), in order.
     *
     * @var array<int, string>
     */
    public array $reads = [];

    /**
     * Indicates if put() and replace() should report a failed write.
     */
    public bool $failWrites = false;

    /**
     * The paths deleted through delete(), in order.
     *
     * @var array<int, string>
     */
    public array $deletes = [];

    /**
     * Determine if a file or directory exists.
     */
    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    /**
     * Get the contents of a file.
     *
     * @throws FileNotFoundException
     */
    public function get(string $path, bool $lock = false): string
    {
        if (! $this->exists($path)) {
            throw new FileNotFoundException("File does not exist at path {$path}.");
        }

        $this->reads[] = $path;

        return $this->files[$path];
    }

    /**
     * Write the contents of a file.
     *
     * @param resource|string $contents
     */
    public function put(string $path, $contents, bool $lock = false): int|false
    {
        if ($this->failWrites) {
            return false;
        }

        $this->writes[] = $path;
        $this->files[$path] = $contents;

        return strlen($contents);
    }

    /**
     * Write the contents of a file, replacing it atomically if it already exists.
     */
    public function replace(string $path, #[SensitiveParameter] string $content, ?int $mode = null): void
    {
        if ($this->failWrites) {
            throw new RuntimeException("Unable to write the complete replacement contents for [{$path}].");
        }

        $this->writes[] = $path;
        $this->files[$path] = $content;
        $this->modes[$path] = $mode;
    }

    /**
     * Get or set UNIX mode of a file or directory.
     */
    public function chmod(string $path, ?int $mode = null): string|bool
    {
        if (! $this->exists($path)) {
            return false;
        }

        return sprintf('%04o', $this->modes[$path] ?? 0644);
    }

    /**
     * Delete the file at a given path.
     */
    public function delete(array|string $paths): bool
    {
        foreach ((array) $paths as $path) {
            $this->deletes[] = $path;
            unset($this->files[$path]);
        }

        return true;
    }
}
