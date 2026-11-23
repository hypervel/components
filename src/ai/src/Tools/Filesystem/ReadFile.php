<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Coroutine\Coroutine;
use Throwable;

#[Strict]
class ReadFile extends FilesystemTool
{
    /**
     * The maximum number of bytes that may be read inline.
     */
    protected const int MAX_BYTES = 256 * 1024;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Read and return the UTF-8 text contents of a file on the filesystem disk. Files larger than 256 KB or that are not valid UTF-8 text (such as images or other binary files) are rejected; use GetFileUrl to access those instead.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $disk = $this->disk();

        $path = (string) $request->string('path');

        try {
            $size = $disk->size($path);
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

            // Unlike upstream, a failed lookup of an existing file is reported instead of described as missing.
            // Missing files and other failures throw the same exception, so only a failure checks existence...
            if (! $this->fileExists($disk, $path)) {
                return "File [{$path}] does not exist.";
            }

            report($exception);

            return "Unable to read [{$path}].";
        }

        if ($size > static::MAX_BYTES) {
            return "File [{$path}] is too large to read inline. Use GetFileMetadata or GetFileUrl instead.";
        }

        $contents = $disk->get($path);

        // Disks that do not throw return null when the read fails.
        if ($contents === null) {
            return "Unable to read [{$path}].";
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return "File [{$path}] appears to be binary and cannot be read as text. Use GetFileUrl to access it.";
        }

        return $contents;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to read, relative to the disk root.')
                ->required(),
        ];
    }
}
