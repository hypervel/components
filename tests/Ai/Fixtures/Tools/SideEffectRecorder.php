<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class SideEffectRecorder implements Tool
{
    public static int $invocations = 0;

    /**
     * Get the tool's description.
     */
    public function description(): string
    {
        return 'Records an irreversible side effect.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        ++static::$invocations;

        return 'recorded';
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
