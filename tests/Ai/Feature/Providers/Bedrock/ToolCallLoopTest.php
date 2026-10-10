<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Bedrock;

use Hypervel\Ai\Exceptions\NoSuchToolException;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Responses\StructuredTextResponse;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;

function bedrockToolCallResponse(string $toolUseId): array
{
    return [
        'output' => ['message' => ['content' => [
            ['toolUse' => ['toolUseId' => $toolUseId, 'name' => 'FixedNumberGenerator', 'input' => []]],
        ]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 3],
        'stopReason' => 'tool_use',
    ];
}

function bedrockTextResponse(string $text): array
{
    return [
        'output' => ['message' => ['content' => [['text' => $text]]]],
        'usage' => ['inputTokens' => 7, 'outputTokens' => 5],
        'stopReason' => 'end_turn',
    ];
}

class ToolCallLoopTest extends TestCase
{
    use BedrockHelpers;

    public function testMultiStepToolLoopReturnsAccumulatedResponseShape(): void
    {
        $client = $this->fakeBedrockConverseSequence([
            bedrockToolCallResponse('t1'),
            bedrockToolCallResponse('t2'),
            bedrockTextResponse('Done'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            messages: [new UserMessage('Generate numbers')],
            tools: [new FixedNumberGenerator],
            options: new TextGenerationOptions(maxSteps: 5),
        );

        $this->assertSame('Done', (string) $response);
        $this->assertCount(5, $response->messages);
        $this->assertCount(3, $response->steps);
        $this->assertCount(2, $response->toolCalls);
        $this->assertCount(2, $response->toolResults);
        $this->assertSame(21, $response->usage->inputTokens);
        $this->assertSame(11, $response->usage->outputTokens);
    }

    public function testMaxStepsLimitsToolCallDepth(): void
    {
        $client = $this->fakeBedrockConverseSequence([
            bedrockToolCallResponse('t1'),
            bedrockToolCallResponse('t2'),
            bedrockToolCallResponse('t3'),
            bedrockTextResponse('Done'),
        ]);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            messages: [new UserMessage('Generate numbers')],
            tools: [new FixedNumberGenerator],
            options: new TextGenerationOptions(maxSteps: 2),
        );

        $this->assertCount(2, $response->steps);
    }

    public function testUnknownToolCallThrowsNoSuchToolException(): void
    {
        $client = $this->fakeBedrockConverse([
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 't1', 'name' => 'NonExistentTool', 'input' => []]],
            ]]],
            'usage' => ['inputTokens' => 7, 'outputTokens' => 3],
            'stopReason' => 'tool_use',
        ]);

        $gateway = $this->gatewayWithClient($client);

        $this->expectException(NoSuchToolException::class);
        (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            tools: [new FixedNumberGenerator],
        );
    }

    public function testStructuredOutputIsParsedFromSyntheticToolCall(): void
    {
        $requests = [];

        $client = $this->fakeBedrockConverseSequence([[
            'output' => ['message' => ['content' => [
                ['toolUse' => ['toolUseId' => 's1', 'name' => 'structured_output', 'input' => ['symbol' => 'Fe']]],
            ]]],
            'usage' => ['inputTokens' => 8, 'outputTokens' => 4],
            'stopReason' => 'tool_use',
        ]], $requests);

        $gateway = $this->gatewayWithClient($client);

        $response = (new TextGenerationLoop($gateway))->generate(
            $this->bedrockProvider(),
            'anthropic.claude-opus-4-7-v1:0',
            null,
            schema: ['symbol' => (new JsonSchemaTypeFactory)->string()],
        );

        $this->assertInstanceOf(StructuredTextResponse::class, $response);
        $this->assertSame('Fe', $response->structured['symbol']);
        $this->assertCount(1, $response->steps);
        $this->assertSame(8, $response->usage->inputTokens);
        $this->assertSame(4, $response->usage->outputTokens);
        $this->assertSame(['tool' => ['name' => 'structured_output']], $requests[0]['toolConfig']['toolChoice']);
        $this->assertArrayNotHasKey('system', $requests[0]);
    }

    public function testStructuredOutputIsParsedFromJsonAnswerOfModelsRejectingForcedToolChoice(): void
    {
        $requests = [];

        $client = $this->fakeBedrockConverseSequence([
            bedrockTextResponse('{"symbol": "Fe"}'),
        ], $requests);

        $response = (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'us.anthropic.claude-sonnet-5-5',
            'You are a helpful assistant.',
            schema: ['symbol' => (new JsonSchemaTypeFactory)->string()],
        );

        $this->assertInstanceOf(StructuredTextResponse::class, $response);
        $this->assertSame(['symbol' => 'Fe'], $response->structured);
        $this->assertArrayNotHasKey('toolConfig', $requests[0]);
        $this->assertCount(2, $requests[0]['system']);
        $this->assertSame(['text' => 'You are a helpful assistant.'], $requests[0]['system'][0]);
        $this->assertStringContainsString('"symbol"', $requests[0]['system'][1]['text']);
    }

    public function testModelsRejectingForcedToolChoiceKeepRealToolsOnAutoSelectionBeforeJsonAnswer(): void
    {
        $requests = [];

        $client = $this->fakeBedrockConverseSequence([
            bedrockToolCallResponse('t1'),
            bedrockTextResponse('{"number": 42}'),
        ], $requests);

        $response = (new TextGenerationLoop($this->gatewayWithClient($client)))->generate(
            $this->bedrockProvider(),
            'us.anthropic.claude-opus-5-5',
            null,
            messages: [new UserMessage('Generate a number')],
            tools: [new FixedNumberGenerator],
            schema: ['number' => (new JsonSchemaTypeFactory)->integer()],
            options: new TextGenerationOptions(maxSteps: 2),
        );

        $this->assertSame(['number' => 42], $response->structured);
        $this->assertCount(1, $response->toolResults);
        $this->assertCount(2, $requests);

        foreach ($requests as $request) {
            $this->assertArrayNotHasKey('toolChoice', $request['toolConfig']);
            $this->assertSame(['FixedNumberGenerator'], array_column(array_column($request['toolConfig']['tools'], 'toolSpec'), 'name'));
        }
    }

    public function testStreamingToolLoopEmitsSingleStreamEndWithAccumulatedUsage(): void
    {
        $client = $this->fakeBedrockStreamSequence([
            [
                $this->contentBlockStart(0, ['toolUse' => ['toolUseId' => 't1', 'name' => 'FixedNumberGenerator']]),
                $this->contentBlockDelta(0, ['toolUse' => ['input' => '{}']]),
                $this->contentBlockStop(0),
                $this->messageStop('tool_use'),
                ['metadata' => ['usage' => ['inputTokens' => 5, 'outputTokens' => 2]]],
            ],
            [
                $this->contentBlockStart(0),
                $this->contentBlockDelta(0, ['text' => 'Done']),
                $this->contentBlockStop(0),
                $this->messageStop('end_turn'),
                ['metadata' => ['usage' => ['inputTokens' => 5, 'outputTokens' => 2]]],
            ],
        ]);

        $gateway = $this->gatewayWithClient($client);

        $events = iterator_to_array(
            (new TextGenerationLoop($gateway))->stream(
                'inv-1',
                $this->bedrockProvider(),
                'anthropic.claude-opus-4-7-v1:0',
                null,
                tools: [new FixedNumberGenerator],
            ),
            preserve_keys: false,
        );

        $streamEnds = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd));
        $toolResults = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolResultEvent));

        $this->assertCount(1, $streamEnds);
        $this->assertSame('stop', $streamEnds[0]->reason);
        $this->assertSame(10, $streamEnds[0]->usage->inputTokens);
        $this->assertSame(4, $streamEnds[0]->usage->outputTokens);
        $this->assertCount(1, $toolResults);
        $this->assertInstanceOf(StreamEnd::class, $events[count($events) - 1]);
    }
}
