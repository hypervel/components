<?php

declare(strict_types=1);

namespace Hypervel\Ai\Tools\Filesystem;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Swoole\Coroutine\CanceledException;
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
        $from = (string) $request->string('from');
        $to = (string) $request->string('to');

        try {
            $copied = $this->disk()->copy($from, $to);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            return "Unable to copy [{$from}] to [{$to}]: {$throwable->getMessage()}";
        }

        return $copied
            ? "Copied [{$from}] to [{$to}]."
            : "Unable to copy [{$from}] to [{$to}]. The source file may not exist.";
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
