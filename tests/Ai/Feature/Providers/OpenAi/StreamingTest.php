<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

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
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\OpenAiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamingTest extends TestCase
{
    use OpenAiHelpers;

    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Hello'),
                    $this->outputTextDelta(' world'),
                    $this->outputTextDone('Hello world'),
                    $this->responseCompleted(10, 5),
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
        $this->assertInstanceOf(StreamEnd::class, $events[count($events) - 1]);
    }

    public function testStreamingEmitsCitationEventsForWebSearchUrlCitations(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Here are sources'),
                    ['type' => 'response.output_text.annotation.added', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'annotation_index' => 0, 'annotation' => ['type' => 'url_citation', 'url' => 'https://example.com/one', 'title' => 'Example One', 'start_index' => 0, 'end_index' => 10]],
                    ['type' => 'response.output_text.annotation.added', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'annotation_index' => 1, 'annotation' => ['type' => 'url_citation', 'url' => 'https://example.com/two', 'title' => 'Example Two', 'start_index' => 11, 'end_index' => 25]],
                    $this->outputTextDone('Here are sources'),
                    $this->responseCompleted(10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $citations = array_values(array_filter($this->collectStreamEvents(), fn (object $event): bool => $event instanceof CitationEvent));

        $this->assertCount(2, $citations);
        $this->assertSame('https://example.com/one', $citations[0]->citation->url);
        $this->assertSame('Example One', $citations[0]->citation->title);
        $this->assertSame(0, $citations[0]->citation->startIndex);
        $this->assertSame(10, $citations[0]->citation->endIndex);
        $this->assertSame('https://example.com/two', $citations[1]->citation->url);
    }

    public function testStreamingStartsANewTextPartAfterEachTextEndInTheSameStep(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('First'),
                    $this->outputTextDone('First'),
                    $this->outputTextDelta('Second'),
                    $this->outputTextDone('Second'),
                    $this->responseCompleted(10, 5, output: [[
                        'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                        'content' => [
                            ['type' => 'output_text', 'text' => 'First'],
                            ['type' => 'output_text', 'text' => 'Second'],
                        ],
                    ]]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $events = $this->collectStreamEvents();
        $textStarts = array_values(array_filter($events, fn (object $event): bool => $event instanceof TextStart));
        $textEnds = array_values(array_filter($events, fn (object $event): bool => $event instanceof TextEnd));
        $textDeltas = array_values(array_filter($events, fn (object $event): bool => $event instanceof TextDelta));

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
            'api.openai.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->responseCreated(),
                        $this->outputItemAdded('fc_1', 'call_1', 'FixedNumberGenerator'),
                        $this->functionCallArgumentsDelta('fc_1', '{}'),
                        $this->functionCallArgumentsDone('fc_1', '{}'),
                        $this->responseCompleted(10, 5, output: [[
                            'type' => 'function_call', 'status' => 'completed', 'id' => 'fc_1',
                            'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}',
                        ]]),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        ['type' => 'response.created', 'response' => ['id' => 'resp_2', 'model' => 'gpt-5.4', 'status' => 'in_progress', 'output' => []]],
                        $this->outputTextDelta('The number is 72019'),
                        $this->outputTextDone('The number is 72019'),
                        $this->responseCompleted(20, 10),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);
        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);
        $toolCallEvents = array_values(array_filter($events, fn (object $event): bool => $event instanceof ToolCallEvent));
        $streamEnd = array_values(array_filter($events, fn (object $event): bool => $event instanceof StreamEnd))[0];

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame('call_1', $toolCallEvents[0]->toolCall->resultId);
        $this->assertSame(FinishReason::Stop->value, $streamEnd->reason);
        $this->assertSame(30, $streamEnd->usage->inputTokens);
        $this->assertSame(15, $streamEnd->usage->outputTokens);
    }

    #[DataProvider('reasoningEventTypes')]
    public function testStreamingHandlesReasoningEvents(string $eventType): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    ['type' => $eventType, 'delta' => 'Let me think...', 'item_id' => 'rs_1'],
                    ['type' => 'response.output_item.done', 'item' => [
                        'type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Let me think...']],
                    ]],
                    $this->outputTextDelta('Answer'),
                    $this->outputTextDone('Answer'),
                    $this->responseCompleted(10, 15),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $events = $this->collectStreamEvents();
        $types = array_map(fn (object $event): string => $event::class, $events);

        $this->assertContains(ReasoningStart::class, $types);
        $this->assertContains(ReasoningDelta::class, $types);
        $this->assertContains(ReasoningEnd::class, $types);
        $reasoningDelta = array_values(array_filter($events, fn (object $event): bool => $event instanceof ReasoningDelta))[0];
        $this->assertSame('Let me think...', $reasoningDelta->delta);
    }

    /**
     * Supply reasoning delta event types.
     */
    public static function reasoningEventTypes(): array
    {
        return [
            'reasoning summary' => ['response.reasoning_summary_text.delta'],
            'reasoning text' => ['response.reasoning_text.delta'],
        ];
    }

    #[DataProvider('errorEvents')]
    public function testStreamingErrorEventStopsStream(array $event, string $expectedType): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([$event]),
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
        $this->assertSame($expectedType, $error->type);
        $this->assertSame('Server overloaded', $error->message);
    }

    /**
     * Supply observed nested errors and the documented flat shape.
     */
    public static function errorEvents(): array
    {
        return [
            'nested code' => [['type' => 'error', 'error' => ['code' => 'server_error', 'message' => 'Server overloaded']], 'server_error'],
            'nested type' => [['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'code' => null, 'message' => 'Server overloaded']], 'invalid_request_error'],
            'flat' => [['type' => 'error', 'code' => 'server_error', 'message' => 'Server overloaded'], 'server_error'],
        ];
    }

    public function testFailedResponseStopsTheStreamWithItsProviderError(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Partial'),
                    ['type' => 'response.failed', 'response' => [
                        'id' => 'resp_1', 'status' => 'failed',
                        'error' => ['code' => 'server_error', 'message' => 'Generation failed'],
                    ]],
                ]),
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
        $this->assertSame('Generation failed', $error->message);
    }

    public function testStreamEndingBeforeTheCompletionEventThrows(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(
            body: $this->ssePayload([
                $this->responseCreated(),
                $this->outputTextDelta('Partial answer'),
            ]),
            headers: ['Content-Type' => 'text/event-stream'],
        )]);

        $this->expectException(StreamErrorException::class);
        $this->expectExceptionMessage('The provider stream ended before the response was complete.');

        $this->collectStreamEvents();
    }

    #[DataProvider('incompleteReasons')]
    public function testIncompleteResponseRetainsItsTextUsageAndFinishReason(string $reason, FinishReason $expected): void
    {
        $terminal = $this->responseCompleted(42, 10);
        $terminal['type'] = 'response.incomplete';
        $terminal['response']['status'] = 'incomplete';
        $terminal['response']['incomplete_details'] = ['reason' => $reason];
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Partial answer'),
                    $this->outputTextDone('Partial answer'),
                    $terminal,
                ]),
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $events = $this->collectStreamEvents();
        $streamEnd = array_values(array_filter($events, fn (object $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame($expected->value, $streamEnd->reason);
        $this->assertSame('Partial answer', $streamEnd->steps[0]->text);
        $this->assertSame(42, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
    }

    /**
     * Supply incomplete response reasons.
     */
    public static function incompleteReasons(): array
    {
        return [
            'token limit' => ['max_output_tokens', FinishReason::Length],
            'content filter' => ['content_filter', FinishReason::ContentFilter],
        ];
    }

    public function testStreamingCapturesUsageFromResponseCompleted(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Hello'),
                    $this->outputTextDone('Hello'),
                    $this->responseCompleted(42, 10, cachedTokens: 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $events = $this->collectStreamEvents();
        $streamEnd = array_values(array_filter($events, fn (object $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame(42, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
        $this->assertSame(5, $streamEnd->usage->cacheReadInputTokens);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsCorrectly(string $status, string $type, FinishReason $expected): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Hello'),
                    $this->outputTextDone('Hello'),
                    $this->responseCompleted(10, 5, output: [[
                        'type' => $type, 'status' => $status, 'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => '']],
                    ]]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $events = $this->collectStreamEvents();
        $streamEnd = array_values(array_filter($events, fn (object $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame($expected->value, $streamEnd->reason);
    }

    /**
     * Supply the upstream item-status finish reason cases.
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

    public function testStreamingCapturesCacheWriteTokensFromResponseCompleted(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    $this->outputTextDelta('Hello'),
                    $this->outputTextDone('Hello'),
                    $this->responseCompleted(8817, 120, cachedTokens: 0, cacheWriteTokens: 8814),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $events = $this->collectStreamEvents();
        $streamEnd = array_values(array_filter($events, fn (object $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame(8814, $streamEnd->usage->cacheWriteInputTokens);
        $this->assertSame(0, $streamEnd->usage->cacheReadInputTokens);
        $this->assertSame(8817, $streamEnd->usage->inputTokens);
        $this->assertSame(120, $streamEnd->usage->outputTokens);
    }

    public function testStreamingEmitsProviderToolEventsForCodeInterpreterCodeDeltas(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->responseCreated(),
                    ['type' => 'response.code_interpreter_call.in_progress', 'item_id' => 'ci_1', 'output_index' => 0],
                    ['type' => 'response.code_interpreter_call_code.delta', 'item_id' => 'ci_1', 'output_index' => 0, 'delta' => 'print(1)'],
                    ['type' => 'response.code_interpreter_call_code.done', 'item_id' => 'ci_1', 'output_index' => 0, 'code' => 'print(1)'],
                    ['type' => 'response.code_interpreter_call.completed', 'item_id' => 'ci_1', 'output_index' => 0],
                    $this->outputTextDelta('1'),
                    $this->outputTextDone('1'),
                    $this->responseCompleted(10, 5),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $providerEvents = array_values(array_filter($this->collectStreamEvents(), fn (object $event): bool => $event instanceof ProviderToolEvent));

        $this->assertSame(['in_progress', 'code_delta', 'code_done', 'completed'], array_map(fn (ProviderToolEvent $event): string => $event->status, $providerEvents));
        $this->assertSame('code_interpreter_call', $providerEvents[1]->type);
        $this->assertSame('ci_1', $providerEvents[1]->itemId);
        $this->assertSame('print(1)', $providerEvents[1]->data['delta']);
    }
}
