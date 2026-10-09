<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Generator;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\Citation as CitationData;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall as ToolCallData;
use Hypervel\Ai\Responses\Data\ToolResult as ToolResultData;
use Hypervel\Ai\Responses\Data\UrlCitation;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Ai\Streaming\Events\Citation;
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
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Ai\Streaming\Protocols\AgentUserInteractionProtocol;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\FakeConversationStore;
use Hypervel\Tests\Ai\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render supplied stream events through the AG-UI protocol.
 */
function agUiProtocolEvents(array|Closure $events, ?string $threadId = 'thread-1', ?string $runId = 'run-1'): array
{
    $stream = $events instanceof Closure ? $events : fn (): Generator => yield from $events;

    return agUiEvents((new StreamableAgentResponse('invocation-1', $stream, new Meta('anthropic', 'claude-sonnet-4-6')))
        ->usingAgentUserInteractionProtocol($threadId, $runId)
        ->toResponse(request()));
}

/**
 * Decode the AG-UI frames emitted by a response.
 */
function agUiEvents(Response $response): array
{
    $output = '';

    ob_start(function (string $buffer) use (&$output): string {
        $output .= $buffer;

        return '';
    });

    try {
        $response->sendContent();
    } finally {
        ob_end_clean();
    }

    if (($frames = trim($output)) === '') {
        return [];
    }

    return collect(explode("\n\n", $frames))
        ->map(fn (string $frame): array => json_decode(str_replace('data: ', '', $frame), true))
        ->all();
}

/**
 * Build the expected terminal event for an empty-usage run.
 */
function agUiRunFinished(string $reason = 'stop'): array
{
    return [
        'type' => 'RUN_FINISHED',
        'threadId' => 'thread-1',
        'runId' => 'run-1',
        'usage' => [[
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'inputTokens' => 0,
            'outputTokens' => 0,
            'totalTokens' => 0,
        ]],
        'metadata' => ['finishReason' => $reason],
    ];
}

