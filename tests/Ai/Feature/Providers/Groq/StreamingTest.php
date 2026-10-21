<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\GroqHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;

class StreamingTest extends TestCase
{
    use GroqHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.groq.key', 'test-key');
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(
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

    public function testStreamingEmitsProviderToolEventsForExecutedBuiltInTools(): void
    {
        $running = ['name' => 'python', 'index' => 0, 'type' => 'function', 'arguments' => 'print(1 + 1)'];
        $finished = [...$running, 'output' => "2\n", 'code_results' => [['text' => '2']]];

        Http::fake([
            'api.groq.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['executed_tools' => [$running]]),
                    $this->chatChunk(['executed_tools' => [$finished]]),
                    $this->chatChunk(['content' => '2']),
                    $this->chatChunkFinish('stop', ['prompt_tokens' => 10, 'completion_tokens' => 5]),
                    '[DONE]',
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = array_values(array_filter($this->collectStreamEvents(), fn (StreamEvent $event): bool => $event instanceof ProviderToolEvent));

        $this->assertCount(2, $events);
        $this->assertSame('python', $events[0]->type);
        $this->assertSame('in_progress', $events[0]->status);
        $this->assertSame('completed', $events[1]->status);
        $this->assertSame($finished, $events[1]->data);
        $this->assertSame('groq', $events[1]->provider);
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence([
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

    public function testStreamingToolCallLoopEmitsASingleAccumulatedStreamEnd(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence([
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

    #[DataProvider('streamErrors')]
    public function testStreamingErrorEventStopsStream(array $chunk, string $type, string $message): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(
                body: $this->ssePayload([
                    $chunk,
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
        $this->assertSame($type, $error->type);
        $this->assertSame($message, $error->message);
    }

    /**
     * Provide the documented stream error envelopes.
     */
    public static function streamErrors(): array
    {
        return [
            'top level' => [['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit exceeded']], 'rate_limit_exceeded', 'Rate limit exceeded'],
            'Groq metadata' => [['x_groq' => ['error' => 'Generation stopped early']], 'unknown_error', 'Generation stopped early'],
        ];
    }

    #[DataProvider('usageLocations')]
    public function testStreamingCapturesUsageFromFinalChunk(bool $nested): void
    {
        $usage = ['prompt_tokens' => 42, 'completion_tokens' => 10];
        $terminal = $this->chatChunkFinish('stop', $nested ? null : $usage);
        $chunks = [$this->chatChunk(['role' => 'assistant', 'content' => 'Hello']), $terminal];

        if ($nested) {
            $chunks[] = ['choices' => [], 'usage' => null, 'x_groq' => ['usage' => $usage]];
        }

        $chunks[] = '[DONE]';
        Http::fake([
            'api.groq.com/*' => Http::response(
                body: $this->ssePayload($chunks),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame(42, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
    }

    /**
     * Provide supported usage locations in the final chunks.
     */
    public static function usageLocations(): array
    {
        return ['top level' => [false], 'Groq metadata trailer' => [true]];
    }

    public function testStreamingRejectsAnUnfinishedToolCall(): void
    {
        $tool = new GroqStreamingCountingTool;

        Http::fake(['api.groq.com/*' => Http::response(
            $this->ssePayload([
                $this->chatChunkToolCallStart(0, 'call_1', 'GroqStreamingCountingTool'),
                $this->chatChunkToolCallDelta(0, '{"seed":'),
            ]),
            headers: ['Content-Type' => 'text/event-stream'],
        )]);

        $events = [];
        $error = null;

        try {
            foreach (agent(tools: [$tool])->stream('Hello', provider: 'groq') as $event) {
                $events[] = $event;
            }
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('incomplete_stream', $error->type);
        $this->assertSame([], array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));
        $this->assertSame(0, $tool->calls);
    }

    public function testStreamingCapturesReasoningTokens(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->chatChunk(['role' => 'assistant', 'content' => 'The answer is 4.']),
                    $this->chatChunkFinish('stop', [
                        'prompt_tokens' => 100,
                        'completion_tokens' => 50,
                        'total_tokens' => 150,
                        'completion_tokens_details' => [
                            'reasoning_tokens' => 20,
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
        $this->assertSame(20, $streamEnd->usage->reasoningTokens);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsCorrectly(string $apiReason, FinishReason $expected): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(
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
            'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
        ];
    }
}

class GroqStreamingCountingTool extends FixedNumberGenerator
{
    public int $calls = 0;

    /**
     * Count tool executions.
     */
    public function handle(Request $request): string
    {
        ++$this->calls;

        return parent::handle($request);
    }
}
