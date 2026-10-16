<?php

declare(strict_types=1);

namespace Workbench\App\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class CurrentLocation implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return "Get the city the user is currently in. Call this before any tool that needs the user's location.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'Pune, India';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
