<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Swoole\Coroutine\CanceledException;
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
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return "File [{$path}] does not exist.";
        }

        if ($size > static::MAX_BYTES) {
            return "File [{$path}] is too large to read inline. Use GetFileMetadata or GetFileUrl instead.";
        }

        $contents = $disk->get($path);

        if ($contents === null || ! mb_check_encoding($contents, 'UTF-8')) {
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
