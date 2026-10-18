<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Swoole\Coroutine\CanceledException;
use Throwable;

#[Strict]
class GetFileUrl extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Generate a URL for accessing a file on the filesystem disk. Disks that do not support URLs return an explanatory message instead of a URL.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $disk = $this->disk();

        $path = (string) $request->string('path');

        if (! $this->fileExists($disk, $path)) {
            return "File [{$path}] does not exist.";
        }

        $minutes = $request->integer('expires_in_minutes');

        try {
            // URL methods are disk capabilities outside the base filesystem contract.
            return $minutes > 0
                // @phpstan-ignore method.notFound
                ? $disk->temporaryUrl($path, now()->addMinutes($minutes))
                // @phpstan-ignore method.notFound
                : $disk->url($path);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            return "Unable to generate a URL for [{$path}]: {$throwable->getMessage()}";
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path, relative to the disk root.')
                ->required(),
            'expires_in_minutes' => $schema->integer()
                ->description("Number of minutes a temporary signed URL stays valid, or null for the disk's standard (non-expiring) URL.")
                ->nullable()
                ->required(),
        ];
    }
}
