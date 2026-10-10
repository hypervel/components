<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Contracts\HasMiddleware;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\AgentFailed;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\StartingStep;
use Hypervel\Ai\Events\StepCompleted;
use Hypervel\Ai\Events\StepFailed;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\StepResult;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Jobs\InvokeAgent;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\PendingStep;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Ai\Streaming\Events\StreamEnd;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\ToolChoice;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\CapturingStepGateway;
use Hypervel\Tests\Ai\Fixtures\FakeConversationStore;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

#[WithConfig('ai.conversations.generate_title', false)]
#[WithConfig('ai.default', 'anthropic')]
class AgentMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function testAgentMiddlewareWrapsEveryGenerationStep(): void
    {
        AssistantAgent::fake([new ToolCall('call_1', 'FixedNumberGenerator', []), 'Fake response']);
        $seen = [];

        $response = (new AssistantAgent)
            ->withTools([new FixedNumberGenerator])
            ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen): StepResult {
                $seen[] = [$step->number, count($step->messages), count($step->steps), Arr::last($step->steps)?->toolCalls[0]->id];

                return $next($step);
            }])
            ->prompt('Test prompt');

        $this->assertSame('Fake response', $response->text);
        $this->assertSame([[0, 1, 0, null], [1, 3, 1, 'call_1']], $seen);
    }

    public function testAgentMiddlewareWrapsEveryGenerationStepWhenStreaming(): void
    {
        AssistantAgent::fake([new ToolCall('call_1', 'FixedNumberGenerator', []), 'Fake response']);
        $seen = [];
        $response = (new AssistantAgent)
            ->withTools([new FixedNumberGenerator])
            ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen): StepResult {
                $seen[] = $step->number;

                return $next($step);
            }])
            ->stream('Test prompt');
        $text = null;
        $response->each(fn (): true => true)->then(function (StreamedAgentResponse $response) use (&$text): void {
            $text = $response->text;
        });

        $this->assertSame('Fake response', $text);
        $this->assertSame([0, 1], $seen);
    }

    public function testAgentMiddlewareSeesTheProviderAccumulatedUsageAndFinalStepFlag(): void
    {
        $gateway = new CapturingStepGateway;
        $seen = [];
        (new TextGenerationLoop($gateway))->generate(
            Ai::textProviderFor(new AssistantAgent, 'openai'),
            'gpt-test',
            'Be helpful.',
            [new UserMessage('Hi')],
            [new FixedNumberGenerator],
            options: new TextGenerationOptions(maxSteps: 2, agent: (new AssistantAgent)->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen): StepResult {
                $seen[] = [$step->provider, $step->isFinalStep, $step->usage->inputTokens, $step->usage->outputTokens];

                return $next($step);
            }])),
        );

        $this->assertSame([['openai', false, 0, 0], ['openai', true, 10, 5]], $seen);
    }

    public function testAgentMiddlewareThenCallbackReceivesTheStepResponseBeforeItsToolsRun(): void
    {
        AssistantAgent::fake([new ToolCall('call_1', 'FixedNumberGenerator', []), 'Fake response']);
        $seen = [];
        Event::listen(InvokingTool::class, function () use (&$seen): void {
            $seen[] = 'tool';
        });

        (new AssistantAgent)
            ->withTools([new FixedNumberGenerator])
            ->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen): StepResult {
                return $next($step)->then(function (StepResponse $response) use (&$seen): void {
                    $seen[] = $response->finishReason->value;
                });
            }])->prompt('Test prompt');

        $this->assertSame(['tool_calls', 'tool', 'stop'], $seen);
    }

    public function testAnOuterMiddlewareReceivesAStepResultWhenAnInnerMiddlewareShortCircuits(): void
    {
        AssistantAgent::fake(['Fake response']);
        $seen = [];
        $observer = function (PendingStep $step, Closure $next) use (&$seen): StepResult {
            return $next($step)->then(function (StepResponse $response) use (&$seen): void {
                $seen[] = $response->text;
            });
        };
        $response = (new AssistantAgent)->withMiddleware([$observer, $this->shortCircuitingMiddleware()])->prompt('Test prompt');

        $this->assertSame('Short-circuited response', $response->text);
        $this->assertSame(['Short-circuited response'], $seen);

        $streamed = (new AssistantAgent)->withMiddleware([$observer, $this->shortCircuitingMiddleware()])->stream('Test prompt');
        iterator_to_array($streamed, false);

        $this->assertSame('Short-circuited response', $streamed->text);
        $this->assertSame(['Short-circuited response', 'Short-circuited response'], $seen);
    }

    public function testAFailedStepReportsTheModelTheMiddlewareChose(): void
    {
        Event::fake([StepFailed::class]);
        $exception = new RuntimeException('Provider down.');
        AssistantAgent::fake([fn () => throw $exception]);

        try {
            (new AssistantAgent)->withMiddleware([fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('other-model'))])->prompt('Test prompt');
            $this->fail('The provider exception was not thrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        Event::assertDispatched(StepFailed::class, fn (StepFailed $event): bool => $event->model === 'other-model');
    }

    public function testAgentMiddlewareThenCallbackRunsOnceAStreamedStepHasDrained(): void
    {
        AssistantAgent::fake(['Fake response']);
        $seen = [];
        $response = (new AssistantAgent)->withMiddleware([function (PendingStep $step, Closure $next) use (&$seen): StepResult {
            return $next($step)->then(function (StepResponse $response) use (&$seen): void {
                $seen[] = $response->text;
            });
        }])->stream('Test prompt');

        $this->assertSame([], $seen);
        foreach ($response as $event);

        $this->assertSame(['Fake response'], $seen);
    }

    public function testAgentMiddlewareMayShortCircuitAStep(): void
    {
        AssistantAgent::fake(['Fake response']);
        $response = (new AssistantAgent)->withMiddleware([$this->shortCircuitingMiddleware()])->prompt('Test prompt');

        $this->assertSame('Short-circuited response', $response->text);
        $this->assertCount(1, $response->steps);
    }

    public function testAgentMiddlewareThatReadsTheResponseOfAStreamedStepStillYieldsItsEvents(): void
    {
        AssistantAgent::fake(['Fake response']);
        $response = (new AssistantAgent)->withMiddleware([function (PendingStep $step, Closure $next): StepResponse|StepResult {
            $result = $next($step);

            return $result->response()->text === '' ? new StepResponse('Fallback', [], FinishReason::Stop, new TextUsage, new Meta) : $result;
        }])->stream('Test prompt');
        $events = iterator_to_array($response, false);

        $this->assertNotEmpty(array_filter($events, fn (object $event): bool => $event instanceof TextDelta));
        $this->assertSame('Fake response', $response->text);
    }

    public function testAgentMiddlewareThatIteratesAStreamedStepItselfStillYieldsItsEvents(): void
    {
        AssistantAgent::fake(['Fake response']);
        $response = (new AssistantAgent)->withMiddleware([function (PendingStep $step, Closure $next): StepResult {
            $result = $next($step);
            foreach ($result as $event);

            return $result;
        }])->stream('Test prompt');
        iterator_to_array($response, false);

        $this->assertSame('Fake response', $response->text);
    }

    public function testAgentMiddlewareThatReplacesAStreamedStepResponseNarratesTheReplacement(): void
    {
        AssistantAgent::fake(['Fake response']);
        $response = (new AssistantAgent)->withMiddleware([function (PendingStep $step, Closure $next): StepResponse|StepResult {
            $result = $next($step);

            return $result->response()->text === 'Fake response' ? new StepResponse('Replaced', [], FinishReason::Stop, new TextUsage, new Meta) : $result;
        }])->stream('Test prompt');
        $deltas = array_values(array_filter(iterator_to_array($response, false), fn (object $event): bool => $event instanceof TextDelta));

        $this->assertSame(['Replaced'], array_map(fn (TextDelta $event): string => $event->delta, $deltas));
    }

    public function testStepProviderOptionsOverrideTheAgentsOwn(): void
    {
        Event::fake([StartingStep::class]);
        $agent = new class extends AssistantAgent implements HasProviderOptions {
            /**
             * Return the agent's provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return ['reasoning' => 'high', 'store' => false];
            }
        };
        $agent::fake(['Fake response']);
        $agent->withMiddleware([fn (PendingStep $step, Closure $next): StepResult => $next($step->withProviderOptions(['reasoning' => 'low']))])->prompt('Test prompt');

        Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->options->providerOptions('openai') === ['reasoning' => 'low', 'store' => false]);
    }

    public function testAgentMiddlewareMayShortCircuitAStreamedStep(): void
    {
        AssistantAgent::fake(['Fake response']);
        $response = (new AssistantAgent)->withMiddleware([$this->shortCircuitingMiddleware()])->stream('Test prompt');
        $events = iterator_to_array($response, false);

        $this->assertSame([StreamStart::class, TextStart::class, TextDelta::class, TextEnd::class, StreamEnd::class], array_map(fn (object $event): string => $event::class, $events));
        $this->assertSame('Short-circuited response', $response->text);
    }

    public function testAgentMiddlewareMayChangeTheModelAndOptionsForAStep(): void
    {
        Event::fake([StartingStep::class, StepCompleted::class]);
        AssistantAgent::fake(['Fake response']);
        $response = (new AssistantAgent)->withMiddleware([fn (PendingStep $step, Closure $next): StepResult => $next(
            $step->withModel('other-model')->withMaxTokens(1000)->withToolChoice('none')->withProviderOptions(['reasoning' => 'low']),
        )])->prompt('Test prompt');

        $this->assertSame('other-model', $response->meta->model);
        Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->model === 'other-model'
            && $event->options->maxTokens === 1000
            && $event->options->toolChoice->mode === ToolChoice::none
            && $event->options->providerOptions('openai') === ['reasoning' => 'low']);
        Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->model === 'other-model');
    }

    public function testAStreamedResponseReportsTheModelTheMiddlewareChose(): void
    {
        Event::fake([StepCompleted::class]);
        AssistantAgent::fake(['Fake response']);
        $response = (new AssistantAgent)->withMiddleware([fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('other-model'))])->stream('Test prompt');
        $response->each(fn (): true => true)->then(function (StreamedAgentResponse $response): void {
            $this->assertSame('other-model', $response->meta->model);
        });

        Event::assertDispatched(StepCompleted::class, fn (StepCompleted $event): bool => $event->model === 'other-model');
    }

    public function testAgentMiddlewareThatReplacesTheHistoryOrTheModelDropsTheProviderContinuationToken(): void
    {
        $gateway = new CapturingStepGateway;
        $run = function (Closure $middleware) use ($gateway): array {
            $this->runThroughGateway($gateway, [$middleware]);

            return array_map(fn (array $call): ?string => $call['context']->continuationToken, $gateway->calls);
        };

        $this->assertSame([null, 'resp_1'], $run(fn (PendingStep $step, Closure $next): StepResult => $next($step)));
        $this->assertSame([null, null], $run(fn (PendingStep $step, Closure $next): StepResult => $next($step->withMessages(array_slice($step->messages, -1)))));
        $this->assertSame([null, null], $run(fn (PendingStep $step, Closure $next): StepResult => $next($step->isFirstStep() ? $step : $step->withModel('cheaper-model'))));
        $this->assertSame([null, null], $run(fn (PendingStep $step, Closure $next): StepResult => $next($step->isFirstStep() ? $step->withModel('cheaper-model') : $step)));
        $this->assertSame([null, 'resp_1'], $run(fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('cheaper-model'))));
        $this->assertSame([null, null], $run(fn (PendingStep $step, Closure $next): StepResult => $next($step->isFirstStep() ? $step : $step->withInstructions('Wrap up.'))));
    }

    public function testAgentMiddlewareMayNarrowTheToolsAndInstructionsForAStep(): void
    {
        $gateway = new CapturingStepGateway;
        $this->runThroughGateway($gateway, [fn (PendingStep $step, Closure $next): StepResult => $next($step->isFirstStep()
            ? $step->withoutTools('custom_named_tool', 'WebSearch')->withInstructions('Plan first.')
            : $step->onlyTools('custom_named_tool'))], tools: [new FixedNumberGenerator, new NamedTool, new WebSearch]);

        $this->assertCount(1, $gateway->calls[0]['tools']);
        $this->assertInstanceOf(FixedNumberGenerator::class, $gateway->calls[0]['tools'][0]);
        $this->assertSame('Plan first.', $gateway->calls[0]['instructions']);
        $this->assertCount(1, $gateway->calls[1]['tools']);
        $this->assertInstanceOf(NamedTool::class, $gateway->calls[1]['tools'][0]);
        $this->assertSame('Be helpful.', $gateway->calls[1]['instructions']);
    }

    public function testAgentMiddlewareMustReturnTheNextStepResultOrAStepResponse(): void
    {
        AssistantAgent::fake(['Fake response']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Agent middleware must return the next step result or a StepResponse.');
        (new AssistantAgent)->withMiddleware([fn (PendingStep $step, Closure $next): string => 'nope'])->prompt('Test prompt');
    }

    public function testAgentMiddlewareThatFailsBeforeTheModelIsCalledFailsTheRunWithoutAStepFailure(): void
    {
        Event::fake([StepFailed::class, AgentFailed::class]);
        AssistantAgent::fake(['Fake response']);
        $exception = new RuntimeException('Blocked by middleware.');

        try {
            (new AssistantAgent)->withMiddleware([fn (PendingStep $step, Closure $next) => throw $exception])->prompt('Test prompt');
            $this->fail('Middleware did not throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        Event::assertNotDispatched(StepFailed::class);
        Event::assertDispatched(AgentFailed::class, fn (AgentFailed $event): bool => $event->exception->getMessage() === 'Blocked by middleware.');
    }

    public function testStreamResponseConversationIdIsAvailableAfterRememberedConversationsStreamCompletes(): void
    {
        $this->app->instance(ConversationStore::class, new FakeConversationStore);
        RememberingAssistantAgent::fake(['Fake response']);
        $user = new class {
            public int $id = 1;
        };
        $agent = (new RememberingAssistantAgent)->forUser($user);
        $response = $agent->stream('Test prompt');
        foreach ($response as $event) {
            $this->assertNotNull($event);
        }

        $this->assertNotNull($response->conversationId);
        $this->assertSame($agent->currentConversation(), $response->conversationId);
        $this->assertSame($user, $response->conversationUser);
    }

    public function testStreamResponseConversationIdIsAvailableWhenContinuingAnExistingConversation(): void
    {
        $this->app->instance(ConversationStore::class, new FakeConversationStore);
        RememberingAssistantAgent::fake(['Fake response']);
        $user = new class {
            public int $id = 1;
        };
        $response = (new RememberingAssistantAgent)->continue('existing-conversation-id', $user)->stream('Test prompt');
        foreach ($response as $event) {
            $this->assertNotNull($event);
        }

        $this->assertSame('existing-conversation-id', $response->conversationId);
        $this->assertSame($user, $response->conversationUser);
    }

    public function testStreamResponseConversationIdSyncsAfterLateThenCallbacks(): void
    {
        AssistantAgent::fake(['Fake response']);
        $user = new class {
            public int $id = 1;
        };
        $response = (new AssistantAgent)->stream('Test prompt');
        foreach ($response as $event) {
            $this->assertNotNull($event);
        }
        $response->then(function (StreamedAgentResponse $response) use ($user): void {
            $response->withinConversation('late-conversation-id', $user);
        });

        $this->assertSame('late-conversation-id', $response->conversationId);
        $this->assertSame($user, $response->conversationUser);
    }

    public function testStreamResponsePreservesManuallyAssignedConversationIdWithoutAParticipant(): void
    {
        AssistantAgent::fake(['Fake response']);
        $response = (new AssistantAgent)->stream('Test prompt')->withinConversation('manual-conversation-id');
        foreach ($response as $event) {
            $this->assertNotNull($event);
        }

        $this->assertSame('manual-conversation-id', $response->conversationId);
        $this->assertNull($response->conversationUser);
    }

    public function testAnOwnerlessSuccessfulStreamDoesNotRetainAnUnpersistedConversationId(): void
    {
        RememberingAssistantAgent::fake(['Fake response']);
        $agent = new RememberingAssistantAgent;
        $response = $agent->stream('Test prompt');
        foreach ($response as $_);

        $this->assertNull($agent->currentConversation());
        $this->assertNull($response->conversationId);
        $this->assertNull($response->conversationUser);
    }

    public function testStepMiddlewareThatCompactsTheHistoryDoesNotChangeWhatTheConversationRemembers(): void
    {
        RememberingAssistantAgent::fake([new ToolCall('call_1', 'FixedNumberGenerator', []), 'Fake response']);
        $user = (object) ['id' => 1];
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation('user', $user->id, 'Compacted conversation');
        $sent = [];
        $response = (new RememberingAssistantAgent)
            ->withTools([new FixedNumberGenerator])
            ->withMiddleware([
                fn (PendingStep $step, Closure $next): StepResult => $next($step->isFirstStep() ? $step : $step->withMessages(array_slice($step->messages, -1))),
                function (PendingStep $step, Closure $next) use (&$sent): StepResult {
                    $sent[] = count($step->messages);

                    return $next($step);
                },
            ])->continue($conversationId, $user)->prompt('Test prompt');
        $assistant = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();
        $remembered = $store->getLatestConversationMessages($conversationId, 10);

        $this->assertSame([1, 1], $sent);
        $this->assertSame('Fake response', $response->text);
        $this->assertSame('Fake response', $assistant->content);
        $this->assertCount(1, json_decode((string) $assistant->steps, true)[0]['tool_calls']);
        $this->assertSame('72019', json_decode((string) $assistant->steps, true)[0]['tool_calls'][0]['result']);
        $this->assertSame([Message::class, AssistantMessage::class, ToolResultMessage::class, AssistantMessage::class], $remembered->map(fn (Message $message): string => $message::class)->all());
    }

    public function testRuntimeMiddlewareAppendsOutsideDeclaredMiddleware(): void
    {
        RuntimeMiddlewareAgent::fake(['First', 'Second']);
        $agent = (new RuntimeMiddlewareAgent)
            ->withMiddleware([static fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('runtime'))])
            ->withMiddleware([new RuntimeModelMiddleware]);

        $this->assertSame('runtime-object-declared', $agent->prompt('First')->meta->model);
        $this->assertSame('runtime-object-declared', $agent->prompt('Second')->meta->model);
    }

    #[DataProvider('streamProviders')]
    public function testRuntimeMiddlewareIsCapturedBeforeStreamConsumption(array $providers): void
    {
        RuntimeMiddlewareAgent::fake([
            ...count($providers) > 1 ? [static fn () => throw RateLimitedException::forProvider('anthropic')] : [],
            'Streamed response',
        ]);
        $agent = (new RuntimeMiddlewareAgent)->withMiddleware([
            static fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('captured')),
        ]);
        $stream = $agent->stream('First', provider: $providers);
        $agent->withMiddleware([static fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('later'))]);
        $model = null;
        $stream->then(static function (StreamedAgentResponse $response) use (&$model): void {
            $model = $response->meta->model;
        });
        iterator_to_array($stream, false);

        $this->assertSame('captured-declared', $model);
    }

    /**
     * Supply single-provider and lazy failover stream factories.
     */
    public static function streamProviders(): array
    {
        return [['providers' => ['anthropic']], ['providers' => ['anthropic', 'anthropic']]];
    }

    public function testQueuedAgentsExecuteRuntimeMiddlewareAfterSerialization(): void
    {
        Queue::fake()->serializeAndRestore();
        Event::fake([StartingStep::class]);
        RuntimeMiddlewareAgent::fake(['Queued response']);
        (new RuntimeMiddlewareAgent)->withMiddleware([
            static fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('queued')),
        ])->queue('Test prompt');

        Queue::assertPushed(InvokeAgent::class, function (InvokeAgent $job): bool {
            $job->handle();

            return true;
        });
        Event::assertDispatched(StartingStep::class, fn (StartingStep $event): bool => $event->model === 'queued-declared');
    }

    public function testOptionsCarryRuntimeMiddlewareThroughCopiesWithoutPromptable(): void
    {
        $gateway = new CapturingStepGateway;
        $options = (new TextGenerationOptions(maxSteps: 2, agent: self::createStub(Agent::class), toolChoice: ToolChoice::from('required')))
            ->withMiddleware([static fn () => throw new RuntimeException('Replaced middleware ran.')])
            ->withMiddleware([static fn (PendingStep $step, Closure $next): StepResult => $next($step->withModel('options-model'))])
            ->withMaxTokens(100);

        (new TextGenerationLoop($gateway))->generate(
            self::createConfiguredStub(TextProvider::class, ['name' => 'test-provider']),
            'original-model',
            'Be helpful.',
            [new UserMessage('Hi')],
            [new FixedNumberGenerator],
            options: $options,
        );

        $this->assertSame(['options-model', 'options-model'], array_column($gateway->calls, 'model'));
    }

    /**
     * Run middleware through a gateway while retaining its captured calls.
     */
    private function runThroughGateway(CapturingStepGateway $gateway, array $middleware, array $tools = [new FixedNumberGenerator]): void
    {
        $gateway->calls = [];
        (new TextGenerationLoop($gateway))->generate(
            Ai::textProviderFor(new AssistantAgent, 'openai'),
            'gpt-test',
            'Be helpful.',
            [new UserMessage('Hi')],
            $tools,
            options: TextGenerationOptions::forAgent((new AssistantAgent)->withMiddleware($middleware)),
        );
    }

    /**
     * Return middleware that answers without calling the provider.
     */
    private function shortCircuitingMiddleware(): object
    {
        return new class {
            /**
             * Answer the step directly.
             */
            public function handle(PendingStep $step, Closure $next): StepResponse
            {
                return new StepResponse('Short-circuited response', [], FinishReason::Stop, new TextUsage, new Meta);
            }
        };
    }
}

class RuntimeMiddlewareAgent implements Agent, HasMiddleware
{
    use Promptable;

    /**
     * Return the agent instructions.
     */
    public function instructions(): string
    {
        return 'Be helpful.';
    }

    /**
     * Return declared middleware independently of runtime middleware.
     */
    public function middleware(): array
    {
        return [DeclaredModelMiddleware::class];
    }
}

class DeclaredModelMiddleware
{
    /**
     * Append the declared middleware marker to the model.
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        return $next($step->withModel($step->model . '-declared'));
    }
}

class RuntimeModelMiddleware
{
    /**
     * Append the runtime middleware marker to the model.
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        return $next($step->withModel($step->model . '-object'));
    }
}
