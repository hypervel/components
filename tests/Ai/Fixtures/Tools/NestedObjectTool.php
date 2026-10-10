<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class NestedObjectTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'A tool with nested object parameters for testing.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'ok';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'description' => $schema->string()->required(),
                ]))
                ->required(),
        ];
    }
}
