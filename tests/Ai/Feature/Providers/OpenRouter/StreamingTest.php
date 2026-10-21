<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Streaming\Events\Citation as CitationEvent;
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
use Hypervel\Tests\Ai\Fixtures\OpenRouterHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;

class StreamingTest extends TestCase
{
    use OpenRouterHelpers;

    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]],
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $types = array_map(fn (StreamEvent $e): string => $e::class, $events);

        $this->assertContains(StreamStart::class, $types);
        $this->assertContains(TextStart::class, $types);
        $this->assertContains(TextDelta::class, $types);
        $this->assertContains(TextEnd::class, $types);
        $this->assertContains(StreamEnd::class, $types);

        $textDeltas = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof TextDelta));
        $this->assertSame('Hello', $textDeltas[0]->delta);
        $this->assertSame(' world', $textDeltas[1]->delta);
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response($this->ssePayload([
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 10]],
                ])),
                Http::response($this->ssePayload([
                    ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5]],
                ])),
            ]),
        ]);

        $events = [];
        foreach (agent(tools: [new FixedNumberGenerator])->stream('Give me a number', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolCallEvent));
        $toolResultEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolResultEvent));
        $streamEndEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd));

        $this->assertCount(1, $toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertCount(1, $toolResultEvents);
        $this->assertCount(1, $streamEndEvents);
        $this->assertInstanceOf(StreamEnd::class, $events[count($events) - 1]);
        $this->assertSame(FinishReason::Stop->value, $streamEndEvents[0]->reason);
    }

    public function testStreamingToolLoopEmitsASingleStreamEndWithAccumulatedUsage(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response($this->ssePayload([
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_123', 'type' => 'function', 'function' => ['name' => 'FixedNumberGenerator', 'arguments' => '']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
                ])),
                Http::response($this->ssePayload([
                    ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'The number is 72019'], 'finish_reason' => null]]],
                    ['id' => 'chatcmpl-2', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10]],
                ])),
            ]),
        ]);

        $events = [];

        foreach (agent(tools: [new FixedNumberGenerator])->stream('Give me a number', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $streamEndEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd));

        $this->assertCount(1, $streamEndEvents);
        $this->assertSame(FinishReason::Stop->value, $streamEndEvents[0]->reason);
        $this->assertSame(30, $streamEndEvents[0]->usage->inputTokens);
        $this->assertSame(15, $streamEndEvents[0]->usage->outputTokens);
    }

    #[DataProvider('errorCodes')]
    public function testStreamingErrorEventStopsStream(int|string $code): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['error' => ['code' => $code, 'message' => 'Internal error']],
            ])),
        ]);

        $events = [];
        $error = null;

        try {
            foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
                $events[] = $event;
            }
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $errorEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof Error));

        $this->assertCount(1, $errorEvents);
        $this->assertSame((string) $code, $errorEvents[0]->type);
        $this->assertSame($errorEvents[0], $error);
    }

    /**
     * Provide textual and numeric stream error codes.
     */
    public static function errorCodes(): array
    {
        return [['server_error'], [429]];
    }

    public function testStreamingRejectsPrematureEof(): void
    {
        Http::fake(['*' => Http::response('data: ' . json_encode($this->chatChunk(['content' => 'Partial'])) . "\n\n")]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('incomplete_stream', $error->type);
    }

    public function testStreamingErrorFinishReasonEmitsErrorEvent(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Partial'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'error', 'error' => ['code' => 502, 'message' => 'Provider returned error']]]],
            ])),
        ]);

        $events = [];
        $error = null;

        try {
            foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
                $events[] = $event;
            }
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $errorEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof Error));

        $this->assertCount(1, $errorEvents);
        $this->assertSame('502', $errorEvents[0]->type);
        $this->assertSame($errorEvents[0], $error);
    }

    public function testStreamingCapturesUsageFromFinalChunk(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [], 'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 3]],
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd));
        $this->assertCount(1, $streamEnd);
        $this->assertSame(15, $streamEnd[0]->usage->inputTokens);
        $this->assertSame(3, $streamEnd[0]->usage->outputTokens);
    }

    public function testStreamingReportsCachedTokensWithinTheInputTokenCount(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 5, 'prompt_tokens_details' => ['cached_tokens' => 20, 'cache_write_tokens' => 30]]],
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd));
        $this->assertCount(1, $streamEnd);
        $this->assertSame(100, $streamEnd[0]->usage->inputTokens);
        $this->assertSame(5, $streamEnd[0]->usage->outputTokens);
        $this->assertSame(20, $streamEnd[0]->usage->cacheReadInputTokens);
        $this->assertSame(30, $streamEnd[0]->usage->cacheWriteInputTokens);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsCorrectly(string $apiReason, FinishReason $expected): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
                ['id' => 'chatcmpl-1', 'object' => 'chat.completion.chunk', 'model' => 'anthropic/claude-sonnet-4.6', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $apiReason]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]],
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Hi', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd))[0];

        $this->assertSame($expected->value, $streamEnd->reason);
    }

    /**
     * Provide API finish reasons and their mapped values.
     */
    public static function finishReasons(): array
    {
        return [
            'stop maps to Stop' => ['stop', FinishReason::Stop],
            'tool_calls maps to ToolCalls' => ['tool_calls', FinishReason::ToolCalls],
            'length maps to Length' => ['length', FinishReason::Length],
            'content_filter maps to ContentFilter' => ['content_filter', FinishReason::ContentFilter],
            'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
        ];
    }

    public function testStreamingEmitsCitationEventsForWebSearchAnnotations(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Paris is the capital']),
                $this->chatChunk(['content' => ' of France.', 'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url_citation' => [
                            'url' => 'https://example.com/paris',
                            'title' => 'Paris - Wikipedia',
                            'start_index' => 0,
                            'end_index' => 30,
                        ],
                    ],
                ]]),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('What is the capital of France?', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $citations = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof CitationEvent));

        $this->assertCount(1, $citations);
        $this->assertSame('https://example.com/paris', $citations[0]->citation->url);
        $this->assertSame('Paris - Wikipedia', $citations[0]->citation->title);
        $this->assertSame(0, $citations[0]->citation->startIndex);
        $this->assertSame(30, $citations[0]->citation->endIndex);
    }

    public function testStreamingEmitsMultipleCitationEventsAcrossChunks(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Answer', 'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url_citation' => ['url' => 'https://example.com/one', 'title' => 'One'],
                    ],
                ]]),
                $this->chatChunk(['content' => ' more', 'annotations' => [
                    [
                        'type' => 'url_citation',
                        'url_citation' => ['url' => 'https://example.com/two', 'title' => 'Two'],
                    ],
                ]]),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 2]),
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Question', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $citations = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof CitationEvent));

        $this->assertCount(2, $citations);
        $this->assertSame('https://example.com/one', $citations[0]->citation->url);
        $this->assertSame('https://example.com/two', $citations[1]->citation->url);
    }

    public function testStreamingIgnoresNonUrlCitationAnnotationTypes(): void
    {
        Http::fake([
            '*' => Http::response($this->ssePayload([
                $this->chatChunk(['role' => 'assistant', 'content' => 'Answer', 'annotations' => [
                    ['type' => 'other_type', 'data' => 'something'],
                ]]),
                $this->chatChunkFinish('stop', ['prompt_tokens' => 5, 'completion_tokens' => 2]),
            ])),
        ]);

        $events = [];
        foreach (agent()->stream('Question', provider: 'openrouter') as $event) {
            $events[] = $event;
        }

        $citations = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof CitationEvent));

        $this->assertCount(0, $citations);
    }
}
