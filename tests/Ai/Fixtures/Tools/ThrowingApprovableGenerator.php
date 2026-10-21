<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Exception;
use Hypervel\Ai\Concerns\InteractsWithApprovals;
use Hypervel\Ai\Contracts\Approvable;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class ThrowingApprovableGenerator implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'Generates a number, but requires human approval first and always fails.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        throw new Exception('Forced to throw exception.');
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
