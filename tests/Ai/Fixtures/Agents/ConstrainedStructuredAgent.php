<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class ConstrainedStructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->required()->min(1)->max(10),
            'summary' => $schema->string()->required()->min(1)->max(280),
            'tags' => $schema->array()->required()->items($schema->string()->max(20))->min(1)->max(5),
        ];
    }
}
