<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Streaming\Events\Citation as CitationEvent;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\XaiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamingTest extends TestCase
{
    use XaiHelpers;

    /**
     * Configure the xAI provider.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.xai.key', 'test-key');
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
                    ['type' => 'response.output_text.delta', 'delta' => ' world'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertInstanceOf(StreamStart::class, $events[0]);
        $this->assertInstanceOf(TextStart::class, $events[1]);
        $this->assertInstanceOf(TextDelta::class, $events[2]);
        $this->assertSame('Hello', $events[2]->delta);
        $this->assertInstanceOf(TextDelta::class, $events[3]);
        $this->assertSame(' world', $events[3]->delta);
        $this->assertInstanceOf(TextEnd::class, $events[4]);
        $this->assertInstanceOf(StreamEnd::class, $events[5]);
    }

    #[DataProvider('reasoningEventTypes')]
    public function testStreamingEmitsReasoningEvents(string $eventType): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => $eventType, 'delta' => 'Let me think...', 'item_id' => 'rs_1'],
                    ['type' => 'response.output_item.done', 'item' => ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []]],
                    ['type' => 'response.output_text.delta', 'delta' => 'Answer'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Answer']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 3]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $this->assertTrue(collect($events)->contains(fn ($event): bool => $event instanceof ReasoningStart));
        $this->assertTrue(collect($events)->contains(fn ($event): bool => $event instanceof ReasoningEnd));

        $reasoningDelta = collect($events)->first(fn ($event): bool => $event instanceof ReasoningDelta);

        $this->assertSame('Let me think...', $reasoningDelta->delta);
    }

    /**
     * Provide reasoning event types.
     */
    public static function reasoningEventTypes(): array
    {
        return [
            'reasoning summary' => ['response.reasoning_summary_text.delta'],
            'reasoning text' => ['response.reasoning_text.delta'],
        ];
    }

    public function testStreamingEmitsCitationEventsFromTheCompletedResponse(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'Here are sources'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Here are sources', 'annotations' => [
                        ['type' => 'url_citation', 'url' => 'https://example.com/one', 'title' => 'Example One', 'start_index' => 0, 'end_index' => 10],
                        ['type' => 'url_citation', 'url' => 'https://example.com/two', 'title' => 'Example Two', 'start_index' => 11, 'end_index' => 25],
                    ]]]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();
        $textStart = collect($events)->first(fn ($event): bool => $event instanceof TextStart);
        $citations = array_values(array_filter($events, fn ($event): bool => $event instanceof CitationEvent));

        $this->assertCount(2, $citations);
        $this->assertSame($textStart->messageId, $citations[0]->messageId);
        $this->assertSame($textStart->messageId, $citations[1]->messageId);
        $this->assertSame('https://example.com/one', $citations[0]->citation->url);
        $this->assertSame('Example One', $citations[0]->citation->title);
        $this->assertSame(0, $citations[0]->citation->startIndex);
        $this->assertSame('https://example.com/two', $citations[1]->citation->url);
    }

    public function testStreamingStartsANewTextPartAfterEachTextEndInTheSameStep(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'First'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.output_text.delta', 'delta' => 'Second'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'FirstSecond']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $textStarts = array_values(array_filter($events, fn ($e): bool => $e instanceof TextStart));
        $textEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof TextEnd));
        $textDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof TextDelta));

        $this->assertCount(2, $textStarts);
        $this->assertCount(2, $textEnds);
        $this->assertCount(2, $textDeltas);
        $this->assertNotSame($textStarts[0]->messageId, $textStarts[1]->messageId);
        $this->assertSame($textStarts[0]->messageId, $textEnds[0]->messageId);
        $this->assertSame($textStarts[1]->messageId, $textEnds[1]->messageId);
        $this->assertSame($textStarts[0]->messageId, $textDeltas[0]->messageId);
        $this->assertSame($textStarts[1]->messageId, $textDeltas[1]->messageId);
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator']],
                        ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{}'],
                        ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
                        ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        ['type' => 'response.created', 'response' => ['id' => 'resp_456', 'model' => 'grok-4-1-fast-reasoning']],
                        ['type' => 'response.output_text.delta', 'delta' => 'The number is 72019'],
                        ['type' => 'response.output_text.done'],
                        ['type' => 'response.completed', 'response' => ['id' => 'resp_456', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));
        $toolResultEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolResultEvent));

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertNotEmpty($toolResultEvents);
    }

    public function testStreamingToolLoopEmitsASingleStreamEndWithAccumulatedUsage(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                        ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator']],
                        ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_1', 'delta' => '{}'],
                        ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{}'],
                        ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 2], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        ['type' => 'response.created', 'response' => ['id' => 'resp_456', 'model' => 'grok-4-1-fast-reasoning']],
                        ['type' => 'response.output_text.delta', 'delta' => 'The number is 72019'],
                        ['type' => 'response.output_text.done'],
                        ['type' => 'response.completed', 'response' => ['id' => 'resp_456', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 8], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $streamEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd));

        $this->assertCount(1, $streamEnds);
        $this->assertSame(FinishReason::Stop->value, $streamEnds[0]->reason);
        $this->assertSame(30, $streamEnds[0]->usage->inputTokens);
        $this->assertSame(15, $streamEnds[0]->usage->outputTokens);
        $this->assertSame(10, $streamEnds[0]->usage->cacheReadInputTokens);
    }

    public function testStreamingCapturesUsage(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'Hi'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 2], 'output_tokens_details' => ['reasoning_tokens' => 3]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

        $this->assertSame(10, $streamEnd->usage->inputTokens);
        $this->assertSame(8, $streamEnd->usage->outputTokens);
        $this->assertSame(2, $streamEnd->usage->cacheReadInputTokens);
        $this->assertSame(3, $streamEnd->usage->reasoningTokens);
    }

    #[DataProvider('errorEvents')]
    public function testStreamingErrorEventStopsStream(array $event): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    $event,
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('server_error', $error->type);
        $this->assertSame('Internal server error', $error->message);
    }

    /**
     * Provide the supported stream error shapes.
     */
    public static function errorEvents(): array
    {
        return [
            'nested code' => [['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Internal server error']]],
            'nested type' => [['type' => 'error', 'error' => ['type' => 'server_error', 'message' => 'Internal server error']]],
            'flat error' => [['type' => 'error', 'code' => 'server_error', 'message' => 'Internal server error']],
            'failed response' => [['type' => 'response.failed', 'response' => ['status' => 'failed', 'error' => ['code' => 'server_error', 'message' => 'Internal server error']]]],
        ];
    }

    public function testIncompleteResponseRetainsItsTextUsageAndFinishReason(): void
    {
        Http::fake(['*' => Http::response($this->ssePayload([
            ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4']],
            ['type' => 'response.output_text.delta', 'delta' => 'Partial answer'],
            ['type' => 'response.output_text.done'],
            ['type' => 'response.incomplete', 'response' => [
                'id' => 'resp_123', 'model' => 'grok-4', 'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Partial answer']]]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]],
        ]))]);

        $response = (new AssistantAgent)->stream('Hello', provider: 'xai');
        $events = iterator_to_array($response);
        $end = collect($events)->first(fn ($event): bool => $event instanceof StreamEnd);

        $this->assertSame('Partial answer', $response->text);
        $this->assertSame(FinishReason::Length->value, $end->reason);
        $this->assertSame(10, $end->usage->inputTokens);
        $this->assertSame(5, $end->usage->outputTokens);
        $this->assertSame('grok-4', $end->steps[0]->meta->model);
    }

    public function testStreamingRejectsEofWithoutATerminalResponse(): void
    {
        Http::fake(['*' => Http::response($this->ssePayload([
            ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4']],
            ['type' => 'response.output_text.delta', 'delta' => 'Partial answer'],
        ]))]);

        $error = null;
        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('incomplete_stream', $error->type);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsCorrectly(string $status, string $type, FinishReason $expected): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.output_text.delta', 'delta' => 'Hello'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => $status, 'output' => [['type' => $type, 'status' => $status, 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

        $this->assertSame($expected->value, $streamEnd->reason);
    }

    /**
     * Provide response statuses and their finish reasons.
     */
    public static function finishReasons(): array
    {
        return [
            'completed message maps to Stop' => ['completed', 'message', FinishReason::Stop],
            'completed function_call maps to ToolCalls' => ['completed', 'function_call', FinishReason::ToolCalls],
            'incomplete maps to Length' => ['incomplete', 'message', FinishReason::Length],
            'failed maps to Error' => ['failed', 'message', FinishReason::Error],
            'unknown status maps to Unknown' => ['mystery_status', 'message', FinishReason::Unknown],
            'completed unknown type maps to Unknown' => ['completed', 'mystery_output', FinishReason::Unknown],
        ];
    }

    public function testStreamingEmitsProviderToolEventsForCodeInterpreterCodeDeltas(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'response.created', 'response' => ['id' => 'resp_123', 'model' => 'grok-4-1-fast-reasoning']],
                    ['type' => 'response.code_interpreter_call_code.delta', 'item_id' => 'ci_1', 'output_index' => 0, 'delta' => 'print(1)'],
                    ['type' => 'response.code_interpreter_call_code.done', 'item_id' => 'ci_1', 'output_index' => 0, 'code' => 'print(1)'],
                    ['type' => 'response.output_text.delta', 'delta' => '1'],
                    ['type' => 'response.output_text.done'],
                    ['type' => 'response.completed', 'response' => ['id' => 'resp_123', 'status' => 'completed', 'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '1']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]]]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $providerEvents = array_values(array_filter($this->collectStreamEvents(), fn ($e): bool => $e instanceof ProviderToolEvent));

        $this->assertSame(['code_delta', 'code_done'], array_map(fn (ProviderToolEvent $e): string => $e->status, $providerEvents));
        $this->assertSame('code_interpreter_call', $providerEvents[0]->type);
        $this->assertSame('ci_1', $providerEvents[0]->itemId);
    }
}
