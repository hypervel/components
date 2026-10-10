<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

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
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsWithToolsAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class StreamingTest extends TestCase
{
    use AnthropicHelpers;

    public function testStreamingEmitsTextEvents(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => ' world']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
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

    public function testStreamingReportsTheTextBlocksOfOneStepAsASingleMessage(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'First']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search']),
                    $this->contentBlockStop(1),
                    $this->contentBlockStart(2, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(2, ['type' => 'text_delta', 'text' => 'Second']),
                    $this->contentBlockStop(2),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $textStarts = array_values(array_filter($events, fn ($e): bool => $e instanceof TextStart));
        $textEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof TextEnd));
        $textDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof TextDelta));

        // A web search closes the text block and reopens it mid-answer. Reporting each block as
        // its own message made an AG-UI client draw one answer as several, so the step opens and
        // closes exactly one message, and closes it only once the last block has been sent...
        $this->assertCount(1, $textStarts);
        $this->assertCount(1, $textEnds);
        $this->assertCount(2, $textDeltas);
        $this->assertSame($textStarts[0]->messageId, $textEnds[0]->messageId);
        $this->assertSame($textStarts[0]->messageId, $textDeltas[0]->messageId);
        $this->assertSame($textStarts[0]->messageId, $textDeltas[1]->messageId);
        $this->assertGreaterThan(array_search($textDeltas[1], $events, true), array_search($textEnds[0], $events, true));
    }

    public function testStreamingKeepsAThinkingBlockOutOfTheMessageTheStepsTextBelongsTo(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'First']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'thinking', 'thinking' => '']),
                    $this->contentBlockDelta(1, ['type' => 'thinking_delta', 'thinking' => 'Pondering']),
                    $this->contentBlockStop(1),
                    $this->contentBlockStart(2, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(2, ['type' => 'text_delta', 'text' => 'Second']),
                    $this->contentBlockStop(2),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $textStarts = array_values(array_filter($events, fn ($e): bool => $e instanceof TextStart));
        $textEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof TextEnd));
        $textDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof TextDelta));
        $reasoningStarts = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningStart));
        $reasoningEnds = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningEnd));
        $reasoningDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta));

        // Text spans that a thinking block interrupts still belong to one message, and the
        // thinking carries its own ID so the two never merge into each other...
        $this->assertCount(1, $textStarts);
        $this->assertCount(1, $textEnds);
        $this->assertCount(1, $reasoningStarts);
        $this->assertCount(1, $reasoningEnds);
        $this->assertSame($textStarts[0]->messageId, $textDeltas[0]->messageId);
        $this->assertSame($textStarts[0]->messageId, $textDeltas[1]->messageId);
        $this->assertNotSame($textStarts[0]->messageId, $reasoningDeltas[0]->reasoningId);
        $this->assertSame('FirstSecond', TextDelta::combine($events));
        $this->assertSame('Pondering', $reasoningDeltas[0]->delta);
    }

    public function testStreamingHandlesToolCalls(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => '']),
                        $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('tool_use', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The number is 72019']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
                ]),
            ]),
        ]);

        $events = $this->collectStreamEvents(agent: new ProviderOptionsWithToolsAgent);

        $toolCallEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCallEvent));

        $this->assertNotEmpty($toolCallEvents);
        $this->assertSame('FixedNumberGenerator', $toolCallEvents[0]->toolCall->name);
        $this->assertSame('toolu_1', $toolCallEvents[0]->toolCall->id);
    }

    public function testStreamingHandlesThinkingBlocks(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'thinking', 'thinking' => '']),
                    $this->contentBlockDelta(0, ['type' => 'thinking_delta', 'thinking' => 'Let me think...']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Answer']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 15),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $eventTypes = array_map(fn (object $event): string => $event::class, $events);
        $this->assertContains(ReasoningStart::class, $eventTypes);
        $this->assertContains(ReasoningDelta::class, $eventTypes);
        $this->assertContains(ReasoningEnd::class, $eventTypes);

        $reasoningDelta = array_values(array_filter($events, fn ($e): bool => $e instanceof ReasoningDelta))[0];
        $this->assertSame('Let me think...', $reasoningDelta->delta);
    }

    public function testStreamingHandlesServerToolUse(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search']),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Result']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $providerEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ProviderToolEvent));

        $this->assertCount(2, $providerEvents);
        $this->assertSame('started', $providerEvents[0]->status);
        $this->assertSame('srvtoolu_1', $providerEvents[0]->itemId);
        $this->assertSame('anthropic', $providerEvents[0]->provider);
        $this->assertSame('completed', $providerEvents[1]->status);
    }

    public function testStreamingHandlesProviderToolResults(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'search_results' => []]),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'Found it']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $providerEvents = array_values(array_filter($events, fn ($e): bool => $e instanceof ProviderToolEvent));

        $this->assertNotEmpty($providerEvents);
        $this->assertSame('result_received', $providerEvents[0]->status);
        $this->assertSame('web_search_tool_result', $providerEvents[0]->type);
    }

    public function testStreamingPauseTurnTriggersFollowUpStreamWithAssistantReplayed(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'server_tool_use', 'id' => 'srvtoolu_pause', 'name' => 'web_search']),
                        $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{"query":"hypervel ai"}']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('pause_turn', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        $this->messageStart(),
                        $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                        $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Resumed']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('end_turn', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
            ]),
        ]);

        $events = $this->collectStreamEvents();

        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);

        $followUpBody = $recorded[1][0]->data();
        $lastMessage = end($followUpBody['messages']);

        $this->assertSame('assistant', $lastMessage['role']);
        $this->assertSame('server_tool_use', $lastMessage['content'][0]['type']);
        $this->assertInstanceOf(stdClass::class, $lastMessage['content'][0]['input']);
        $this->assertSame('hypervel ai', $lastMessage['content'][0]['input']->query);

        $textDeltas = array_values(array_filter($events, fn ($e): bool => $e instanceof TextDelta));
        $this->assertNotEmpty($textDeltas);
        $this->assertSame('Resumed', $textDeltas[0]->delta);
    }

    public function testStreamingErrorEventStopsStream(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Server overloaded']],
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
        $this->assertSame('overloaded_error', $error->type);
        $this->assertSame('Server overloaded', $error->message);
    }

    public function testStreamingCapturesInputTokensFromMessageStart(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    [
                        'type' => 'message_start',
                        'message' => [
                            'id' => 'msg_1',
                            'model' => 'claude-sonnet-4-6',
                            'role' => 'assistant',
                            'content' => [],
                            'usage' => [
                                'input_tokens' => 42,
                                'output_tokens' => 0,
                                'cache_creation_input_tokens' => 100,
                                'cache_read_input_tokens' => 50,
                            ],
                        ],
                    ],
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10, thinkingTokens: 6),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

        $this->assertSame(192, $streamEnd->usage->inputTokens);
        $this->assertSame(10, $streamEnd->usage->outputTokens);
        $this->assertSame(100, $streamEnd->usage->cacheWriteInputTokens);
        $this->assertSame(50, $streamEnd->usage->cacheReadInputTokens);
        $this->assertSame(6, $streamEnd->usage->reasoningTokens);
    }

    public function testStreamingPrefersTheCumulativeUsageReportedOnMessageDelta(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    [
                        'type' => 'message_start',
                        'message' => [
                            'id' => 'msg_1',
                            'model' => 'claude-sonnet-4-6',
                            'role' => 'assistant',
                            'content' => [],
                            'usage' => [
                                'input_tokens' => 2679,
                                'output_tokens' => 3,
                                'cache_creation_input_tokens' => 0,
                                'cache_read_input_tokens' => 0,
                            ],
                        ],
                    ],
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockStop(0),
                    [
                        'type' => 'message_delta',
                        'delta' => ['stop_reason' => 'end_turn'],
                        'usage' => [
                            'input_tokens' => 10682,
                            'output_tokens' => 510,
                            'cache_creation_input_tokens' => 25,
                            'cache_read_input_tokens' => 75,
                        ],
                    ],
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $streamEnd = array_values(array_filter($events, fn ($e): bool => $e instanceof StreamEnd))[0];

        $this->assertSame(10782, $streamEnd->usage->inputTokens);
        $this->assertSame(510, $streamEnd->usage->outputTokens);
        $this->assertSame(25, $streamEnd->usage->cacheWriteInputTokens);
        $this->assertSame(75, $streamEnd->usage->cacheReadInputTokens);
    }

    public function testStreamingToolLoopEmitsASingleStreamEndWithAccumulatedUsage(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response(
                    body: $this->ssePayload([
                        [
                            'type' => 'message_start',
                            'message' => [
                                'id' => 'msg_1',
                                'model' => 'claude-sonnet-4-6',
                                'role' => 'assistant',
                                'content' => [],
                                'usage' => [
                                    'input_tokens' => 10,
                                    'output_tokens' => 0,
                                    'cache_creation_input_tokens' => 3,
                                    'cache_read_input_tokens' => 2,
                                ],
                            ],
                        ],
                        $this->contentBlockStart(0, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'FixedNumberGenerator', 'input' => '']),
                        $this->contentBlockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{}']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('tool_use', 5),
                    ]),
                    status: 200,
                    headers: ['Content-Type' => 'text/event-stream'],
                ),
                Http::response(
                    body: $this->ssePayload([
                        [
                            'type' => 'message_start',
                            'message' => [
                                'id' => 'msg_2',
                                'model' => 'claude-sonnet-4-6',
                                'role' => 'assistant',
                                'content' => [],
                                'usage' => [
                                    'input_tokens' => 20,
                                    'output_tokens' => 0,
                                    'cache_creation_input_tokens' => 7,
                                    'cache_read_input_tokens' => 8,
                                ],
                            ],
                        ],
                        $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                        $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'The number is 72019']),
                        $this->contentBlockStop(0),
                        $this->messageDelta('end_turn', 10),
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
        $this->assertSame(50, $streamEnds[0]->usage->inputTokens);
        $this->assertSame(15, $streamEnds[0]->usage->outputTokens);
        $this->assertSame(10, $streamEnds[0]->usage->cacheWriteInputTokens);
        $this->assertSame(10, $streamEnds[0]->usage->cacheReadInputTokens);
    }

    #[DataProvider('finishReasons')]
    public function testStreamingFinishReasonMapsCorrectly(string $apiReason, FinishReason $expected): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
                    $this->contentBlockStop(0),
                    $this->messageDelta($apiReason, 10),
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
     * Provide the provider's finish reasons and their normalized values.
     */
    public static function finishReasons(): array
    {
        return [
            'end_turn maps to Stop' => ['end_turn', FinishReason::Stop],
            'stop_sequence maps to Stop' => ['stop_sequence', FinishReason::Stop],
            'max_tokens maps to Length' => ['max_tokens', FinishReason::Length],
            'model_context_window_exceeded maps to Length' => ['model_context_window_exceeded', FinishReason::Length],
            'refusal maps to ContentFilter' => ['refusal', FinishReason::ContentFilter],
            'tool_use without tool blocks normalizes to Stop (StreamEnd still emitted)' => ['tool_use', FinishReason::Stop],
        ];
    }

    public function testStreamingEmitsACitationForAFetchedUrl(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, [
                        'type' => 'web_fetch_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => [
                            'type' => 'web_fetch_result',
                            'url' => 'https://example.com/article',
                            'content' => ['type' => 'document', 'title' => 'Article Title'],
                        ],
                    ]),
                    $this->contentBlockStop(0),
                    $this->contentBlockStart(1, ['type' => 'text', 'text' => '']),
                    $this->contentBlockDelta(1, ['type' => 'text_delta', 'text' => 'The article argues X.']),
                    $this->contentBlockStop(1),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $events = $this->collectStreamEvents();

        $citations = array_values(array_filter($events, fn ($e): bool => $e instanceof CitationEvent));

        $this->assertCount(1, $citations);
        $this->assertSame('https://example.com/article', $citations[0]->citation->url);
        $this->assertSame('Article Title', $citations[0]->citation->title);
    }

    public function testStreamingSkipsCitationsForAFailedFetch(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([
                    $this->messageStart(),
                    $this->contentBlockStart(0, [
                        'type' => 'web_fetch_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible'],
                    ]),
                    $this->contentBlockStop(0),
                    $this->messageDelta('end_turn', 10),
                ]),
                status: 200,
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $citations = array_filter($this->collectStreamEvents(), fn ($e): bool => $e instanceof CitationEvent);

        $this->assertEmpty($citations);
    }
}
