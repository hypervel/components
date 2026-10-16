<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class NullableStructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that knows about periodic table elements and their properties.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()->required(),
            'meltingPoint' => $schema->number()->nullable()->required(),
            'boilingPoint' => $schema->number()->nullable()->required(),
        ];
    }
}
