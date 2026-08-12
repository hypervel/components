<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Fixtures;

use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Http\File;
use Hypervel\Http\UploadedFile;

class ArrayFilesystem implements Filesystem
{
    public array $files = [];

    public bool $deleteResult = true;

    public bool|string $putResult = true;

    public function path(string $path): string
    {
        return $path;
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files) || $this->files($path) !== [];
    }

    public function get(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function readStream(string $path): mixed
    {
        return null;
    }

    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        return null;
    }

    public function put(string $path, mixed $contents, mixed $options = []): bool|string
    {
        if ($this->putResult === false) {
            return false;
        }

        $this->files[$path] = $contents;

        return $this->putResult;
    }

    public function putFile(
        string|File|UploadedFile $path,
        array|string|File|UploadedFile|null $file = null,
        mixed $options = [],
    ): false|string {
        return false;
    }

    public function putFileAs(
        string|File|UploadedFile $path,
        array|string|File|UploadedFile|null $file,
        array|string|null $name = null,
        mixed $options = [],
    ): false|string {
        return false;
    }

    public function writeStream(string $path, mixed $resource, array $options = []): bool
    {
        return false;
    }

    public function getVisibility(string $path): string
    {
        return Filesystem::VISIBILITY_PRIVATE;
    }

    public function setVisibility(string $path, string $visibility): bool
    {
        return true;
    }

    public function prepend(string $path, string $data, string $separator = PHP_EOL): bool
    {
        return false;
    }

    public function append(string $path, string $data, string $separator = PHP_EOL): bool
    {
        return false;
    }

    public function delete(array|string $paths): bool
    {
        if (! $this->deleteResult) {
            return false;
        }

        foreach ((array) $paths as $path) {
            unset($this->files[$path]);
        }

        return true;
    }

    public function copy(string $from, string $to): bool
    {
        return false;
    }

    public function move(string $from, string $to): bool
    {
        return false;
    }

    public function size(string $path): int
    {
        return strlen($this->files[$path] ?? '');
    }

    public function lastModified(string $path): int
    {
        return 0;
    }

    public function files(?string $directory = null, bool $recursive = false): array
    {
        $directory = trim((string) $directory, '/');

        return array_values(array_filter(array_keys($this->files), function (string $path) use ($directory): bool {
            return $directory === '' || str_starts_with($path, $directory . '/');
        }));
    }

    public function allFiles(?string $directory = null): array
    {
        return $this->files($directory, true);
    }

    public function directories(?string $directory = null, bool $recursive = false): array
    {
        return [];
    }

    public function allDirectories(?string $directory = null): array
    {
        return [];
    }

    public function makeDirectory(string $path): bool
    {
        return true;
    }

    public function deleteDirectory(string $directory): bool
    {
        foreach ($this->allFiles($directory) as $path) {
            unset($this->files[$path]);
        }

        return true;
    }
}
