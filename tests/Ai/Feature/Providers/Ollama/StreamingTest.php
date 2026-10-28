<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

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
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\OllamaHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamingTest extends TestCase
{
    use OllamaHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.ollama.key', '');
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('Hello'),
                    $this->chatChunk(' world'),
                    $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 10, 'eval_count' => 5]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'application/x-ndjson'],
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

    public function testStreamingRequestSetsStreamToTrue(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('Hello'),
                    $this->chatChunk('', true, 'stop'),
                ]),
                status: 200,
            ),
        ]);

        $this->collectStreamEvents();

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);

            return $body['stream'] === true;
        });
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ndjsonPayload([
                        $this->chatChunkWithToolCalls([
                            ['id' => 'call_1', 'function' => ['index' => 0, 'name' => 'FixedNumberGenerator', 'arguments' => ['first' => 'Paris']]],
                        ], done: false),
                        $this->chatChunkWithToolCalls([
                            ['id' => 'call_2', 'function' => ['index' => 1, 'name' => 'FixedNumberGenerator', 'arguments' => ['second' => 'London']]],
                        ], done: false),
                        $this->chatChunk('', true, 'stop'),
                    ]),
                    status: 200,
                ),
                Http::response(
                    body: $this->ndjsonPayload([
                        $this->chatChunk('The number is 72019'),
                        $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 20, 'eval_count' => 10]),
                    ]),
                    status: 200,
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolCallEvent));

        $this->assertCount(2, $toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame('call_1', $toolCallEvents[0]->toolCall->id);
        $this->assertSame(['first' => 'Paris'], $toolCallEvents[0]->toolCall->arguments);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[1]->toolCall->name);
        $this->assertSame('call_2', $toolCallEvents[1]->toolCall->id);
        $this->assertSame(['second' => 'London'], $toolCallEvents[1]->toolCall->arguments);

        Http::assertSentCount(2);
        $body = json_decode(Http::recorded()[1][0]->body(), true);
        $assistant = collect($body['messages'])->first(fn (array $message): bool => isset($message['tool_calls']));
        $this->assertSame([
            ['function' => ['name' => 'FixedNumberGenerator', 'arguments' => ['first' => 'Paris']]],
            ['function' => ['name' => 'FixedNumberGenerator', 'arguments' => ['second' => 'London']]],
        ], $assistant['tool_calls']);
        $this->assertCount(2, array_filter($body['messages'], fn (array $message): bool => $message['role'] === 'tool'));
    }

    public function testStreamingDoesNotExecuteToolCallsWhenDoneReasonIsLength(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ndjsonPayload([
                        $this->chatChunkWithToolCalls([
                            $this->toolCallChunk('call_1', 'FixedNumberGenerator'),
                        ], done: false),
                        $this->chatChunk('', true, 'length', ['prompt_eval_count' => 10, 'eval_count' => 5]),
                    ]),
                    status: 200,
                ),
                Http::response(
                    body: $this->ndjsonPayload([
                        $this->chatChunk('The number is 72019'),
                        $this->chatChunk('', true, 'stop'),
                    ]),
                    status: 200,
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd))[0];

        Http::assertSentCount(1);
        $this->assertSame(FinishReason::Length->value, $streamEnd->reason);
        $this->assertSame([], array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolResult));
    }

    public function testStreamingErrorEventStopsStreamWithStringPayload(): void
    {
        Http::fake([
            '*' => Http::response(
                body: json_encode(['error' => 'model not found']) . "\n",
                status: 200,
            ),
        ]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('unknown_error', $error->type);
        $this->assertSame('model not found', $error->message);
    }

    public function testStreamingErrorEventAlsoHandlesStructuredPayload(): void
    {
        Http::fake([
            '*' => Http::response(
                body: json_encode(['error' => ['code' => 'model_error', 'message' => 'Model not found']]) . "\n",
                status: 200,
            ),
        ]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('model_error', $error->type);
        $this->assertSame('Model not found', $error->message);
    }

    public function testStreamingRejectsPrematureEof(): void
    {
        Http::fake(['*' => Http::response($this->ndjsonPayload([$this->chatChunk('Partial')]))]);

        $error = null;
        $events = [];

        try {
            foreach ((new AssistantAgent)->stream('Hello', provider: 'ollama') as $event) {
                $events[] = $event;
            }
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('incomplete_stream', $error->type);
        $this->assertSame('Partial', TextDelta::combine($events));
        $this->assertEmpty(array_filter($events, fn (StreamEvent $event): bool => $event instanceof TextEnd || $event instanceof StreamEnd));
    }

    public function testStreamingAccumulatesToolCallArgumentsAcrossChunks(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ndjsonPayload([
                        // First chunk: id + name but no arguments yet.
                        [
                            'model' => 'llama3.1:8b',
                            'message' => [
                                'role' => 'assistant',
                                'content' => '',
                                'tool_calls' => [[
                                    'id' => 'call_1',
                                    'function' => [
                                        'name' => 'FixedNumberGenerator',
                                    ],
                                ]],
                            ],
                            'done' => false,
                        ],
                        // Second chunk: argument fragment as partial JSON string.
                        [
                            'model' => 'llama3.1:8b',
                            'message' => [
                                'role' => 'assistant',
                                'content' => '',
                                'tool_calls' => [[
                                    'function' => [
                                        'arguments' => '{"foo":',
                                    ],
                                ]],
                            ],
                            'done' => false,
                        ],
                        // Third chunk: remainder of the arguments payload.
                        [
                            'model' => 'llama3.1:8b',
                            'message' => [
                                'role' => 'assistant',
                                'content' => '',
                                'tool_calls' => [[
                                    'function' => [
                                        'arguments' => '"bar"}',
                                    ],
                                ]],
                            ],
                            'done_reason' => 'tool_calls',
                            'done' => true,
                            'prompt_eval_count' => 1,
                            'eval_count' => 1,
                        ],
                    ]),
                    status: 200,
                ),
                Http::response(
                    body: $this->ndjsonPayload([
                        $this->chatChunk('done'),
                        $this->chatChunk('', true, 'stop'),
                    ]),
                    status: 200,
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolCallEvent));

        $this->assertCount(1, $toolCallEvents);
        $this->assertSame('call_1', $toolCallEvents[0]->toolCall->id);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame(['foo' => 'bar'], $toolCallEvents[0]->toolCall->arguments);
    }

    public function testStreamingGeneratesFallbackIdWhenToolCallHasNoId(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ndjsonPayload([
                        [
                            'model' => 'llama3.1:8b',
                            'message' => [
                                'role' => 'assistant',
                                'content' => '',
                                'tool_calls' => [[
                                    // No "id" — Ollama sometimes omits it.
                                    'function' => [
                                        'name' => 'FixedNumberGenerator',
                                        'arguments' => (object) [],
                                    ],
                                ]],
                            ],
                            'done_reason' => 'tool_calls',
                            'done' => true,
                            'prompt_eval_count' => 1,
                            'eval_count' => 1,
                        ],
                    ]),
                    status: 200,
                ),
                Http::response(
                    body: $this->ndjsonPayload([
                        $this->chatChunk('done'),
                        $this->chatChunk('', true, 'stop'),
                    ]),
                    status: 200,
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof ToolCallEvent));

        $this->assertCount(1, $toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertNotEmpty($toolCallEvents[0]->toolCall->id);
    }

    public function testStreamingCapturesUsageFromFinalChunk(): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('Hello'),
                    $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 42, 'eval_count' => 10]),
                ]),
                status: 200,
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd))[0];

        $this->assertSame(42, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
    }

    public function testStreamingEmitsExactlyOneStreamEndAcrossAToolLoop(): void
    {
        Http::fake([
            '*' => Http::sequence([
                Http::response(
                    body: $this->ndjsonPayload([
                        $this->chatChunkWithToolCalls([
                            $this->toolCallChunk('call_1', 'FixedNumberGenerator'),
                        ]),
                    ]),
                    status: 200,
                ),
                Http::response(
                    body: $this->ndjsonPayload([
                        $this->chatChunk('The number is 72019'),
                        $this->chatChunk('', true, 'stop', ['prompt_eval_count' => 20, 'eval_count' => 10]),
                    ]),
                    status: 200,
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $streamEnds = array_values(array_filter($events, fn (StreamEvent $e): bool => $e instanceof StreamEnd));

        $this->assertCount(1, $streamEnds);
        $this->assertSame($events[count($events) - 1], $streamEnds[0]);
        $this->assertSame(FinishReason::Stop->value, $streamEnds[0]->reason);
        $this->assertSame(30, $streamEnds[0]->usage->inputTokens);
        $this->assertSame(15, $streamEnds[0]->usage->outputTokens);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsCorrectly(string $doneReason, FinishReason $expected): void
    {
        Http::fake([
            '*' => Http::response(
                body: $this->ndjsonPayload([
                    $this->chatChunk('Hello'),
                    $this->chatChunk('', true, $doneReason, ['prompt_eval_count' => 10, 'eval_count' => 5]),
                ]),
                status: 200,
                headers: ['Content-Type' => 'application/x-ndjson'],
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
            'unknown maps to Unknown' => ['unknown_reason', FinishReason::Unknown],
        ];
    }
}
