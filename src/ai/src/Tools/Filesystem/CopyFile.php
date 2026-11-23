<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Coroutine\Coroutine;
use Throwable;

#[Strict]
class CopyFile extends FilesystemTool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Copy a file to a new path on the filesystem disk. The source file is left unchanged.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        $disk = $this->disk();

        $from = (string) $request->string('from');
        $to = (string) $request->string('to');

        try {
            if (! $this->fileExists($disk, $from)) {
                return "File [{$from}] does not exist.";
            }

            $copied = $disk->copy($from, $to);
        } catch (Throwable $throwable) {
            if (Coroutine::causedByCancellation($throwable)) {
                throw $throwable;
            }

            // Unlike upstream, the failure is reported, since the tool's result keeps it from the exception handler.
            report($throwable);

            // Unlike upstream, the exception message is only included in debug mode, since it can carry server paths...
            return config()->boolean('app.debug')
                ? "Unable to copy [{$from}] to [{$to}]: {$throwable->getMessage()}"
                : "Unable to copy [{$from}] to [{$to}].";
        }

        return $copied
            ? "Copied [{$from}] to [{$to}]."
            : "Unable to copy [{$from}] to [{$to}].";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->description('The source file path, relative to the disk root.')
                ->required(),
            'to' => $schema->string()
                ->description('The destination file path, relative to the disk root.')
                ->required(),
        ];
    }
}
