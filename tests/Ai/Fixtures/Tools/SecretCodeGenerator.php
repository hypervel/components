<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class SecretCodeGenerator implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Returns the secret authorization code for the current session.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'ZEBRA-4417';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
