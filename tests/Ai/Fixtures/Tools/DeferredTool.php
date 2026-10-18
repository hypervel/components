<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class DeferredTool implements Tool
{
    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'A deferred tool whose definition is loaded on demand.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'done';
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
