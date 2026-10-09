<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

#[Strict]
class WriteFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Write UTF-8 text contents to a file on the filesystem disk, creating it (and any parent directories) or overwriting it if it already exists.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $path = (string) $request->string('path');

        $contents = (string) $request->string('contents');

        if (! $this->disk()->put($path, $contents)) {
            return "Unable to write [{$path}].";
        }

        return 'Wrote ' . strlen($contents) . " bytes to [{$path}].";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The file path to write to, relative to the disk root.')
                ->required(),
            'contents' => $schema->string()
                ->description('The UTF-8 text contents to write to the file.')
                ->required(),
        ];
    }
}
