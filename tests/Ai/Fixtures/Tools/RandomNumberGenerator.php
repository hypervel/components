<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Exception;
use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

#[Strict]
class RandomNumberGenerator implements Tool
{
    /**
     * Create a new tool instance.
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

        return (string) random_int($request['min'], $request['max']);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'min' => $schema->integer()->description('The minimum value of the random number')->default(1)->required(),
            'max' => $schema->integer()->description('The maximum value of the random number')->default(100)->required(),
        ];
    }
}
