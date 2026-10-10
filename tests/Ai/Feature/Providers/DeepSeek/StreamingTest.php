<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

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
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\DeepSeekHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamingTest extends TestCase
{
    use DeepSeekHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.deepseek.key', 'test-key');
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                    $this->chatChunk(['content' => ' world']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
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
        $this->assertInstanceOf(TextEnd::class, $events[count($events) - 2]);
        $this->assertInstanceOf(StreamEnd::class, $events[count($events) - 1]);
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                        $this->chatChunkToolCallDelta(0, '{}'),
                        $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                        '[DONE]',
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                        $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                        '[DONE]',
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame('call_1', $toolCallEvents[0]->toolCall->id);
    }

    public function testStreamingToolLoopEmitsASingleStreamEndWithAccumulatedUsage(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->chatChunkToolCallStart(0, 'call_1', 'FixedNumberGenerator'),
                        $this->chatChunkToolCallDelta(0, '{}'),
                        $this->chatChunkFinish('tool_calls', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                        '[DONE]',
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->chatChunk(['role' => 'assistant', 'content' => 'The number is 72019']),
                        $this->chatChunkFinish('stop', ['prompt_tokens' => 20, 'completion_tokens' => 10]),
                        '[DONE]',
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $streamEnds = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd));

        $this->assertCount(1, $streamEnds);
        $this->assertSame(FinishReason::Stop->value, $streamEnds[0]->reason);
        $this->assertSame(30, $streamEnds[0]->usage->inputTokens);
        $this->assertSame(15, $streamEnds[0]->usage->outputTokens);
    }

    public function testStreamingErrorEventStopsStream(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(
                body: $this->ssePayload([
                    ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit exceeded']],
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
        $this->assertSame('rate_limit_exceeded', $error->type);
        $this->assertSame('Rate limit exceeded', $error->message);
    }

    public function testStreamingRejectsPrematureEof(): void
    {
        Http::fake(['*' => Http::response(
            $this->ssePayload([$this->chatChunk(['content' => 'Partial'])]),
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

    public function testStreamingCapturesUsageFromFinalChunk(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 42, 'completion_tokens' => 10]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame(42, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
    }

    public function testStreamingCapturesCacheHitAndReasoningTokens(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                    $this->chatChunkFinish('stop', [
                        'prompt_tokens' => 100,
                        'completion_tokens' => 50,
                        'prompt_cache_hit_tokens' => 30,
                        'prompt_cache_miss_tokens' => 70,
                        'total_tokens' => 150,
                        'completion_tokens_details' => [
                            'reasoning_tokens' => 12,
                        ],
                    ]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame(100, $streamEnd->usage->inputTokens);
        $this->assertSame(50, $streamEnd->usage->outputTokens);
        $this->assertSame(30, $streamEnd->usage->cacheReadInputTokens);
        $this->assertNull($streamEnd->usage->cacheWriteInputTokens);
        $this->assertSame(12, $streamEnd->usage->reasoningTokens);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsCorrectly(string $apiReason, FinishReason $expected): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'Hello']),
                    $this->chatChunkFinish($apiReason, ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

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
            'content_filter maps to ContentFilter' => ['content_filter', FinishReason::ContentFilter],
            'aborted maps to Error' => ['aborted', FinishReason::Error],
            'insufficient_system_resource maps to Error' => ['insufficient_system_resource', FinishReason::Error],
            'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
        ];
    }
}
