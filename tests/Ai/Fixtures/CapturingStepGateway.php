<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures;

use Generator;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;

class CapturingStepGateway implements StepTextGateway
{
    /** @var array<int, array{model: string, instructions: ?string, tools: array, schema: ?array, context: StepContext}> */
    public array $calls = [];

    /**
     * Generate one step and record its resolved inputs.
     */
    public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
    {
        $this->calls[] = ['model' => $model, 'instructions' => $instructions, 'tools' => $tools, 'schema' => $schema, 'context' => $stepContext];

        return count($this->calls) === 1
            ? new StepResponse('', [new ToolCall('call_1', 'FixedNumberGenerator', [])], FinishReason::ToolCalls, new TextUsage(10, 5), new Meta, continuationToken: 'resp_1')
            : new StepResponse('Done.', [], FinishReason::Stop, new TextUsage, new Meta);
    }

    /**
     * Return an empty stream.
     */
    public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
    {
        yield from [];
    }
}
