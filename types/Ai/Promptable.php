<?php

declare(strict_types=1);

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;

use function Hypervel\Ai\agent;
use function PHPStan\Testing\assertType;

class PromptableTypeAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are helpful.';
    }
}

class PromptableTypeStructuredAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are helpful.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['score' => $schema->integer()->required()];
    }
}

assertType('Hypervel\Ai\Responses\AgentResponse', (new PromptableTypeAgent)->prompt('Hello'));
assertType('Hypervel\Ai\Responses\StructuredAgentResponse', (new PromptableTypeStructuredAgent)->prompt('Hello'));
assertType('Hypervel\Ai\Responses\StructuredAgentResponse', PromptableTypeStructuredAgent::make()->prompt('Hello'));

assertType('Hypervel\Ai\Responses\AgentResponse', agent('You are helpful.')->prompt('Hello'));
assertType('Hypervel\Ai\Responses\StructuredAgentResponse', agent(schema: fn ($schema) => [])->prompt('Hello'));