#[WithConfig('database.default', 'testing')]
class AgentUserInteractionProtocolStreamTest extends TestCase
{
    public function testATextStreamEmitsRunStepAndTextMessageEvents(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextStart('event-1', 'msg-1', time()),
            new TextDelta('event-2', 'msg-1', 'Hello.', time()),
            new TextEnd('event-3', 'msg-1', time()),
            new StreamEnd('event-4', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'msg-1', 'role' => 'assistant'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-1', 'delta' => 'Hello.'],
            ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'msg-1'],
            ['type' => 'STEP_FINISHED', 'stepName' => '1'],
            agUiRunFinished(),
        ], $events);
    }

    public function testTheRunFinishedEventCarriesTheCombinedUsageAndFinishReason(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new StreamEnd('event-1', 'length', new TextUsage(inputTokens: 10, outputTokens: 5, reasoningTokens: 2), time()),
        ]);

        $this->assertSame([
            'type' => 'RUN_FINISHED',
            'threadId' => 'thread-1',
            'runId' => 'run-1',
            'usage' => [[
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-6',
                'inputTokens' => 10,
                'outputTokens' => 5,
                'totalTokens' => 15,
                'reasoningTokens' => 2,
            ]],
            'metadata' => ['finishReason' => 'length'],
        ], end($events));
    }

    public function testAMultiStepRunCombinesTheUsageOfEveryStep(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new StreamEnd('event-1', 'tool_calls', new TextUsage(inputTokens: 10, outputTokens: 5), time()),
            new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
            new StreamEnd('event-2', 'stop', new TextUsage(inputTokens: 20, outputTokens: 7), time()),
        ]);

        $this->assertSame([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'inputTokens' => 30,
            'outputTokens' => 12,
            'totalTokens' => 42,
        ], end($events)['usage'][0]);
        $this->assertSame(['finishReason' => 'stop'], end($events)['metadata']);
    }

    public function testTheResponseIsServedAsAnUnbufferedEventStream(): void
    {
        $response = (new StreamableAgentResponse('invocation-1', fn (): Generator => yield from [], new Meta('anthropic', 'claude-sonnet-4-6')))
            ->usingProtocol(new AgentUserInteractionProtocol)
            ->toResponse(request());

        $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache, no-transform', $response->headers->get('Cache-Control'));
    }

    public function testAFirstTurnStreamEmitsTheThreadIdTheConversationIsStoredUnder(): void
    {
        app()->instance(ConversationStore::class, new FakeConversationStore);

        RememberingAssistantAgent::fake(['Fake response']);

        $user = new class {
            public int $id = 1;
        };

        $agent = (new RememberingAssistantAgent)->forUser($user);

        $events = agUiEvents($agent->stream('Hello')->usingProtocol(new AgentUserInteractionProtocol)->toResponse(request()));

        $this->assertNotNull($agent->currentConversation());
        $this->assertSame($agent->currentConversation(), $events[0]['threadId']);
        $this->assertSame($agent->currentConversation(), end($events)['threadId']);
    }

    public function testARememberedStreamReportsTheAssistantRowItWrote(): void
    {
        app()->instance(ConversationStore::class, new FakeConversationStore);

        RememberingAssistantAgent::fake(['Fake response']);

        $user = new class {
            public int $id = 1;
        };

        $response = (new RememberingAssistantAgent)->forUser($user)->stream('Hello');

        $events = agUiEvents($response->usingProtocol(new AgentUserInteractionProtocol)->toResponse(request()));
        $finished = end($events);

        $this->assertNotNull($response->assistantMessageId);
        $this->assertSame(['type', 'threadId', 'runId', 'messageId', 'userMessageId', 'usage', 'metadata'], array_keys($finished));
        $this->assertSame($response->conversationId, $finished['threadId']);
        $this->assertSame($response->invocationId, $finished['runId']);
        $this->assertSame($response->assistantMessageId, $finished['messageId']);
    }

    public function testAStoredRunReportsTheRowItWroteForThePrompt(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../src/ai/database/migrations');
        RememberingAssistantAgent::fake(['Fake response']);

        $user = new class {
            public int $id = 1;
        };

        $response = (new RememberingAssistantAgent)->forUser($user)->stream('Hello');

        $events = agUiEvents($response->usingProtocol(new AgentUserInteractionProtocol)->toResponse(request()));

        $this->assertNotNull($response->userMessageId);
        $this->assertSame($response->userMessageId, end($events)['userMessageId']);
        $this->assertNotSame($response->assistantMessageId, $response->userMessageId);
    }

    public function testAStreamThatPersistsNothingOmitsTheMessageId(): void
    {
        $events = agUiProtocolEvents([
            new TextStart('event-1', 'msg-1', time()),
            new TextDelta('event-2', 'msg-1', 'Hello', time()),
            new TextEnd('event-3', 'msg-1', time()),
            new StreamEnd('event-4', 'stop', new TextUsage, time()),
        ]);

        $this->assertArrayNotHasKey('messageId', end($events));
        $this->assertArrayNotHasKey('userMessageId', end($events));
    }

    public function testAnOwnerlessApprovalStreamPersistsTheThreadIdItEmits(): void
    {
        app()->instance(ConversationStore::class, new FakeConversationStore);

        RememberingApprovableAgent::fake([
            AgentResponse::fakeWithPendingApprovals([
                new PendingApproval('call-1', 'ApprovableNumberGenerator', [], 'Requires approval.'),
            ]),
        ]);

        $agent = new RememberingApprovableAgent;
        $events = agUiEvents($agent->stream('Generate a number')->usingProtocol(new AgentUserInteractionProtocol)->toResponse(request()));

        $this->assertSame($agent->currentConversation(), $events[0]['threadId']);
        $this->assertSame($agent->currentConversation(), end($events)['threadId']);
        $this->assertSame('interrupt', end($events)['outcome']['type']);
    }

    public function testARunWithoutAnExplicitIdentityFallsBackToTheConversationAndInvocationIds(): void
    {
        $response = (new StreamableAgentResponse('invocation-1', fn (): Generator => yield from [
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new StreamEnd('event-1', 'stop', new TextUsage, time()),
        ], new Meta('anthropic', 'claude-sonnet-4-6')))
            ->withinConversation('conversation-1')
            ->usingProtocol(new AgentUserInteractionProtocol);

        $this->assertSame([
            'type' => 'RUN_STARTED',
            'threadId' => 'conversation-1',
            'runId' => 'invocation-1',
        ], agUiEvents($response->toResponse(request()))[0]);
    }

    public function testRenderingTheSameResponseTwiceEmitsTheSameRun(): void
    {
        $response = (new StreamableAgentResponse('invocation-1', fn (): Generator => yield from [
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ], new Meta('anthropic', 'claude-sonnet-4-6')))->usingProtocol(new AgentUserInteractionProtocol);

        $render = fn (): array => agUiEvents($response->toResponse(request()));

        $this->assertSame($render(), $render());
    }

    public function testARunWithoutAConversationGeneratesAThreadId(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new StreamEnd('event-1', 'stop', new TextUsage, time()),
        ], threadId: null, runId: null);

        $this->assertIsString($events[0]['threadId']);
        $this->assertNotEmpty($events[0]['threadId']);
        $this->assertSame('invocation-1', $events[0]['runId']);
    }

    public function testAReasoningStreamWrapsTheReasoningMessageInReasoningEvents(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ReasoningStart('event-1', 'reasoning-1', time()),
            new ReasoningDelta('event-2', 'reasoning-1', 'Considering the options.', time()),
            new ReasoningEnd('event-3', 'reasoning-1', time()),
            new StreamEnd('event-4', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'RUN_STARTED', 'STEP_STARTED',
            'REASONING_START', 'REASONING_MESSAGE_START',
            'REASONING_MESSAGE_CONTENT',
            'REASONING_MESSAGE_END', 'REASONING_END',
            'STEP_FINISHED', 'RUN_FINISHED',
        ], collect($events)->pluck('type')->all());
        $this->assertSame(['type' => 'REASONING_START', 'messageId' => 'reasoning-1'], $events[2]);
        $this->assertSame(['type' => 'REASONING_MESSAGE_START', 'messageId' => 'reasoning-1', 'role' => 'reasoning'], $events[3]);
        $this->assertSame(['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'reasoning-1', 'delta' => 'Considering the options.'], $events[4]);
    }

    public function testAToolExecutedWithinTheRunEmitsItsCallAndResultEvents(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
            new ToolResult('event-2', new ToolResultData('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call-1', 'toolCallName' => 'GetWeather'],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{"city":"Lisbon"}'],
            ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call-1'],
            ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'event-2', 'toolCallId' => 'call-1', 'content' => 'sunny', 'role' => 'tool'],
            ['type' => 'STEP_FINISHED', 'stepName' => '1'],
            agUiRunFinished(),
        ], $events);
    }

    public function testPreliminaryToolOutputStreamsAsAnActivitySnapshotBesideTheTerminalToolResult(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'document_specialist', ['task' => 'Report']), time()),
            new ToolResult('event-2', new ToolResultData('call-1', 'document_specialist', ['task' => 'Report'], 'internal monologue'), true, null, 200, preliminary: true),
            new ToolResult('event-3', new ToolResultData('call-1', 'document_specialist', ['task' => 'Report'], 'done'), true, null, time()),
            new StreamEnd('event-4', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call-1', 'toolCallName' => 'document_specialist'],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{"task":"Report"}'],
            ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call-1'],
            [
                'type' => 'ACTIVITY_SNAPSHOT',
                'messageId' => 'call-1',
                'activityType' => 'TOOL_OUTPUT',
                'content' => [
                    'toolName' => 'document_specialist',
                    'output' => 'internal monologue',
                ],
            ],
            ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'event-3', 'toolCallId' => 'call-1', 'content' => 'done', 'role' => 'tool'],
            ['type' => 'STEP_FINISHED', 'stepName' => '1'],
            agUiRunFinished(),
        ], $events);
    }

    public function testAToolCallWithoutArgumentsStreamsAnEmptyJsonObject(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetTime', []), time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame(['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call-1', 'delta' => '{}'], $events[3]);
    }

    public function testANonStringToolResultIsEncodedAsJsonContent(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
            new ToolResult('event-2', new ToolResultData('call-1', 'GetWeather', ['city' => 'Lisbon'], ['temperature' => 21], resultId: 'result-1'), true, null, time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => 'result-1',
            'toolCallId' => 'call-1',
            'content' => '{"temperature":21}',
            'role' => 'tool',
        ], $events[5]);
    }

    public function testAFailedToolCallStreamsItsErrorAsTheResultContent(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
            new ToolResult('event-2', new ToolResultData('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, 'The city is unknown.', time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame('The city is unknown.', $events[5]['content']);
    }

    public function testAFailedToolCallWithoutAnErrorMessageStreamsADefaultResultContent(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
            new ToolResult('event-2', new ToolResultData('call-1', 'GetWeather', ['city' => 'Lisbon'], null), false, null, time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame('The tool call failed.', $events[5]['content']);
    }

    public function testAMultiStepRunWrapsEachProviderStepInStepEvents(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'GetWeather', ['city' => 'Lisbon']), time()),
            new StreamEnd('event-2', 'tool_calls', new TextUsage, time()),
            new ToolResult('event-3', new ToolResultData('call-1', 'GetWeather', ['city' => 'Lisbon'], 'sunny'), true, null, time()),
            new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextDelta('event-4', 'msg-2', 'Sunny.', time()),
            new StreamEnd('event-5', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'RUN_STARTED', 'STEP_STARTED',
            'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT',
            'STEP_FINISHED', 'STEP_STARTED',
            'TEXT_MESSAGE_CONTENT',
            'STEP_FINISHED', 'RUN_FINISHED',
        ], collect($events)->pluck('type')->all());
        $this->assertSame(['1', '1', '2', '2'], collect($events)->whereIn('type', ['STEP_STARTED', 'STEP_FINISHED'])->pluck('stepName')->all());
    }

    public function testAPausedRunFinishesWithAnInterruptOutcomeForEachPendingApproval(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolCall('event-1', new ToolCallData('call-1', 'DeleteFile', ['path' => 'a.txt']), time()),
            new ToolApprovalRequest('event-2', collect([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
            ]), time()),
            new StreamEnd('event-3', 'tool_calls', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'RUN_FINISHED',
            'threadId' => 'thread-1',
            'runId' => 'run-1',
            'outcome' => [
                'type' => 'interrupt',
                'interrupts' => [[
                    'id' => 'call-1',
                    'reason' => 'approval_required',
                    'message' => 'Destructive operation.',
                    'toolCallId' => 'call-1',
                    'metadata' => [
                        'kind' => 'approval',
                        'toolName' => 'DeleteFile',
                        'input' => ['path' => 'a.txt'],
                    ],
                    'responseSchema' => [
                        'type' => 'object',
                        'properties' => ['approved' => ['type' => 'boolean']],
                        'required' => ['approved'],
                    ],
                ]],
            ],
        ], end($events));
    }

    public function testAPausedRunReportsItsInterruptOutcomeEvenWhenTheStreamLaterThrows(): void
    {
        Exceptions::fake();

        $events = agUiProtocolEvents(function (): Generator {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new ToolApprovalRequest('event-1', collect([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt'], 'Destructive operation.'),
            ]), time());

            throw new RuntimeException('The conversation could not be persisted.');
        });

        $this->assertSame([
            'RUN_STARTED', 'STEP_STARTED', 'STEP_FINISHED', 'RUN_FINISHED',
        ], collect($events)->pluck('type')->all());
        $this->assertSame('call-1', $events[3]['outcome']['interrupts'][0]['id']);

        Exceptions::assertReported(RuntimeException::class);
    }

    public function testAResumeTheStoreRejectsMidStreamEndsTheRealStreamWithTheMismatchCode(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../../src/ai/database/migrations');
        config(['ai.conversations.generate_title' => false]);

        Exceptions::fake();

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push([
                'id' => 'msg_tool_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ApprovableNumberGenerator', 'input' => (object) []]],
                'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ])
            ->push([
                'id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-6',
                'content' => [['type' => 'text', 'text' => 'The number is 72019.']],
                'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $paused = (new RememberingApprovableAgent)->forUser((object) ['id' => 1])->prompt('Generate a number', provider: 'anthropic');

        app()->instance(ConversationStore::class, new class extends DatabaseConversationStore {
            /**
             * Reject approval results while the stream is running.
             */
            public function storeApprovalResults(string $conversationId, array $toolResults): void
            {
                throw new ApprovalMismatchException('The approval results do not match a paused conversation turn.', collect());
            }
        });

        $events = agUiEvents((new RememberingApprovableAgent)
            ->continue($paused->conversationId, (object) ['id' => 1])
            ->stream(Decisions::from(['toolu_1' => true]), provider: 'anthropic')
            ->usingAgentUserInteractionProtocol('thread-1', 'run-1')
            ->toResponse(request()));

        $this->assertSame(['RUN_STARTED', 'STEP_STARTED', 'RUN_ERROR'], collect($events)->pluck('type')->all());
        $this->assertSame([
            'type' => 'RUN_ERROR',
            'message' => 'The approval results do not match a paused conversation turn.',
            'code' => 'approval_mismatch',
        ], $events[2]);

        Exceptions::assertReported(ApprovalMismatchException::class);
    }

    public function testAPendingApprovalWithoutAReasonOmitsTheInterruptMessage(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ToolApprovalRequest('event-1', collect([
                new PendingApproval('call-1', 'DeleteFile', ['path' => 'a.txt']),
            ]), time()),
            new StreamEnd('event-2', 'tool_calls', new TextUsage, time()),
        ]);

        $this->assertArrayNotHasKey('message', end($events)['outcome']['interrupts'][0]);
    }

    public function testAResumedRunEmitsTheApprovedToolResultForThePriorTurnToolCall(): void
    {
        $events = agUiProtocolEvents([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
            new StreamStart('msg-2', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextDelta('event-2', 'msg-2', 'Done.', time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'event-1', 'toolCallId' => 'call-1', 'content' => 'deleted', 'role' => 'tool'],
            ['type' => 'STEP_FINISHED', 'stepName' => '1'],
            ['type' => 'STEP_STARTED', 'stepName' => '2'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-2', 'delta' => 'Done.'],
            ['type' => 'STEP_FINISHED', 'stepName' => '2'],
            agUiRunFinished(),
        ], $events);
    }

    public function testAResumedRunStreamsTheReplayedToolResultWithoutRestatingItsCall(): void
    {
        $events = agUiProtocolEvents([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'deleted'), true, null, time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ], threadId: null, runId: null);

        $this->assertSame([
            'RUN_STARTED', 'STEP_STARTED', 'TOOL_CALL_RESULT', 'STEP_FINISHED', 'RUN_FINISHED',
        ], collect($events)->pluck('type')->all());
    }

    public function testARejectedApprovalStreamsTheRejectionAsTheToolResultContent(): void
    {
        $events = agUiProtocolEvents([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The user rejected this tool call.'), false, 'The user rejected this tool call.', time(), denied: true),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => 'event-1',
            'toolCallId' => 'call-1',
            'content' => 'The user rejected this tool call.',
            'role' => 'tool',
            'metadata' => ['error' => 'The user rejected this tool call.', 'denied' => true],
        ], $events[2]);
    }

    public function testAFailedToolCallReportsAnErrorWithoutMarkingItDenied(): void
    {
        $events = agUiProtocolEvents([
            new ToolResult('event-1', new ToolResultData('call-1', 'DeleteFile', ['path' => 'a.txt'], 'The tool call failed: disk unavailable.'), false, 'The tool call failed: disk unavailable.', time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'TOOL_CALL_RESULT',
            'messageId' => 'event-1',
            'toolCallId' => 'call-1',
            'content' => 'The tool call failed: disk unavailable.',
            'role' => 'tool',
            'metadata' => ['error' => 'The tool call failed: disk unavailable.'],
        ], $events[2]);
    }

    public function testACitedTextStreamEmitsACustomCitationEvent(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new Citation('event-1', 'msg-1', new UrlCitation('https://hypervel.org/docs', 'Hypervel Documentation'), time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'CUSTOM',
            'name' => 'citation',
            'value' => ['url' => 'https://hypervel.org/docs', 'title' => 'Hypervel Documentation'],
        ], $events[2]);
    }

    public function testAUrlCitationWithoutATitleOmitsOnlyTheTitleFromTheCustomEvent(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new Citation('event-1', 'msg-1', new UrlCitation('https://hypervel.org/docs'), time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame(['url' => 'https://hypervel.org/docs'], $events[2]['value']);
    }

    public function testAnUnknownCitationTypeIsSkippedInsteadOfEndingTheRun(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new Citation('event-1', 'msg-1', new class extends CitationData {}, time()),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'RUN_STARTED', 'STEP_STARTED', 'STEP_FINISHED', 'RUN_FINISHED',
        ], collect($events)->pluck('type')->all());
    }

    public function testAProviderHostedToolEmitsACustomProviderToolEvent(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new ProviderToolEvent('event-1', 'item-1', 'web_search_call', ['query' => 'hypervel'], 'completed', time(), 'anthropic'),
            new StreamEnd('event-2', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'type' => 'CUSTOM',
            'name' => 'provider-tool',
            'value' => [
                'provider' => 'anthropic',
                'itemId' => 'item-1',
                'type' => 'web_search_call',
                'data' => ['query' => 'hypervel'],
                'status' => 'completed',
            ],
        ], $events[2]);
    }

    public function testEventsStreamedAfterAnErrorAreDroppedBecauseTheRunHasEnded(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
            new TextDelta('event-2', 'msg-1', 'Ghost.', time()),
            new StreamEnd('event-3', 'stop', new TextUsage, time()),
        ]);

        $this->assertSame([
            'RUN_STARTED', 'STEP_STARTED', 'RUN_ERROR',
        ], collect($events)->pluck('type')->all());
    }

    public function testTheThreadIdAdoptsAConversationIdSurfacedAfterStreamingBegins(): void
    {
        $response = null;

        $response = new StreamableAgentResponse('invocation-1', function () use (&$response): Generator {
            $response->withinConversation('conversation-9');

            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new StreamEnd('event-1', 'stop', new TextUsage, time());
        }, new Meta('anthropic', 'claude-sonnet-4-6'));

        $events = agUiEvents($response->usingProtocol(new AgentUserInteractionProtocol)->toResponse(request()));

        $this->assertSame([
            'type' => 'RUN_STARTED',
            'threadId' => 'conversation-9',
            'runId' => 'invocation-1',
        ], $events[0]);
    }

    public function testAFailedRunEmitsARunErrorInsteadOfARunFinishedEvent(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new TextStart('event-1', 'msg-1', time()),
            new Error('event-2', 'overloaded_error', 'Overloaded', false, time()),
        ]);

        $this->assertSame([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'msg-1', 'role' => 'assistant'],
            ['type' => 'RUN_ERROR', 'message' => 'Overloaded', 'code' => 'overloaded_error'],
        ], $events);
    }

    public function testAStreamEndAfterAnErrorDoesNotFinishTheRun(): void
    {
        $events = agUiProtocolEvents([
            new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time()),
            new Error('event-1', 'overloaded_error', 'Overloaded', false, time()),
            new StreamEnd('event-2', 'error', new TextUsage, time()),
        ]);

        $this->assertSame([
            'RUN_STARTED', 'STEP_STARTED', 'RUN_ERROR',
        ], collect($events)->pluck('type')->all());
    }

    public function testAnExceptionMidRunIsReportedAndEmittedAsAMaskedRunError(): void
    {
        Exceptions::fake();

        $events = agUiProtocolEvents(function (): Generator {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new TextDelta('event-1', 'msg-1', 'Hel', time());

            throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        });

        $this->assertSame([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg-1', 'delta' => 'Hel'],
            ['type' => 'RUN_ERROR', 'message' => 'An error occurred.'],
        ], $events);

        Exceptions::assertReported(RuntimeException::class);
    }

    public function testAProviderStreamErrorFollowedByTheLoopExceptionEmitsASingleRunError(): void
    {
        Exceptions::fake();

        $events = agUiProtocolEvents(function (): Generator {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());

            $error = new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

            yield $error;

            throw new StreamErrorException($error);
        });

        $this->assertSame([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'RUN_ERROR', 'message' => 'Overloaded', 'code' => 'overloaded_error'],
        ], $events);

        Exceptions::assertNothingReported();
    }

    public function testAnUnexpectedExceptionAfterARunErrorIsStillReported(): void
    {
        Exceptions::fake();

        $events = agUiProtocolEvents(function (): Generator {
            yield new StreamStart('msg-1', 'anthropic', 'claude-sonnet-4-6', time());
            yield new Error('event-1', 'overloaded_error', 'Overloaded', false, time());

            throw new RuntimeException('Broken pipe');
        });

        $this->assertSame(1, collect($events)->where('type', 'RUN_ERROR')->count());

        Exceptions::assertReported(RuntimeException::class);
    }

    public function testAFakedMultiStepAgentStreamEmitsAWellFormedRun(): void
    {
        MultiStepToolAgent::fake([
            new ToolCallData('call-1', 'FixedNumberGenerator', []),
            'The number is 72019.',
        ]);

        $response = (new MultiStepToolAgent)->stream('Generate a number')
            ->usingProtocol(new AgentUserInteractionProtocol('thread-1', 'run-1'))
            ->toResponse(request());

        $types = collect(agUiEvents($response))->pluck('type');

        // Collapse the streamed text deltas so only the run's event sequence is asserted...
        $this->assertSame([
            'RUN_STARTED', 'STEP_STARTED',
            'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT',
            'STEP_FINISHED', 'STEP_STARTED',
            'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
            'STEP_FINISHED', 'RUN_FINISHED',
        ], $types->filter(fn (string $type, int $key): bool => $type !== $types->get($key - 1))->values()->all());
    }

    public function testAnExceptionBeforeTheRunStartsEmitsAMaskedRunErrorWithinAStartedRun(): void
    {
        Exceptions::fake();

        $events = agUiProtocolEvents(function (): Generator {
            throw new RuntimeException('Broken pipe');
            yield null;
        });

        $this->assertSame([
            ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
            ['type' => 'STEP_STARTED', 'stepName' => '1'],
            ['type' => 'RUN_ERROR', 'message' => 'An error occurred.'],
        ], $events);

        Exceptions::assertReported(RuntimeException::class);
    }
}
