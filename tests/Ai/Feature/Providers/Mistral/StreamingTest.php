<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Streaming\Events\ToolResult as ToolResultEvent;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\MistralHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamingTest extends TestCase
{
    use MistralHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.mistral.key', 'test-key');
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
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

    public function testStreamingFlattensBlockContentDeltas(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello']]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['content' => [['type' => 'reference', 'reference_ids' => ['search_documents']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
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
        $this->assertInstanceOf(TextEnd::class, $events[3]);
        $this->assertInstanceOf(StreamEnd::class, $events[4]);
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                        ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                        ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                        ['id' => 'chatcmpl-456', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolCallEvent));
        $toolResultEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolResultEvent));
        $streamEndEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd));

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertNotEmpty($toolResultEvents);
        $this->assertCount(1, $streamEndEvents);
        $this->assertSame(FinishReason::Stop->value, $streamEndEvents[0]->reason);
        $this->assertSame(30, $streamEndEvents[0]->usage->inputTokens);
        $this->assertSame(15, $streamEndEvents[0]->usage->outputTokens);
    }

    public function testStreamingCapturesUsage(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd))[0];

        $this->assertSame(10, $streamEnd->usage->inputTokens);
        $this->assertSame(5, $streamEnd->usage->outputTokens);
    }

    public function testStreamingErrorEventStopsStream(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['error' => ['code' => 'server_error', 'message' => 'Internal server error']],
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

    public function testStreamingRejectsPrematureEof(): void
    {
        Http::fake(['*' => Http::response(
            'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['content' => 'Partial'], 'finish_reason' => null]]]) . "\n\n",
            headers: ['Content-Type' => 'text/event-stream'],
        )]);

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
    public function testStreamingFinishReasonMapsCorrectly(string $apiReason, FinishReason $expected): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ssePayload([
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-123', 'object' => 'chat.completion.chunk', 'model' => 'mistral-medium-latest', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $apiReason]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd))[0];

        $this->assertSame($expected->value, $streamEnd->reason);
    }

    /**
     * Provide provider finish reasons and their normalized values.
     */
    public static function finishReasons(): array
    {
        return [
            'stop maps to Stop' => ['stop', FinishReason::Stop],
            'tool_calls maps to ToolCalls' => ['tool_calls', FinishReason::ToolCalls],
            'length maps to Length' => ['length', FinishReason::Length],
            'model_length maps to Length' => ['model_length', FinishReason::Length],
            'content_filter maps to ContentFilter' => ['content_filter', FinishReason::ContentFilter],
            'error maps to Error' => ['error', FinishReason::Error],
            'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
        ];
    }
}
