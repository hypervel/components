<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Swoole\Coroutine\CanceledException;
use Throwable;

#[Strict]
class GetFileMetadata extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Get metadata for a file (size in bytes, last modified time, MIME type, visibility) without reading its contents. Use ReadFile to read the contents.';
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

        return (string) json_encode([
            'path' => $path,
            'size' => $size,
            'last_modified' => rescue(fn (): int => $disk->lastModified($path), report: false),
            // The contract omits adapter metadata methods, which every shipped disk provides.
            // @phpstan-ignore method.notFound
            'mime_type' => rescue(fn (): false|string => $disk->mimeType($path), report: false),
            'visibility' => rescue(fn (): string => $disk->getVisibility($path), report: false),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to inspect, relative to the disk root.')
                ->required(),
        ];
    }
}
