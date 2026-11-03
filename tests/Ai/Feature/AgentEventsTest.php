<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Exception;
use Hypervel\Ai\AnonymousAgent;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Events\AgentFailed;
use Hypervel\Ai\Events\AgentFailedOver;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\AgentStreamAbandoned;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\PromptingAgent;
use Hypervel\Ai\Events\StartingStep;
use Hypervel\Ai\Events\StepCompleted;
use Hypervel\Ai\Events\StepFailed;
use Hypervel\Ai\Events\StreamingAgent;
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
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\DelegatingAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\DelegatingViaCustomToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\MiddleManagerAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\MultiStepToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\OrchestratorAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RateLimitedToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingThrowingApprovableAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ResearchAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\ConversationStores\InMemoryConversationStore;
use Hypervel\Tests\Ai\Fixtures\FakeConversationStore;
use Hypervel\Tests\Ai\Fixtures\Tools\ApprovableNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

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

    #[DataProvider('publicInvocationMethods')]
    public function testHistorySetupFailurePrecedesProviderLifecycleEvents(string $method): void
    {
        Event::fake();
        Http::preventStrayRequests();
        $exception = new RuntimeException('History unavailable.');
        $agent = new class($exception) extends AssistantAgent implements Conversational {
            /**
             * Hold the history failure.
             */
            public function __construct(private RuntimeException $exception)
            {
            }

            /**
             * Read the unavailable history.
             */
            public function messages(): iterable
            {
                throw $this->exception;
            }
        };

        try {
            $agent->{$method}('Hello');
            $this->fail('History setup must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        Event::assertNotDispatched(PromptingAgent::class);
        Event::assertNotDispatched(StreamingAgent::class);
        Event::assertNotDispatched(AgentFailed::class);
        Http::assertNothingSent();
    }

    /**
     * Provide public invocation entry points.
     */
    public static function publicInvocationMethods(): array
    {
        return [['prompt'], ['stream']];
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

        $tool = new FixedNumberGenerator;

        (new MultiStepToolAgent)->withMiddleware([
            fn (PendingStep $step, Closure $next): StepResult => $next(
                $step->withInstructions('Use the supplied tool.')->withTools([$tool]),
            ),
        ])->prompt('Generate a number');

        [$first, $second] = Event::dispatched(StartingStep::class)->map(fn (array $dispatched): StartingStep => $dispatched[0])->all();

        $this->assertCount(1, $first->messages);
        $this->assertInstanceOf(UserMessage::class, $first->messages[0]);
        $this->assertInstanceOf(TextGenerationOptions::class, $first->options);
        $this->assertSame('Use the supplied tool.', $first->instructions);
        $this->assertSame([$tool], $first->tools);

        // The second step is sent the assistant turn and the tool result the first step produced...
        $this->assertCount(3, $second->messages);
        $this->assertInstanceOf(ToolResultMessage::class, $second->messages[2]);
        $this->assertSame('Use the supplied tool.', $second->instructions);
        $this->assertSame([$tool], $second->tools);
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

        $this->assertCount(1, Event::dispatched(StartingStep::class, fn (StartingStep $event): bool => $event->invocationId === $response->invocationId));
        $this->assertCount(1, Event::dispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->invocationId === $response->invocationId));
    }

    public function testConversationTitleGenerationIsReportedAsItsOwnAgentRun(): void
    {
        $this->prepareConversationTitleRun();

        Event::fake();

        Http::fakeSequence()
            ->pushResponse(fakeGroqResponse('Hello there.'))
            ->pushResponse(fakeGroqResponse('Greeting Conversation'));

        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello', provider: 'groq');

        $title = Event::dispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->parentInvocationId === $response->invocationId)->sole()[0];

        $this->assertNotSame($response->invocationId, $title->invocationId);
        $this->assertSame('groq', $title->prompt->provider->name());
        $this->assertSame('openai/gpt-oss-20b', $title->prompt->model);
        $this->assertSame('Hello', $title->prompt->prompt);
        $this->assertSame('Greeting Conversation', $title->response->text);

        Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->invocationId === $title->invocationId);
    }

    public function testAFailedConversationTitleRunFallsBackToThePromptWithoutFailingTheTurn(): void
    {
        $store = $this->prepareConversationTitleRun();

        Event::fake();

        Http::fakeSequence()
            ->pushResponse(fakeGroqResponse('Hello there.'))
            ->pushStatus(500);

        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello', provider: 'groq');

        $this->assertSame('Hello there.', $response->text);
        $this->assertSame('Hello', $store->conversations[$response->conversationId]['title']);

        $failed = Event::dispatched(AgentFailed::class)->sole()[0];

        $this->assertInstanceOf(AnonymousAgent::class, $failed->prompt->agent);
        $this->assertSame($response->invocationId, $failed->prompt->parentInvocationId);
        $this->assertInstanceOf(RequestException::class, $failed->exception);
    }

    public function testATurnThatFailsAfterAStepReportsItsTitleRunAgainstTheFailedRun(): void
    {
        $this->prepareConversationTitleRun();

        Event::fake();

        Http::fakeSequence()
            ->pushResponse(fakeGroqToolCallResponse())
            ->pushStatus(500)
            ->pushResponse(fakeGroqResponse('Number Report'));

        $this->assertThrows(fn (): AgentResponse => (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt('Generate a number', provider: 'groq'), RequestException::class);

        $failedRun = Event::dispatched(AgentFailed::class)->sole()[0];

        Event::assertDispatched(AgentPrompted::class, fn (AgentPrompted $event): bool => $event->prompt->parentInvocationId === $failedRun->invocationId
            && $event->response->text === 'Number Report');
    }

    public function testAThrowingListenerOnTheTitleRunDoesNotFailTheTurn(): void
    {
        $store = $this->prepareConversationTitleRun();

        Http::fakeSequence()
            ->pushResponse(fakeGroqResponse('Hello there.'))
            ->pushResponse(fakeGroqResponse('Greeting Conversation'));

        Event::listen(AgentPrompted::class, function (AgentPrompted $event): void {
            if ($event->prompt->parentInvocationId !== null) {
                throw new RuntimeException('observability backend is down');
            }
        });

        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello', provider: 'groq');

        $this->assertSame('Hello there.', $response->text);
        $this->assertSame('Hello', $store->conversations[$response->conversationId]['title']);
    }

    public function testDisabledConversationTitleGenerationMakesNoTitleCall(): void
    {
        $this->prepareConversationTitleRun();

        config(['ai.conversations.generate_title' => false]);

        Http::fakeSequence()->pushResponse(fakeGroqResponse('Hello there.'));

        (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->prompt('Hello', provider: 'groq');

        Http::assertSentCount(1);
    }

    /**
     * Remember conversations in memory against a Groq provider that must not send unexpected requests.
     */
    protected function prepareConversationTitleRun(): InMemoryConversationStore
    {
        $this->app->instance(ConversationStore::class, $store = new InMemoryConversationStore);

        config(['ai.providers.groq' => [...config('ai.providers.groq'), 'key' => 'test-key']]);

        Http::preventStrayRequests();

        return $store;
    }

    public function testStepEventsAreDispatchedOnTheStreamingPath(): void
    {
        Event::fake();

        AssistantAgent::fake(['Hello!']);

        $response = (new AssistantAgent)->stream('Hi');

        foreach ($response as $event);

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

    public function testAThrowingToolDispatchesToolFailedAndTheRunContinues(): void
    {
        Event::fake();

        ToolUsingAgent::fake([
            new ToolCall('call_1', 'FixedNumberGenerator', []),
            ['number' => 5],
        ]);

        (new ToolUsingAgent(fixed: true, toolThrowsException: true))->prompt('Generate');

        Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => $event->tool instanceof FixedNumberGenerator
            && $event->exception->getMessage() === 'Forced to throw exception.');

        Event::assertNotDispatched(ToolInvoked::class);

        Event::assertNotDispatched(AgentFailed::class);

        $invoking = Event::dispatched(InvokingTool::class)->first()[0];
        $failed = Event::dispatched(ToolFailed::class)->first()[0];

        $this->assertSame($invoking->toolInvocationId, $failed->toolInvocationId);
        $this->assertSame($invoking->invocationId, $failed->invocationId);
    }

    #[WithConfig('app.debug', false)]
    public function testAToolThatFailsWhileResumingAnApprovalReportsTheFailureOnceAndContinuesTheRun(): void
    {
        Event::fake();
        Exceptions::fake();

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

        // Outside debug mode, the model receives the failure without the exception message...
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->body(), 'The tool call failed.')
            && ! str_contains($request->body(), 'Forced to throw'));

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(fn (Exception $exception): bool => $exception === $failed->exception);
    }

    public function testAToolInvokedListenerExceptionWhileResumingAnApprovalFailsTheRunInsteadOfFailingTheTool(): void
    {
        config(['ai.conversations.generate_title' => false]);

        ApprovableNumberGenerator::$invocations = 0;

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_tool_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'ApprovableNumberGenerator',
                    'input' => (object) [],
                ]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $user = (object) ['id' => 1];

        $paused = (new RememberingApprovableAgent)->forUser($user)->prompt('Generate a number', provider: 'anthropic');

        $this->assertTrue($paused->hasPendingApprovals());

        Event::listen(ToolInvoked::class, fn () => throw new RuntimeException('Listener failed.'));

        $this->assertThrows(
            fn (): mixed => (new RememberingApprovableAgent)
                ->continue($paused->conversationId, $user)
                ->prompt(Decisions::from(['toolu_1' => true]), provider: 'anthropic'),
            RuntimeException::class,
            'Listener failed.',
        );

        // The tool succeeded, so the model is never told it failed...
        $this->assertSame(1, ApprovableNumberGenerator::$invocations);
        Http::assertSentCount(1);
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

    public function testFailoverAfterAToolStartsIsTerminalAndPersistsItsResult(): void
    {
        Event::fake([AgentFailed::class, AgentFailedOver::class]);
        $this->assertFalse(Event::hasListeners(InvokingTool::class));
        config([
            'ai.conversations.generate_title' => false,
            'ai.providers.primary' => ['driver' => 'groq', 'key' => 'test-key'],
            'ai.providers.backup' => ['driver' => 'groq', 'key' => 'test-key'],
        ]);
        $tool = m::mock(NamedTool::class, ['send_email'])->makePartial();
        $tool->shouldReceive('handle')->once()->andReturn('Sent');
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->pushResponse(fakeGroqToolCallResponse('send_email'))
            ->push(status: 429);
        $agent = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->withTools([$tool]);
        $failure = null;

        try {
            $agent->prompt('Send the email', provider: ['primary', 'backup']);
        } catch (RateLimitedException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RateLimitedException::class, $failure);
        Http::assertSentCount(2);
        Event::assertNotDispatched(AgentFailedOver::class);
        Event::assertDispatchedTimes(AgentFailed::class, 1);
        $this->assertSame($failure, Event::dispatched(AgentFailed::class)->first()[0]->exception);
        $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame('Sent', json_decode($row->steps, true)[0]['tool_calls'][0]['result']);
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

        foreach ($response as $event);

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
            foreach ($response as $event);
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
            foreach ($response as $event);
        }, RateLimitedException::class);

        Event::assertDispatchedTimes(AgentFailed::class, 1);
        Event::assertNotDispatched(AgentFailedOver::class);
    }

    #[DataProvider('publicInvocationMethods')]
    public function testFailureRecordingPreservesCancellationBeforeGeneration(string $method): void
    {
        $cancellation = new CanceledException;
        $observerFailure = new RuntimeException('Agent failure observer failed.');
        Event::listen(AgentFailed::class, static fn () => throw $observerFailure);
        Http::preventStrayRequests();
        $agent = (new AssistantAgent)->withMiddleware([static fn () => throw $cancellation]);
        $caught = null;

        try {
            $response = $agent->{$method}('Hello');

            if ($method === 'stream') {
                iterator_to_array($response);
            }
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($observerFailure, $caught);
        $this->assertSame($cancellation, $caught->getPrevious());
        $this->assertTrue(Coroutine::causedByCancellation($caught));
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

    #[DataProvider('publicInvocationMethods')]
    public function testThrowingStepFailureObserverPreservesCancellation(string $method): void
    {
        $cancellation = new CanceledException;
        $observerFailure = new RuntimeException('Step failure observer failed.');
        AssistantAgent::fake(static fn () => throw $cancellation);
        Event::listen(StepFailed::class, static fn () => throw $observerFailure);
        $caught = null;

        try {
            $response = (new AssistantAgent)->{$method}('Hello');

            if ($method === 'stream') {
                iterator_to_array($response);
            }
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($observerFailure, $caught);
        $this->assertSame($cancellation, $caught->getPrevious());
        $this->assertTrue(Coroutine::causedByCancellation($caught));
    }

    public function testThrowingStepFailureObserverPreservesMiddlewareCancellationAfterGeneration(): void
    {
        $cancellation = new CanceledException;
        $observerFailure = new RuntimeException('Step failure observer failed.');
        AssistantAgent::fake(['Hello']);
        Event::listen(StepFailed::class, static fn () => throw $observerFailure);
        $agent = (new AssistantAgent)->withMiddleware([
            static function (PendingStep $step, Closure $next) use ($cancellation): never {
                $next($step);

                throw $cancellation;
            },
        ]);
        $caught = null;

        try {
            $agent->prompt('Hello');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($observerFailure, $caught);
        $this->assertSame($cancellation, $caught->getPrevious());
        $this->assertTrue(Coroutine::causedByCancellation($caught));
    }

    public function testThrowingToolFailureObserverPreservesToolCancellation(): void
    {
        $cancellation = new CanceledException;
        $observerFailure = new RuntimeException('Tool failure observer failed.');
        $tool = m::mock(NamedTool::class, ['cancel_tool'])->makePartial();
        $tool->shouldReceive('handle')->once()->andThrow($cancellation);
        ToolUsingAgent::fake([new ToolCall('call_1', 'cancel_tool', [])]);
        $agent = (new ToolUsingAgent)->withTools([$tool]);
        Event::listen(ToolFailed::class, static fn () => throw $observerFailure);
        $caught = null;

        try {
            $agent->prompt('Generate');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($observerFailure, $caught);
        $this->assertSame($cancellation, $caught->getPrevious());
        $this->assertTrue(Coroutine::causedByCancellation($caught));
    }

    public function testThrowingStreamingToolFailureObserverPreservesCancellation(): void
    {
        $cancellation = new CanceledException;
        $observerFailure = new RuntimeException('Tool failure observer failed.');
        DelegatingAgent::fake([new ToolCall('research', 'research_agent', ['task' => 'Research'])]);
        ResearchAgent::fake(static fn () => throw $cancellation);
        Event::listen(ToolFailed::class, static fn () => throw $observerFailure);
        $caught = null;

        try {
            iterator_to_array((new DelegatingAgent)->stream('Research'));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($observerFailure, $caught);
        $this->assertSame($cancellation, $caught->getPrevious());
        $this->assertTrue(Coroutine::causedByCancellation($caught));
    }

    public function testAFailingStreamDispatchesAgentFailedInsteadOfAgentStreamed(): void
    {
        Event::fake();

        AssistantAgent::fake(fn (): never => throw new RuntimeException('Stream exploded.'));

        $response = (new AssistantAgent)->stream('Hi');

        for ($iteration = 0; $iteration < 2; ++$iteration) {
            $this->assertThrows(function () use ($response): void {
                foreach ($response as $event);
            }, RuntimeException::class, 'Stream exploded.');
        }

        Event::assertDispatched(StepFailed::class);

        Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->invocationId === $response->invocationId
            && $event->exception->getMessage() === 'Stream exploded.');

        Event::assertNotDispatched(AgentStreamed::class);
        Event::assertDispatchedTimes(AgentFailed::class, 2);
        Event::assertNotDispatched(AgentStreamAbandoned::class);
    }

    public function testAStreamPersistenceFailureReportsTheAgentFailure(): void
    {
        Event::fake();
        config(['ai.conversations.generate_title' => false]);
        RememberingAssistantAgent::fake(['Hello']);
        $exception = new RuntimeException('Database unavailable.');
        $store = m::mock(FakeConversationStore::class)->makePartial();
        $store->shouldReceive('storeConversation')->once()->andThrow($exception);
        app()->instance(ConversationStore::class, $store);
        $response = (new RememberingAssistantAgent)->forUser((object) ['id' => 1])->stream('Hi');

        try {
            iterator_to_array($response);
            $this->fail('Persistence must fail.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        Event::assertDispatchedTimes(AgentFailed::class, 1);
        Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->exception === $exception
            && $event->invocationId === $response->invocationId);
        Event::assertNotDispatched(AgentStreamed::class);
        Event::assertNotDispatched(AgentStreamAbandoned::class);
    }

    public function testASuccessListenerFailureDoesNotReportTheStreamAsFailed(): void
    {
        Event::fake([AgentFailed::class, AgentStreamAbandoned::class]);
        AssistantAgent::fake(['Hello']);
        $exception = new RuntimeException('Listener failed.');
        Event::listen(AgentStreamed::class, fn (): never => throw $exception);

        try {
            iterator_to_array((new AssistantAgent)->stream('Hi'));
            $this->fail('The listener must fail.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        Event::assertNotDispatched(AgentFailed::class);
        Event::assertNotDispatched(AgentStreamAbandoned::class);
    }

    public function testAFailedSseWriteReportsAbandonment(): void
    {
        Event::fake();
        AssistantAgent::fake(['Hello']);
        $stream = (new AssistantAgent)->stream('Hi');
        $response = $stream->toResponse(request());
        $this->assertInstanceOf(IterableStreamedResponse::class, $response);
        $response->streamTo(fn (string $chunk): bool => false);

        Event::assertDispatchedTimes(AgentStreamAbandoned::class, 1);
        Event::assertDispatched(AgentStreamAbandoned::class, fn (AgentStreamAbandoned $event): bool => $event->invocationId === $stream->invocationId);
        Event::assertNotDispatched(AgentFailed::class);
        Event::assertNotDispatched(AgentStreamed::class);
    }

    public function testAbandonmentReportsBothTheParentAndNestedAgent(): void
    {
        Event::fake();
        DelegatingAgent::fake([new ToolCall('research', 'research_agent', ['task' => 'Research'])]);
        ResearchAgent::fake([str_repeat('Findings ', 100)]);

        foreach ((new DelegatingAgent)->stream('Research') as $event) {
            if (Event::dispatched(StreamingAgent::class)->count() === 2) {
                break;
            }
        }

        $started = Event::dispatched(StreamingAgent::class)->map(fn (array $events): string => $events[0]->invocationId)->all();
        $abandoned = Event::dispatched(AgentStreamAbandoned::class)->map(fn (array $events): string => $events[0]->invocationId)->all();
        $this->assertCount(2, $started);
        $this->assertEqualsCanonicalizing($started, $abandoned);
        Event::assertNotDispatched(AgentStreamed::class);
        Event::assertNotDispatched(AgentFailed::class);
    }

    public function testStreamingSubAgentCancellationReportsToolFailure(): void
    {
        Event::fake();
        DelegatingAgent::fake([new ToolCall('research', 'research_agent', ['task' => 'Research'])]);
        $exception = new CanceledException('Research canceled.');
        ResearchAgent::fake([fn (): never => throw $exception]);

        try {
            iterator_to_array((new DelegatingAgent)->stream('Research'));
            $this->fail('Cancellation must reach the caller.');
        } catch (CanceledException $caught) {
            $this->assertSame($exception, $caught);
        }

        $invoking = Event::dispatched(InvokingTool::class)->first()[0];
        Event::assertDispatchedTimes(ToolFailed::class, 1);
        Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => $event->exception === $exception
            && $event->toolInvocationId === $invoking->toolInvocationId
            && $event->invocationId === $invoking->invocationId);
        Event::assertNotDispatched(ToolInvoked::class);
        Event::assertDispatchedTimes(AgentFailed::class, 2);
        Event::assertNotDispatched(AgentStreamAbandoned::class);
    }

    public function testAProviderErrorInsideTheStreamClosesTheStepItOpened(): void
    {
        Event::fake();

        config(['ai.providers.only' => ['driver' => 'groq', 'key' => 'test-key']]);

        Http::preventStrayRequests();

        Http::fakeSequence()->pushResponse(fakeGroqStreamErrorResponse('Upstream exploded.'));

        $response = (new AssistantAgent)->stream('Hi', provider: 'only');

        $this->assertThrows(function () use ($response): void {
            foreach ($response as $event);
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
        Event::fakeExcept([ToolFailed::class]);

        Event::listen(ToolFailed::class, fn (ToolFailed $event) => throw $event->exception);

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
