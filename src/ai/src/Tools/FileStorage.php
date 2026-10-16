<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Filesystem\CopyFile;
use Hypervel\Ai\Tools\Filesystem\DeleteFile;
use Hypervel\Ai\Tools\Filesystem\FileExists;
use Hypervel\Ai\Tools\Filesystem\GetFileMetadata;
use Hypervel\Ai\Tools\Filesystem\GetFileUrl;
use Hypervel\Ai\Tools\Filesystem\ListFiles;
use Hypervel\Ai\Tools\Filesystem\MoveFile;
use Hypervel\Ai\Tools\Filesystem\ReadFile;
use Hypervel\Ai\Tools\Filesystem\WriteFile;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Support\Collection;

class FileStorage
{
    /**
     * Create all of the file storage tools for the given disk.
     *
     * @return Collection<int, Tool>
     */
    public static function all(string|Filesystem|null $disk = null): Collection
    {
        return static::readOnly($disk)->merge([
            new WriteFile($disk),
            new DeleteFile($disk),
            new CopyFile($disk),
            new MoveFile($disk),
        ]);
    }

    /**
     * Create the read-only file storage tools for the given disk.
     *
     * @return Collection<int, Tool>
     */
    public static function readOnly(string|Filesystem|null $disk = null): Collection
    {
        return new Collection([
            new ListFiles($disk),
            new ReadFile($disk),
            new FileExists($disk),
            new GetFileMetadata($disk),
            new GetFileUrl($disk),
        ]);
    }
}
