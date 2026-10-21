<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Exception;
use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

#[Strict]
class FixedNumberGenerator implements Tool
{
    /**
     * Create a fixed number generator.
     */
    public function __construct(public bool $throwsException = false)
    {
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'This tool can be used to generate cryptographically secure random numbers.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        if ($this->throwsException) {
            throw new Exception('Forced to throw exception.');
        }

        return '72019';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
