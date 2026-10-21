<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Streaming\Events\Citation as CitationEvent;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ProviderToolEvent;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Tools\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class StreamingTest extends TestCase
{
    use GeminiHelpers;

    /**
     * Build a streaming response payload.
     */
    protected function geminiStream(array $events): array
    {
        return [
            'body' => $this->ssePayload($events),
            'headers' => ['Content-Type' => 'text/event-stream'],
        ];
    }

    /**
     * Create a streaming HTTP response.
     */
    protected function geminiStreamResponse(array $events): PromiseInterface
    {
        return Http::response(
            body: $this->ssePayload($events),
            status: 200,
            headers: ['Content-Type' => 'text/event-stream'],
        );
    }

    public function testStreamingEmitsProviderToolEventsForCodeExecutionSteps(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->geminiStreamResponse([
                $this->stepStart(0, ['type' => 'code_execution_call', 'id' => 'ce_1']),
                $this->stepDelta(0, 'text', 'print(1)'),
                $this->stepStop(0),
                $this->stepStart(1, ['type' => 'code_execution_result', 'id' => 'ce_1']),
                $this->stepDelta(1, 'text', '1'),
                $this->stepStop(1),
                $this->stepStart(2, ['type' => 'model_output']),
                $this->stepDelta(2, 'text', 'The answer is 1.'),
                $this->stepStop(2),
                $this->interactionCompleted(),
            ]),
        ]);

        $providerEvents = array_values(array_filter($this->collectStreamEvents(), fn (StreamEvent $event): bool => $event instanceof ProviderToolEvent));

        $this->assertSame(['completed', 'result_received'], array_map(fn (ProviderToolEvent $event): string => $event->status, $providerEvents));
        $this->assertSame('code_execution', $providerEvents[0]->type);
        $this->assertSame('gemini', $providerEvents[0]->provider);
        $this->assertSame('code_execution', $providerEvents[1]->type);
    }

    public function testStreamingKeepsTheStepPayloadGeminiOnlySendsAsDeltas(): void
    {
        $rawDelta = fn (int $index, array $delta): array => ['event_type' => 'step.delta', 'index' => $index, 'delta' => $delta];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'thought']),
                    $rawDelta(0, ['type' => 'thought_signature', 'signature' => 'sig_abc']),
                    $this->stepStop(0),
                    $this->stepStart(1, ['type' => 'code_execution_call', 'id' => 'ce_1']),
                    $rawDelta(1, ['type' => 'code_execution_call', 'arguments' => ['language' => 'python', 'code' => 'print(1)']]),
                    $this->stepStop(1),
                    $this->stepStart(2, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => []]),
                    $this->stepStop(2),
                    $this->interactionCompleted(),
                ]))
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'model_output']),
                    $this->stepDelta(0, 'text', 'Done.'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent(tools: [new FixedNumberGenerator]));

        $providerEvent = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ProviderToolEvent))[0];

        [$request] = Http::recorded()[1];

        $this->assertSame(['language' => 'python', 'code' => 'print(1)'], $providerEvent->data['arguments']);
        $this->assertSame(['type' => 'thought', 'signature' => 'sig_abc'], $request->data()['input'][1]);
        $this->assertStringContainsString('"name":"FixedNumberGenerator","arguments":{}', $request->body());
    }

    public function testStreamingReplaysProviderToolStepsWhenContinuingAfterAFunctionCall(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'code_execution_call', 'id' => 'ce_1', 'content' => [['type' => 'text', 'text' => 'print(1)']]]),
                    $this->stepStop(0),
                    $this->stepStart(1, ['type' => 'code_execution_result', 'id' => 'ce_1', 'content' => [['type' => 'text', 'text' => '1']]]),
                    $this->stepStop(1),
                    $this->stepStart(2, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => []]),
                    $this->stepStop(2),
                    $this->interactionCompleted(),
                ]))
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'model_output']),
                    $this->stepDelta(0, 'text', 'Done.'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $this->collectStreamEvents(agent(tools: [new FixedNumberGenerator]));

        Http::assertSentCount(2);

        $input = Http::recorded()[1][0]->data()['input'];

        $this->assertSame([
            'user_input', 'code_execution_call', 'code_execution_result', 'function_call', 'function_result',
        ], array_column($input, 'type'));
    }

    public function testStreamingEmitsCitationEventsForAnnotationsOnTheCompletedInteraction(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->geminiStreamResponse([
                $this->stepStart(0, ['type' => 'model_output']),
                $this->stepDelta(0, 'text', 'Spain won Euro 2024.'),
                ['event_type' => 'step.stop', 'index' => 0, 'step' => ['annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://example.com/euro', 'title' => 'Euro 2024'],
                    ['type' => 'url_citation', 'url' => 'https://example.com/spain', 'title' => 'Spain Wins'],
                ]]],
                $this->interactionCompleted(),
            ]),
        ]);

        $citations = array_values(array_filter($this->collectStreamEvents(), fn (StreamEvent $event): bool => $event instanceof CitationEvent));

        $this->assertCount(2, $citations);
        $this->assertSame('https://example.com/euro', $citations[0]->citation->url);
        $this->assertSame('https://example.com/spain', $citations[1]->citation->url);
    }

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->geminiStreamResponse([
                $this->stepStart(0, ['type' => 'model_output']),
                $this->stepDelta(0, 'text', 'Hello'),
                $this->stepDelta(0, 'text', ' world'),
                $this->stepStop(0),
                $this->interactionCompleted(),
            ]),
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

    public function testStreamingPostsToTheInteractionsEndpointWithSseAndTheStreamFlag(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted(),
            ]),
        ]);

        $this->collectStreamEvents();

        $this->assertStringContainsString('interactions?alt=sse', sentRequest()->url());
        $this->assertTrue(sentRequest()->data()['stream']);
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    $this->interactionCompleted(),
                ]))
                ->push(...$this->geminiStream([
                    $this->stepDelta(0, 'text', 'The number is 72019'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame('call_1', $toolCallEvents[0]->toolCall->id);
    }

    public function testStreamingAccumulatesFunctionCallArgumentsFromArgumentDeltas(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->argumentsDelta(0, '{"see'),
                    $this->argumentsDelta(0, 'd": 7}'),
                    $this->stepStop(0),
                    ['event_type' => 'interaction.completed', 'status' => 'completed'],
                ]))
                ->push(...$this->geminiStream([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCall = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent))[0];

        $this->assertSame(['seed' => 7], $toolCall->toolCall->arguments);
    }

    public function testStreamedArgumentDeltasAreReplayedToGeminiAsAnObject(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->argumentsDelta(0, '{"see'),
                    $this->argumentsDelta(0, 'd": 7}'),
                    $this->stepStop(0),
                    $this->interactionCompleted([], 'requires_action'),
                ]))
                ->push(...$this->geminiStream([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $this->assertStringContainsString('"name":"FixedNumberGenerator","arguments":{"seed":7}', Http::recorded()[1][0]->body());
    }

    public function testStreamingToolLoopEmitsASingleStreamEndWithAccumulatedUsage(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    $this->interactionCompleted(['total_input_tokens' => 10, 'total_output_tokens' => 5]),
                ]))
                ->push(...$this->geminiStream([
                    $this->stepDelta(0, 'text', 'The number is 72019'),
                    $this->interactionCompleted(['total_input_tokens' => 20, 'total_output_tokens' => 10]),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $streamEnds = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd));

        $this->assertCount(1, $streamEnds);
        $this->assertSame(FinishReason::Stop->value, $streamEnds[0]->reason);
        $this->assertSame(30, $streamEnds[0]->usage->inputTokens);
        $this->assertSame(15, $streamEnds[0]->usage->outputTokens);
    }

    public function testStreamingReplaysThoughtStepsVerbatimIntoTheToolCallContinuation(): void
    {
        $thought = [
            'type' => 'thought',
            'summary' => [['type' => 'text', 'text' => 'thinking...']],
            'signature' => 'sig_stream_555',
        ];

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'thought']),
                    $this->stepDelta(0, 'thought_summary', 'thinking...'),
                    ['event_type' => 'step.delta', 'index' => 0, 'delta' => ['type' => 'thought_signature', 'signature' => 'sig_stream_555']],
                    $this->stepStop(0),
                    $this->stepStart(1, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(1),
                    $this->interactionCompleted(),
                ]))
                ->push(...$this->geminiStream([
                    $this->stepDelta(0, 'text', 'The number is 72019'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $replayed = array_values(array_filter(
            Http::recorded()[1][0]->data()['input'],
            fn (array $step): bool => $step['type'] === 'thought',
        ));

        $this->assertSame([$thought], $replayed);
    }

    public function testACompletedEventSentWithoutAnInteractionWrapperStillClosesTheStream(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(...$this->geminiStream([
                    $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'FixedNumberGenerator']),
                    $this->stepStop(0),
                    ['event_type' => 'interaction.completed', 'status' => 'completed'],
                ]))
                ->push(...$this->geminiStream([
                    $this->stepDelta(0, 'text', 'Done'),
                    $this->interactionCompleted(),
                ])),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCalls = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));

        $this->assertCount(1, $toolCalls);
        $this->assertSame('call_1', $toolCalls[0]->toolCall->id);
    }

    public function testStreamingHandlesThoughtSummaryDeltas(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->geminiStreamResponse([
                $this->stepStart(0, ['type' => 'thought']),
                $this->stepDelta(0, 'thought_summary', 'Let me think...'),
                $this->stepStop(0),
                $this->stepStart(1, ['type' => 'model_output']),
                $this->stepDelta(1, 'text', 'Answer'),
                $this->stepStop(1),
                $this->interactionCompleted(),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $types = array_map(fn (StreamEvent $event): string => $event::class, $events);
        $this->assertContains(ReasoningStart::class, $types);
        $this->assertContains(ReasoningDelta::class, $types);
        $this->assertContains(ReasoningEnd::class, $types);

        $reasoningDelta = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ReasoningDelta))[0];

        $this->assertSame('Let me think...', $reasoningDelta->delta);
    }

    public function testStreamingErrorEventStopsStream(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->geminiStreamResponse([
                ['event_type' => 'error', 'error' => ['code' => 'overloaded', 'message' => 'Server overloaded']],
            ]),
        ]);

        $error = null;

        try {
            $this->collectStreamEvents();
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('overloaded', $error->type);
        $this->assertSame('Server overloaded', $error->message);
    }

    public function testUnfinishedStreamDoesNotEmitOrExecutePartialToolCalls(): void
    {
        $tool = new GeminiStreamingCountingTool;
        Http::fake(['*' => Http::sequence([
            $this->geminiStreamResponse([
                $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'GeminiStreamingCountingTool']),
                $this->argumentsDelta(0, '{"seed":'),
            ]),
            $this->geminiStreamResponse([$this->interactionCompleted()]),
        ])]);

        $events = [];
        $error = null;

        try {
            foreach (agent(tools: [$tool])->stream('Generate', provider: 'gemini') as $event) {
                $events[] = $event;
            }
        } catch (StreamErrorException $exception) {
            $error = $exception->error;
        }

        $this->assertInstanceOf(Error::class, $error);
        $this->assertSame('incomplete_stream', $error->type);
        $this->assertEmpty(array_filter($events, fn (StreamEvent $event): bool => $event instanceof ToolCallEvent));
        $this->assertSame(0, $tool->calls);
        Http::assertSentCount(1);
    }

    #[DataProvider('failedInteractions')]
    public function testFailedTerminalInteractionPreservesTheError(string $status, array $errors, string $type, string $message): void
    {
        Http::fake(['*' => $this->geminiStreamResponse([
            $this->stepDelta(0, 'text', 'Partial'),
            ['event_type' => 'interaction.completed', 'interaction' => ['status' => $status, 'errors' => $errors]],
        ])]);

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
     * Provide failed interactions with and without detailed errors.
     */
    public static function failedInteractions(): array
    {
        return [
            'failed' => ['failed', [['code' => 'overloaded', 'message' => 'Server overloaded']], 'overloaded', 'Server overloaded'],
            'cancelled' => ['cancelled', [], 'cancelled', 'The Gemini interaction did not complete.'],
        ];
    }

    #[DataProvider('interruptedToolCalls')]
    public function testInterruptedToolCallsDoNotRun(string $status, array $errors, FinishReason $reason): void
    {
        $tool = new GeminiStreamingCountingTool;
        Http::fake(['*' => Http::sequence([
            $this->geminiStreamResponse([
                $this->stepStart(0, ['type' => 'function_call', 'id' => 'call_1', 'name' => 'GeminiStreamingCountingTool', 'arguments' => []]),
                $this->stepStop(0),
                ['event_type' => 'interaction.completed', 'interaction' => ['status' => $status, 'errors' => $errors]],
            ]),
            $this->geminiStreamResponse([$this->interactionCompleted()]),
        ])]);

        $events = $this->collectStreamEvents(agent(tools: [$tool]));
        $end = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd));

        $this->assertCount(1, $end);
        $this->assertSame($reason->value, $end[0]->reason);
        $this->assertSame(0, $tool->calls);
        $this->assertEmpty(array_filter($events, fn (StreamEvent $event): bool => $event instanceof Error));
        Http::assertSentCount(1);
    }

    /**
     * Provide terminal statuses that must take precedence over tool calls.
     */
    public static function interruptedToolCalls(): array
    {
        return [
            'incomplete' => ['incomplete', [], FinishReason::Length],
            'blocked' => ['failed', [['code' => 'BLOCKED_SAFETY', 'message' => 'Blocked for safety reasons.']], FinishReason::ContentFilter],
        ];
    }

    public function testStreamingCapturesUsageFromTheCompletedEvent(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted([
                    'total_input_tokens' => 42,
                    'total_output_tokens' => 10,
                    'total_cached_tokens' => 5,
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame(42, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
        $this->assertSame(5, $streamEnd->usage->cacheReadInputTokens);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsFromTheInteractionStatus(string $status, FinishReason $expected): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => $this->geminiStreamResponse([
                $this->stepDelta(0, 'text', 'Hello'),
                $this->interactionCompleted([], $status),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn (StreamEvent $event): bool => $event instanceof StreamEnd))[0];

        $this->assertSame($expected->value, $streamEnd->reason);
    }

    /**
     * Provide terminal interaction statuses.
     */
    public static function finishReasons(): array
    {
        return [
            'completed maps to Stop' => ['completed', FinishReason::Stop],
            'incomplete maps to Length' => ['incomplete', FinishReason::Length],
        ];
    }
}

class GeminiStreamingCountingTool extends FixedNumberGenerator
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
