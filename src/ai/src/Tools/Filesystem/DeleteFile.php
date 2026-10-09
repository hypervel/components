<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

#[Strict]
class DeleteFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Permanently delete a file from the filesystem disk. This cannot be undone.';
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

        if (! $disk->delete($path)) {
            return "Unable to delete [{$path}].";
        }

        return "Deleted [{$path}].";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to delete, relative to the disk root.')
                ->required(),
        ];
    }
}
