<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class NonStrictTool implements Tool
{
    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'A tool without the Strict attribute.';
    }

    /**
     * Handle the tool invocation.
     */
    public function handle(Request $request): string
    {
        return 'ok';
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
            'limit' => $schema->integer(),
        ];
    }
}
