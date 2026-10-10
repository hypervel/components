<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Exception;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Events\AgentFailed;
use Hypervel\Ai\Events\AgentFailedOver;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\PromptingAgent;
use Hypervel\Ai\Events\StartingStep;
use Hypervel\Ai\Events\StepCompleted;
use Hypervel\Ai\Events\StepFailed;
use Hypervel\Ai\Events\ToolFailed;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Exceptions\StreamErrorException;
use Hypervel\Ai\Gateway\ParentInvocation;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\DelegatingAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\DelegatingViaCustomToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\MiddleManagerAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\OrchestratorAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RateLimitedToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingThrowingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ResearchAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\FakeConversationStore;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use RuntimeException;

use function Hypervel\Tests\Ai\Fixtures\fakeGroqResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqStreamErrorResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqStreamResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqStreamToolCallResponse;
use function Hypervel\Tests\Ai\Fixtures\fakeGroqToolCallResponse;

require_once __DIR__ . '/../Fixtures/helpers.php';

class AgentEventsTest extends TestCase
{
    use RefreshDatabase;

    public function testASynchronousPromptThreadsOneInvocationIdThroughThePromptAndItsEvents(): void
    {
        Event::fake();

        AssistantAgent::fake(['Hello!']);

        $response = (new AssistantAgent)->prompt('Hi');

        Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->invocationId === $response->invocationId
            && $event->prompt->invocationId === $response->invocationId);

        Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->invocationId === $response->invocationId);
    }

    public function testStepMiddlewareReceivesTheInvocationIdTheRunReports(): void
    {
        AssistantAgent::fake(['Hello!']);

        $seen = null;

        $response = (new AssistantAgent)->withMiddleware([
            function (PendingStep $step, Closure $next) use (&$seen): StepResult {
                $seen = $step->invocationId;

                return $next($step);
            },
        ])->prompt('Hi');

        $this->assertNotNull($seen);
        $this->assertSame($response->invocationId, $seen);
    }

    public function testEveryFailoverAttemptSharesTheRunInvocationId(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->pushResponse(fakeGroqResponse('Hello from the backup.'));

        $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

        $this->assertSame('Hello from the backup.', $response->text);

        Event::assertDispatched(AgentFailedOver::class, fn (AgentFailedOver $event): bool => $event->invocationId === $response->invocationId);

        $invocationIds = Event::dispatched(PromptingAgent::class)
            ->map(fn (array $dispatched): string => $dispatched[0]->invocationId)
            ->unique();

        $this->assertCount(1, $invocationIds);
    }

    public function testANestedSubAgentSharingTheParentProviderInstanceDoesNotOverwriteTheParentToolInvocationId(): void
    {
        Event::fake();

        // Unfaked agents share one memoized provider, and therefore one generation loop, which is the case the tool invocation id has to survive...
        config([
            'ai.default' => 'shared',
            'ai.providers.shared' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->pushResponse(fakeGroqToolCallResponse('middle_manager', ['task' => 'Deep-dive on Hypervel caching'], 'call_001'))
            ->pushResponse(fakeGroqToolCallResponse('research_agent', ['task' => 'Research Hypervel caching internals'], 'call_002'))
            ->pushResponse(fakeGroqResponse('Deep research result'))
            ->pushResponse(fakeGroqResponse('Research delegated.'))
            ->pushResponse(fakeGroqResponse('Delegated to middle manager.'));

        (new OrchestratorAgent)->prompt('Do a deep dive on Hypervel caching');

        $invoking = Event::dispatched(InvokingTool::class)->map(fn (array $dispatched): InvokingTool => $dispatched[0]);
        $invoked = Event::dispatched(ToolInvoked::class)->map(fn (array $dispatched): ToolInvoked => $dispatched[0]);

        $this->assertCount(2, $invoking);
        $this->assertCount(2, $invoked);

        $delegatedTo = fn (string $agent): Closure => fn (InvokingTool|ToolInvoked $event): bool => $event->tool instanceof AgentTool
            && $event->tool->agent() instanceof $agent;

        foreach ([MiddleManagerAgent::class, ResearchAgent::class] as $agent) {
            $start = $invoking->first($delegatedTo($agent));
            $end = $invoked->first($delegatedTo($agent));

            $this->assertNotNull($end);
            $this->assertSame($start->toolInvocationId, $end->toolInvocationId);
        }

        $this->assertCount(2, $invoking->map(fn (InvokingTool $event): string => $event->toolInvocationId)->unique());
    }

    public function testStepEventsAreDispatchedForEachStepOfAToolCallingRun(): void
    {
        Event::fake();

        MultiStepToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'The number is 72019.',
        ]);

        $response = (new MultiStepToolAgent)->prompt('Generate a number');

        Event::assertDispatchedTimes(StartingStep::class, 2);
        Event::assertDispatchedTimes(StepCompleted::class, 2);

        Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->invocationId === $response->invocationId
            && $event->stepNumber === 0
            && $event->isFinalStep === false
            && $event->model !== '');

        Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->invocationId === $response->invocationId
            && $event->stepNumber === 0
            && $event->response->finishReason === FinishReason::ToolCalls
            && count($event->response->toolCalls) === 1);

        Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->stepNumber === 1
            && $event->response->finishReason === FinishReason::Stop
            && $event->response->toolCalls === []);
    }

    public function testStepCompletedCarriesTheWholeStepResponse(): void
    {
        Event::fake();

        AssistantAgent::fake(['Hello!']);

        $agent = new AssistantAgent;

        $agent->prompt('Hi');

        $starting = Event::dispatched(StartingStep::class)->first()[0];
        $completed = Event::dispatched(StepCompleted::class)->first()[0];

        // The response travels whole so a consumer can record the text and usage of a step, not only that it finished...
        $this->assertInstanceOf(StepResponse::class, $completed->response);
        $this->assertSame('Hello!', $completed->response->text);
        $this->assertNotEmpty($completed->response->meta->model);
        $this->assertNotEmpty($completed->response->meta->provider);
        $this->assertInstanceOf(TextUsage::class, $completed->response->usage);
        $this->assertSame($agent, $completed->agent);
        $this->assertSame($agent, $starting->agent);
        $this->assertIsFloat($completed->time);
        $this->assertGreaterThan(0.0, $completed->time);
    }

    public function testStartingStepCarriesTheMessagesAndOptionsTheStepIsSentWith(): void
    {
        Event::fake();

        MultiStepToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'The number is 72019.',
        ]);

        (new MultiStepToolAgent)->prompt('Generate a number');

        [$first, $second] = Event::dispatched(StartingStep::class)->map(fn (array $dispatched): StartingStep => $dispatched[0])->all();

        $this->assertCount(1, $first->messages);
        $this->assertInstanceOf(UserMessage::class, $first->messages[0]);
        $this->assertInstanceOf(TextGenerationOptions::class, $first->options);

        // The second step is sent the assistant turn and the tool result the first step produced...
        $this->assertCount(3, $second->messages);
        $this->assertInstanceOf(ToolResultMessage::class, $second->messages[2]);
    }

    public function testConversationTitleGenerationDoesNotReportStepsAgainstTheRunThatTriggeredIt(): void
    {
        app()->instance(ConversationStore::class, new FakeConversationStore);

        Event::fake();

        RememberingAssistantAgent::fake(['Fake response', 'A Nice Title']);

        $user = new class {
            public int $id = 1;
        };

        $response = (new RememberingAssistantAgent)->forUser($user)->prompt('Test prompt');

        // Title generation runs its own loop on the same provider, and must not be attributed to this run...
        Event::assertDispatchedTimes(StartingStep::class, 1);
        Event::assertDispatchedTimes(StepCompleted::class, 1);

        Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->invocationId === $response->invocationId
            && $event->stepNumber === 0);
    }

    public function testStepEventsAreDispatchedOnTheStreamingPath(): void
    {
        Event::fake();

        AssistantAgent::fake(['Hello!']);

        $response = (new AssistantAgent)->stream('Hi');

        foreach ($response as $event) {
        }

        Event::assertDispatchedTimes(StartingStep::class, 1);

        Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->invocationId === $response->invocationId
            && $event->stepNumber === 0
            && $event->response->finishReason === FinishReason::Stop);
    }

    public function testAStepThatThrowsCarriesNoStreamError(): void
    {
        Event::fake();

        config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

        Http::preventStrayRequests();

        Http::fakeSequence()->push(status: 429);

        $this->assertThrows(fn (): AgentResponse => (new AssistantAgent)->prompt('Hi', provider: 'only'), RateLimitedException::class);

        $failed = Event::dispatched(StepFailed::class)->first()[0];

        $this->assertInstanceOf(RateLimitedException::class, $failed->exception);
        $this->assertNotInstanceOf(StreamErrorException::class, $failed->exception);
        $this->assertIsFloat($failed->time);
        $this->assertGreaterThan(0.0, $failed->time);
    }

    public function testAFailedStepIdentifiesTheProviderAndModelThatFailedIt(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->pushResponse(fakeGroqResponse('Hello from the backup.'));

        (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

        $starting = Event::dispatched(StartingStep::class)->first()[0];
        $failed = Event::dispatched(StepFailed::class)->first()[0];

        // Failover attempts share an invocation id and both restart at step zero, so the step events must name their own provider...
        $this->assertInstanceOf(AssistantAgent::class, $failed->agent);
        $this->assertSame($starting->provider, $failed->provider);
        $this->assertSame($starting->model, $failed->model);
    }

    public function testEveryStepEventNamesTheProviderAndModelTheStepRanAgainst(): void
    {
        Event::fake();

        MultiStepToolAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            'The number is 72019.',
        ]);

        (new MultiStepToolAgent)->prompt('Generate a number');

        $starting = Event::dispatched(StartingStep::class)->first()[0];
        $completed = Event::dispatched(StepCompleted::class)->first()[0];

        // A consumer builds a span from whichever event it sees, so the identity is read the same way on all of them...
        $this->assertSame($starting->provider, $completed->provider);
        $this->assertSame($starting->model, $completed->model);
        $this->assertSame($starting->isFinalStep, $completed->isFinalStep);
        $this->assertSame($starting->agent, $completed->agent);
    }

    public function testAThrowingToolDispatchesToolFailedAndStillPropagatesTheException(): void
    {
        Event::fake();

        ToolUsingAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            ['number' => 5],
        ]);

        $this->assertThrows(fn (): AgentResponse => (new ToolUsingAgent(fixed: true, toolThrowsException: true))->prompt('Generate'), Exception::class, 'Forced to throw exception.');

        Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => $event->tool instanceof FixedNumberGenerator
            && $event->exception->getMessage() === 'Forced to throw exception.');

        Event::assertNotDispatched(ToolInvoked::class);

        Event::assertDispatched(AgentFailed::class);

        $invoking = Event::dispatched(InvokingTool::class)->first()[0];
        $failed = Event::dispatched(ToolFailed::class)->first()[0];

        $this->assertSame($invoking->toolInvocationId, $failed->toolInvocationId);
        $this->assertSame($invoking->invocationId, $failed->invocationId);
    }

    public function testAToolThatFailsWhileResumingAnApprovalReportsTheFailureOnceAndContinuesTheRun(): void
    {
        Event::fake();

        config(['ai.conversations.generate_title' => false]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence([
                Http::response([
                    'id' => 'msg_tool_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'toolu_1',
                        'name' => 'ThrowingApprovableGenerator',
                        'input' => (object) [],
                    ]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
                Http::response([
                    'id' => 'msg_2',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-4-6',
                    'content' => [['type' => 'text', 'text' => 'The tool could not run.']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingThrowingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());

        $resumed = (new RememberingThrowingApprovableAgent)
            ->continue($paused->conversationId, $user)
            ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic');

        // The resume path turns the failure into a tool result, so the run survives but still reports the failure exactly once...
        Event::assertDispatchedTimes(ToolFailed::class, 1);
        Event::assertNotDispatched(ToolInvoked::class);
        Event::assertNotDispatched(AgentFailed::class);

        $this->assertSame('The tool could not run.', $resumed->text);

        $failed = Event::dispatched(ToolFailed::class)->first()[0];
        $invoking = Event::dispatched(InvokingTool::class)->first()[0];

        $this->assertSame('Forced to throw exception.', $failed->exception->getMessage());
        $this->assertSame($invoking->toolInvocationId, $failed->toolInvocationId);
    }

    public function testASubAgentPromptIsLinkedToTheParentInvocationAndToolInvocation(): void
    {
        Event::fake();

        DelegatingAgent::fake([
            new ToolCall('call_123', 'research_agent', ['task' => 'Research Hypervel']),
            'Research delegated.',
        ]);

        ResearchAgent::fake(['Research result']);

        $response = (new DelegatingAgent)->prompt('Delegate research about Hypervel');

        $invoking = Event::dispatched(InvokingTool::class)->first()[0];

        Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->prompt->agent instanceof ResearchAgent
            && $event->prompt->parentInvocationId === $response->invocationId
            && $event->prompt->parentToolInvocationId === $invoking->toolInvocationId);

        Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->prompt->agent instanceof DelegatingAgent
            && $event->prompt->parentInvocationId === null
            && $event->prompt->parentToolInvocationId === null);
    }

    public function testAnAgentPromptedFromAHandWrittenToolIsLinkedToTheParentInvocation(): void
    {
        Event::fake();

        DelegatingViaCustomToolAgent::fake([
            new ToolCall('call_1', 'AgentCallingTool', []),
            'Done.',
        ]);

        ResearchAgent::fake(['Research result']);

        $response = (new DelegatingViaCustomToolAgent)->prompt('Go');

        $invoking = Event::dispatched(InvokingTool::class)->first()[0];

        Event::assertDispatched(PromptingAgent::class, fn (PromptingAgent $event): bool => $event->prompt->agent instanceof ResearchAgent
            && $event->prompt->parentInvocationId === $response->invocationId
            && $event->prompt->parentToolInvocationId === $invoking->toolInvocationId);
    }

    public function testTheParentInvocationIsRestoredOnceAToolCallFinishes(): void
    {
        Event::fake();

        DelegatingAgent::fake([
            new ToolCall('call_1', 'research_agent', ['task' => 'Research Hypervel']),
            'Delegated.',
        ]);

        ResearchAgent::fake(['Research result']);

        (new DelegatingAgent)->prompt('Delegate');

        ResearchAgent::fake(['Standalone result']);

        (new ResearchAgent)->prompt('Standalone');

        $standalone = Event::dispatched(PromptingAgent::class)
            ->map(fn (array $dispatched): AgentPrompt => $dispatched[0]->prompt)
            ->last(fn (AgentPrompt $prompt): bool => $prompt->agent instanceof ResearchAgent);

        $this->assertNull($standalone->parentInvocationId);
        $this->assertNull($standalone->parentToolInvocationId);
    }

    public function testTheParentInvocationNeverTravelsOutsideTheCurrentProcess(): void
    {
        $insideDehydratedContext = null;

        ParentInvocation::within('inv_1', 'tool_1', function () use (&$insideDehydratedContext): void {
            $insideDehydratedContext = Context::dehydrate();

            $this->assertSame(['inv_1', 'tool_1'], ParentInvocation::current());
        });

        // Queued jobs serialize the log context, so the delegating pair must never be stored there...
        $this->assertNull($insideDehydratedContext);
        $this->assertSame([null, null], ParentInvocation::current());
    }

    public function testARecoverableFailoverDoesNotReportTheRunAsFailed(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->pushResponse(fakeGroqResponse('Hello from the backup.'));

        $response = (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']);

        $this->assertSame('Hello from the backup.', $response->text);

        // The recoverable attempt is reported through the per-step event, not the terminal one...
        Event::assertDispatched(StepFailed::class, fn (StepFailed $event): bool => $event->invocationId === $response->invocationId);
        Event::assertDispatched(AgentFailedOver::class);
        Event::assertDispatched(AgentPrompted::class);
        Event::assertNotDispatched(AgentFailed::class);
    }

    public function testARecoverableFailoverDoesNotReportTheStreamedRunAsFailed(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->pushResponse(fakeGroqStreamResponse('Hello from the backup.'));

        $response = (new AssistantAgent)->stream('Hi', provider: ['primary', 'backup']);

        foreach ($response as $event) {
        }

        Event::assertDispatched(StepFailed::class);
        Event::assertDispatched(AgentFailedOver::class);
        Event::assertDispatched(AgentStreamed::class);
        Event::assertNotDispatched(AgentFailed::class);
    }

    public function testARunThatExhaustsEveryProviderReportsTheFailureOnce(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(status: 429);

        $this->assertThrows(fn (): AgentResponse => (new AssistantAgent)->prompt('Hi', provider: ['primary', 'backup']), RateLimitedException::class);

        Event::assertDispatchedTimes(AgentFailedOver::class, 1);
        Event::assertDispatchedTimes(AgentFailed::class, 1);

        $failed = Event::dispatched(AgentFailed::class)->first()[0];
        $failedOver = Event::dispatched(AgentFailedOver::class)->first()[0];

        $this->assertSame($failedOver->invocationId, $failed->invocationId);
        $this->assertSame('Hi', $failed->prompt->prompt);
    }

    public function testAStreamedRunThatExhaustsEveryProviderReportsTheFailureOnce(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()
            ->push(status: 429)
            ->push(status: 429);

        $response = (new AssistantAgent)->stream('Hi', provider: ['primary', 'backup']);

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $event) {
            }
        }, RateLimitedException::class);

        Event::assertDispatchedTimes(AgentFailedOver::class, 1);
        Event::assertDispatchedTimes(AgentFailed::class, 1);

        Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId);
    }

    public function testASingleProviderRunWithNoFailoverStillReportsAFailoverableFailure(): void
    {
        Event::fake();

        config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

        Http::preventStrayRequests();

        Http::fakeSequence()->push(status: 429);

        $this->assertThrows(fn (): AgentResponse => (new AssistantAgent)->prompt('Hi', provider: 'only'), RateLimitedException::class);

        Event::assertDispatchedTimes(AgentFailed::class, 1);
        Event::assertNotDispatched(AgentFailedOver::class);
    }

    public function testASingleProviderStreamWithNoFailoverStillReportsAFailoverableFailure(): void
    {
        Event::fake();

        config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

        Http::preventStrayRequests();

        Http::fakeSequence()->push(status: 429);

        $response = (new AssistantAgent)->stream('Hi', provider: 'only');

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $event) {
            }
        }, RateLimitedException::class);

        Event::assertDispatchedTimes(AgentFailed::class, 1);
        Event::assertNotDispatched(AgentFailedOver::class);
    }

    public function testAFailingGatewayDispatchesStepFailedAndAgentFailedInsteadOfAgentPrompted(): void
    {
        Event::fake();

        AssistantAgent::fake([fn (): never => throw new RuntimeException('Provider exploded.')]);

        $this->assertThrows(fn (): AgentResponse => (new AssistantAgent)->prompt('Hi'), RuntimeException::class, 'Provider exploded.');

        Event::assertDispatched(StepFailed::class, fn (StepFailed $event): bool => $event->stepNumber === 0
            && $event->exception->getMessage() === 'Provider exploded.');

        Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->prompt->prompt === 'Hi'
            && $event->exception->getMessage() === 'Provider exploded.');

        Event::assertNotDispatched(AgentPrompted::class);

        $stepFailed = Event::dispatched(StepFailed::class)->first()[0];
        $agentFailed = Event::dispatched(AgentFailed::class)->first()[0];

        $this->assertSame($agentFailed->invocationId, $stepFailed->invocationId);
    }

    public function testAFailingStreamDispatchesAgentFailedInsteadOfAgentStreamed(): void
    {
        Event::fake();

        AssistantAgent::fake([fn (): never => throw new RuntimeException('Stream exploded.')]);

        $response = (new AssistantAgent)->stream('Hi');

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $event) {
            }
        }, RuntimeException::class, 'Stream exploded.');

        Event::assertDispatched(StepFailed::class);

        Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId
            && $event->exception->getMessage() === 'Stream exploded.');

        Event::assertNotDispatched(AgentStreamed::class);
    }

    public function testAProviderErrorInsideTheStreamClosesTheStepItOpened(): void
    {
        Event::fake();

        config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

        Http::preventStrayRequests();

        Http::fakeSequence()->pushResponse(fakeGroqStreamErrorResponse('Upstream exploded.'));

        $response = (new AssistantAgent)->stream('Hi', provider: 'only');

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $event) {
            }
        }, StreamErrorException::class, 'Upstream exploded.');

        // The provider reported the error in the stream rather than throwing, but the step still has to close...
        Event::assertDispatchedTimes(StartingStep::class, 1);
        Event::assertDispatchedTimes(StepFailed::class, 1);
        Event::assertNotDispatched(StepCompleted::class);
        Event::assertDispatched(AgentFailed::class);
        Event::assertNotDispatched(AgentStreamed::class);

        $starting = Event::dispatched(StartingStep::class)->first()[0];
        $failed = Event::dispatched(StepFailed::class)->first()[0];

        $this->assertSame($starting->invocationId, $failed->invocationId);
        $this->assertSame($starting->stepNumber, $failed->stepNumber);
        $this->assertInstanceOf(StreamErrorException::class, $failed->exception);
        $this->assertSame('Upstream exploded.', $failed->exception->getMessage());

        // The provider's own classification rides on the exception, so a listener never loses the error's type or metadata...
        $this->assertInstanceOf(Error::class, $failed->exception->error);
        $this->assertSame('Upstream exploded.', $failed->exception->error->message);
        $this->assertSame('server_error', $failed->exception->error->type);
    }

    public function testAStreamedFailureAfterTheStreamStartedReportsTheRunAsFailed(): void
    {
        Event::fake();

        config([
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);

        Http::preventStrayRequests();

        Http::fakeSequence()->pushResponse(fakeGroqStreamToolCallResponse('RateLimitedNumberGenerator'));

        $response = (new RateLimitedToolAgent)->stream('Generate', provider: ['primary', 'backup']);

        $seen = 0;

        // The stream had already reached the consumer, so the run can no longer be replayed against the backup...
        $this->assertThrows(function () use ($response, &$seen): void {
            foreach ($response as $event) {
                ++$seen;
            }
        }, RateLimitedException::class, 'Rate limited while running the tool.');

        $this->assertGreaterThan(0, $seen);

        Event::assertDispatchedTimes(AgentFailed::class, 1);
        Event::assertNotDispatched(AgentFailedOver::class);
    }
}
