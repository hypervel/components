<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Generator;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Exceptions\ApprovalMismatchException;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Gateway\TextGenerationLoop;
use Hypervel\Ai\Middleware\RememberConversation;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Step;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Storage\DatabaseConversationStore;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

#[WithConfig('ai.conversations.generate_title', false)]
class RememberConversationTest extends TestCase
{
    use RefreshDatabase;

    public function testACompleteTurnUpdatesConversationActivityOnce(): void
    {
        config(['ai.conversations.generate_title' => true, 'ai.conversations.concurrent_title' => true]);
        $store = new DatabaseConversationStore;
        $conversationId = $store->storeConversation(null, null, 'Existing');
        $agent = new class extends RememberingAssistantAgent {
            public int $continuations = 0;

            /**
             * Count explicit changes to the agent's conversation.
             */
            public function continue(string $conversationId, ?object $as = null): static
            {
                ++$this->continuations;

                return parent::continue($conversationId, $as);
            }
        };
        $agent->continue($conversationId);
        $agent->continuations = 0;
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('test');
        $prompt = new AgentPrompt($agent, 'Hello', [], $provider, 'model');
        $middleware = m::mock(RememberConversation::class, [$store, $provider])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldNotReceive('generateTitle');
        DB::enableQueryLog();

        $response = $middleware->handle($prompt, fn (): AgentResponse => $this->response());

        $updates = array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'update "agent_conversations"'));
        $this->assertCount(1, $updates);
        $this->assertSame(0, $agent->continuations);
        $this->assertSame($conversationId, $response->conversationId);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $response->userMessageId, 'role' => 'user']);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $response->assistantMessageId, 'role' => 'assistant']);
    }

    public function testFailedAtomicPersistenceDoesNotLeaveHalfATurnOrChangeTheAgent(): void
    {
        $failure = new RuntimeException('Cannot store assistant');
        $store = m::mock(DatabaseConversationStore::class, [null])->makePartial();
        $store->shouldReceive('storeAssistantMessage')->once()->andThrow($failure);
        $prompt = $this->prompt();

        $this->assertThrowsSame($failure, fn () => (new RememberConversation($store, $prompt->provider()))->handle($prompt, fn (): AgentResponse => $this->response()));

        $this->assertNull($prompt->agent->currentConversation());
        $this->assertDatabaseCount('agent_conversations', 0);
        $this->assertDatabaseCount('agent_conversation_messages', 0);
    }

    public function testEachFailedIterationRecordsItsWorkEvenWhenTheFactoryThrows(): void
    {
        $prompt = $this->prompt();
        $store = new DatabaseConversationStore;
        $attempt = 0;
        $failure = new RuntimeException('Provider failed');
        $stream = new StreamableAgentResponse('invocation', function () use ($prompt, &$attempt, $failure): array {
            $prompt->setRunContext($context = $this->context($prompt));

            if (++$attempt > 1) {
                $context->recordStep($this->step());
            }

            throw $failure;
        });
        $stream = (new RememberConversation($store, $prompt->provider()))->handle($prompt, fn (): StreamableAgentResponse => $stream);
        $publicFailures = 0;
        $stream->catch(function () use (&$publicFailures): void {
            ++$publicFailures;
        });

        $this->assertThrowsSame($failure, fn (): array => iterator_to_array($stream));
        $this->assertDatabaseCount('agent_conversations', 0);
        $this->assertThrowsSame($failure, fn (): array => iterator_to_array($stream));

        $this->assertSame(1, $publicFailures);
        $this->assertDatabaseCount('agent_conversations', 1);
        $this->assertDatabaseCount('agent_conversation_messages', 2);
        $row = DB::table('agent_conversation_messages')->where('role', 'assistant')->first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('Sent', json_decode($row->steps, true)[0]['tool_calls'][0]['result']);
        $this->assertNull($prompt->runContext());
    }

    public function testSuccessfulRetryUsesTheConversationIdReturnedByTheStore(): void
    {
        $store = new class extends DatabaseConversationStore {
            /**
             * Assign the store's own conversation ID.
             */
            public function storeConversation(?string $participantType, string|int|null $participantId, string $title, ?string $id = null): string
            {
                return parent::storeConversation($participantType, $participantId, $title, (string) Str::uuid7());
            }
        };
        $prompt = $this->prompt();
        $attempt = 0;
        $failure = new RuntimeException('Provider failed');
        $stream = new StreamableAgentResponse('invocation', function () use ($prompt, &$attempt, $failure): Generator {
            $prompt->setRunContext($context = $this->context($prompt));

            if (++$attempt === 1) {
                $context->recordStep($this->step());
                throw $failure;
            }

            yield new TextDelta('event', 'message', 'Done', 1);
        });
        $stream = (new RememberConversation($store, $prompt->provider()))->handle($prompt, fn (): StreamableAgentResponse => $stream);
        $suggestedId = $stream->conversationId;

        $this->assertThrowsSame($failure, fn (): array => iterator_to_array($stream));
        $storedId = $prompt->agent->currentConversation();
        $this->assertNotSame($suggestedId, $storedId);
        $this->assertSame($storedId, $stream->conversationId);

        iterator_to_array($stream);

        $this->assertSame($storedId, $stream->conversationId);
        $this->assertDatabaseCount('agent_conversations', 1);
        $this->assertDatabaseCount('agent_conversation_messages', 4);
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $stream->assistantMessageId, 'content' => 'Done', 'status' => 'completed']);
    }

    public function testAClaimedFailureAfterAnUnclaimedAttemptSettlesTheOriginalPause(): void
    {
        $store = new DatabaseConversationStore;
        $prompt = $this->prompt();
        $conversationId = $store->storeConversation(null, null, 'Approval');
        $paused = $this->response()->withSteps(collect([$this->step(answered: false)]))
            ->withPendingApprovals(collect([new PendingApproval('call', 'send_email', [], 'Sends email')]));
        $messageId = $store->storeAssistantMessage($conversationId, null, null, $prompt, $paused);
        $prompt = $this->prompt(Decisions::from(['call' => true]));
        $prompt->agent->continue($conversationId);
        $attempt = 0;
        $failure = new RuntimeException('Provider failed');
        $stream = new StreamableAgentResponse('invocation', function () use ($prompt, $store, $conversationId, &$attempt, $failure): array {
            $prompt->setRunContext($context = $this->context($prompt, $store, $conversationId));

            if (++$attempt > 1) {
                $context->claimPendingApprovals(['call']);
                $context->recordApprovalResult(new ToolResult('call', 'send_email', [], 'Sent'));
            }

            throw $failure;
        });
        $stream = (new RememberConversation($store, $prompt->provider()))->handle($prompt, fn (): StreamableAgentResponse => $stream);

        $this->assertThrowsSame($failure, fn (): array => iterator_to_array($stream));
        $this->assertDatabaseHas('agent_conversation_messages', ['id' => $messageId, 'status' => 'paused', 'approval_claim' => null]);
        $this->assertThrowsSame($failure, fn (): array => iterator_to_array($stream));

        $this->assertDatabaseCount('agent_conversation_messages', 1);
        $row = DB::table('agent_conversation_messages')->find($messageId);
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->approval_claim);
        $this->assertSame('Sent', json_decode($row->steps, true)[0]['tool_calls'][0]['result']);
    }

    #[DataProvider('titleModes')]
    public function testInvalidApprovalDecisionsWithoutAConversationWriteNothing(bool $concurrent): void
    {
        config(['ai.conversations.generate_title' => true, 'ai.conversations.concurrent_title' => $concurrent]);
        $prompt = $this->prompt(Decisions::from(['call' => true]));
        $loop = new TextGenerationLoop(m::mock(StepTextGateway::class));
        $titleRequests = 0;
        $middleware = m::mock(RememberConversation::class, [new DatabaseConversationStore, $prompt->provider()])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('generateTitle')->andReturnUsing(function () use (&$titleRequests): string {
            ++$titleRequests;

            return 'Unexpected title';
        });

        $this->assertThrows(fn () => $middleware->handle($prompt, function () use ($prompt, $loop): never {
            $prompt->setRunContext($context = $this->context($prompt));
            $loop->generate($prompt->provider(), 'model', '', approval: $prompt->approvalDecisions->all(), context: $context);
        }), ApprovalMismatchException::class);

        $this->assertSame(0, $titleRequests);
        $this->assertDatabaseCount('agent_conversations', 0);
        $this->assertDatabaseCount('agent_conversation_messages', 0);
        $this->assertNull($prompt->agent->currentConversation());
    }

    /**
     * Provide both title scheduling modes.
     */
    public static function titleModes(): array
    {
        return ['serial' => [false], 'concurrent' => [true]];
    }

    public function testASequentialStoreCanChooseItsOwnIdWhenContinuingAnEmptyId(): void
    {
        $store = m::mock(ConversationStore::class);
        $store->shouldReceive('storeConversation')->once()->andReturn('custom-conversation');
        $store->shouldReceive('storeUserMessage')->once()->with('custom-conversation', null, null, RememberingAssistantAgent::class, m::type('object'))->andReturn('user-message');
        $store->shouldReceive('storeAssistantMessage')->once()->with('custom-conversation', null, null, m::type(AgentPrompt::class), m::type(AgentResponse::class), null)->andReturn('assistant-message');
        $prompt = $this->prompt();
        $prompt->agent->continue('');

        $response = (new RememberConversation($store, $prompt->provider()))->handle($prompt, fn (): AgentResponse => $this->response());

        $this->assertSame('custom-conversation', $prompt->agent->currentConversation());
        $this->assertSame('custom-conversation', $response->conversationId);
        $this->assertSame('user-message', $response->userMessageId);
        $this->assertSame('assistant-message', $response->assistantMessageId);
    }

    #[DataProvider('executionModes')]
    public function testConcurrentTitlesOverlapInCapturedContextAndAreGeneratedOnce(bool $streaming): void
    {
        config(['ai.conversations.generate_title' => true, 'ai.conversations.concurrent_title' => true]);
        $prompt = $this->prompt();
        $waiting = new Channel(1);
        $active = false;
        $titleAccount = null;
        CoroutineContext::set('title-test.account', 'consumer');
        $prompt->setContextRunner(static function (Closure $work): mixed {
            $previous = CoroutineContext::get('title-test.account');
            CoroutineContext::set('title-test.account', 'captured');

            try {
                return $work();
            } finally {
                CoroutineContext::set('title-test.account', $previous);
            }
        });
        $middleware = m::mock(RememberConversation::class, [new DatabaseConversationStore, $prompt->provider()])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('generateTitle')->once()->andReturnUsing(function () use ($waiting, &$active, &$titleAccount): string {
            $titleAccount = CoroutineContext::get('title-test.account');
            $active = true;

            try {
                $this->assertTrue($waiting->pop(1));

                return 'Generated title';
            } finally {
                $active = false;
            }
        });
        $generate = function () use ($waiting, &$active): AgentResponse {
            $this->assertTrue($active);
            $waiting->push(true);

            return $this->response();
        };

        try {
            if ($streaming) {
                $prompt->markAsStreaming();
                $stream = new StreamableAgentResponse('invocation', function () use ($generate): Generator {
                    yield new TextDelta('event', 'message', $generate()->text, 1);
                });
                $response = $middleware->handle($prompt, fn (): StreamableAgentResponse => $stream);
                $this->assertFalse($active);
                iterator_to_array($response);
            } else {
                $response = $middleware->handle($prompt, $generate);
            }

            $this->assertFalse($active);
            $this->assertSame('captured', $titleAccount);
            $this->assertSame('consumer', CoroutineContext::get('title-test.account'));
            $this->assertNull($prompt->pendingConversationTitle());
            $this->assertDatabaseHas('agent_conversations', ['id' => $response->conversationId, 'title' => 'Generated title']);
        } finally {
            $waiting->close();
        }
    }

    #[DataProvider('executionModes')]
    public function testUnrecordableFailuresCancelAndJoinAnUnfinishedTitle(bool $streaming): void
    {
        config(['ai.conversations.generate_title' => true, 'ai.conversations.concurrent_title' => true]);
        $prompt = $this->prompt();
        $waiting = new Channel(1);
        $active = false;
        $resumed = false;
        $failure = new RuntimeException('Provider failed before any work');
        $middleware = m::mock(RememberConversation::class, [new DatabaseConversationStore, $prompt->provider()])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('generateTitle')->once()->andReturnUsing(function () use ($waiting, &$active, &$resumed): string {
            $active = true;

            try {
                $waiting->pop(1);
                $resumed = true;

                return 'Unused title';
            } finally {
                $active = false;
            }
        });
        $generate = function () use (&$active, $failure): never {
            $this->assertTrue($active);

            throw $failure;
        };

        try {
            if ($streaming) {
                $prompt->markAsStreaming();
                $stream = new StreamableAgentResponse('invocation', $generate);
                $response = $middleware->handle($prompt, fn (): StreamableAgentResponse => $stream);
                $this->assertThrowsSame($failure, fn (): array => iterator_to_array($response));
            } else {
                $this->assertThrowsSame($failure, fn () => $middleware->handle($prompt, $generate));
            }

            $this->assertFalse($active);
            $this->assertFalse($resumed);
            $this->assertNull($prompt->pendingConversationTitle());
            $this->assertDatabaseCount('agent_conversations', 0);
        } finally {
            $waiting->close();
        }
    }

    #[DataProvider('executionModes')]
    public function testRecordableFailuresUseTheConcurrentTitleOnce(bool $streaming): void
    {
        config(['ai.conversations.generate_title' => true, 'ai.conversations.concurrent_title' => true]);
        $prompt = $this->prompt();
        $failure = new RuntimeException('Provider failed after a tool');
        $middleware = m::mock(RememberConversation::class, [new DatabaseConversationStore, $prompt->provider()])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('generateTitle')->once()->andReturn('Failed conversation');
        $generate = function () use ($prompt, $failure): never {
            $prompt->setRunContext($context = $this->context($prompt));
            $context->recordStep($this->step());

            throw $failure;
        };

        if ($streaming) {
            $prompt->markAsStreaming();
            $stream = new StreamableAgentResponse('invocation', $generate);
            $response = $middleware->handle($prompt, fn (): StreamableAgentResponse => $stream);
            $this->assertThrowsSame($failure, fn (): array => iterator_to_array($response));
        } else {
            $this->assertThrowsSame($failure, fn () => $middleware->handle($prompt, $generate));
        }

        $this->assertNull($prompt->pendingConversationTitle());
        $this->assertDatabaseHas('agent_conversations', ['title' => 'Failed conversation']);
        $this->assertDatabaseHas('agent_conversation_messages', ['role' => 'assistant', 'status' => 'failed']);
    }

    /**
     * Provide synchronous and streamed execution.
     */
    public static function executionModes(): array
    {
        return ['prompt' => [false], 'stream' => [true]];
    }

    public function testUnconsumedStreamsDoNotStartTitlesAndAbandonmentJoinsThem(): void
    {
        config(['ai.conversations.generate_title' => true, 'ai.conversations.concurrent_title' => true]);
        $prompt = $this->prompt();
        $prompt->markAsStreaming();
        $waiting = new Channel(1);
        $active = false;
        $started = false;
        $middleware = m::mock(RememberConversation::class, [new DatabaseConversationStore, $prompt->provider()])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('generateTitle')->once()->andReturnUsing(function () use ($waiting, &$active, &$started): string {
            $active = $started = true;

            try {
                $waiting->pop(1);

                return 'Unused title';
            } finally {
                $active = false;
            }
        });
        $stream = new StreamableAgentResponse('invocation', function (): Generator {
            yield new TextDelta('event', 'message', 'First token', 1);
        });
        $response = $middleware->handle($prompt, fn (): StreamableAgentResponse => $stream);

        try {
            $this->assertFalse($started);

            foreach ($response as $event) {
                $this->assertTrue($active);
                $this->assertSame('First token', $event->delta);
                break;
            }

            $this->assertTrue($started);
            $this->assertFalse($active);
            $this->assertNull($prompt->pendingConversationTitle());
            $this->assertDatabaseCount('agent_conversations', 0);
        } finally {
            $waiting->close();
        }
    }

    public function testAgentFakesKeepSerialTitleOrderingWhenConcurrencyIsEnabled(): void
    {
        config(['ai.conversations.generate_title' => true, 'ai.conversations.concurrent_title' => true]);
        $prompt = $this->prompt();
        Ai::fakeAgent($prompt->agent::class);
        $generated = false;
        $middleware = m::mock(RememberConversation::class, [new DatabaseConversationStore, $prompt->provider()])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('generateTitle')->once()->andReturnUsing(function () use (&$generated): string {
            $this->assertTrue($generated);

            return 'Fake conversation';
        });

        $middleware->handle($prompt, function () use (&$generated): AgentResponse {
            $generated = true;

            return $this->response();
        });

        $this->assertDatabaseHas('agent_conversations', ['title' => 'Fake conversation']);
    }

    public function testCancelingTheStreamConsumerJoinsItsTitleRequest(): void
    {
        config(['ai.conversations.generate_title' => true, 'ai.conversations.concurrent_title' => true]);
        $prompt = $this->prompt();
        $prompt->markAsStreaming();
        $waiting = new Channel(1);
        $active = false;
        $caught = null;
        $middleware = m::mock(RememberConversation::class, [new DatabaseConversationStore, $prompt->provider()])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $middleware->shouldReceive('generateTitle')->once()->andReturnUsing(function () use ($waiting, &$active): string {
            $active = true;

            try {
                $waiting->pop(1);

                return 'Unused title';
            } finally {
                $active = false;
            }
        });
        $stream = new StreamableAgentResponse('invocation', function () use ($waiting): Generator {
            $waiting->pop(1);

            yield new TextDelta('event', 'message', 'Unexpected completion', 1);
        });
        $response = $middleware->handle($prompt, fn (): StreamableAgentResponse => $stream);
        $consumer = EngineCoroutine::create(function () use ($response, &$caught): void {
            try {
                iterator_to_array($response);
            } catch (CanceledException $exception) {
                $caught = $exception;
            }
        });

        try {
            $this->assertTrue($active);
            EngineCoroutine::cancelById($consumer->getId(), throwException: true);
            Coroutine::join([$consumer->getId()], 1);

            $this->assertInstanceOf(CanceledException::class, $caught);
            $this->assertFalse($active);
            $this->assertNull($prompt->pendingConversationTitle());
        } finally {
            $waiting->close();

            if (Coroutine::exists($consumer->getId())) {
                EngineCoroutine::cancelById($consumer->getId(), throwException: true);
                Coroutine::join([$consumer->getId()], 1);
            }
        }
    }

    public function testTitleCancellationIsNotConvertedIntoAFallbackTitle(): void
    {
        config(['ai.conversations.generate_title' => true]);
        $prompt = $this->prompt();
        $failure = new CanceledException('Title request canceled');
        $loop = m::mock(TextGenerationLoop::class);
        $loop->shouldReceive('generate')->once()->andThrow($failure);
        $prompt->provider()->shouldReceive('textGenerationLoop')->once()->andReturn($loop);
        $prompt->provider()->shouldReceive('cheapestTextModel')->once()->andReturn('title-model');

        $this->assertThrowsSame($failure, fn () => (new RememberConversation(new DatabaseConversationStore, $prompt->provider()))
            ->handle($prompt, fn (): AgentResponse => $this->response()));

        $this->assertDatabaseCount('agent_conversations', 0);
        $this->assertNull($prompt->agent->currentConversation());
    }

    /**
     * Create a prompt without making provider requests.
     */
    private function prompt(?Decisions $decisions = null): AgentPrompt
    {
        $provider = m::mock(TextProvider::class);
        $provider->shouldReceive('name')->andReturn('test');

        return new AgentPrompt((new RememberingAssistantAgent)->forUser((object) ['id' => 1]), 'Hello', [], $provider, 'model', approvalDecisions: $decisions);
    }

    /**
     * Create an invocation recorder with optional persisted approvals.
     */
    private function context(AgentPrompt $prompt, ?DatabaseConversationStore $store = null, ?string $conversationId = null): RunContext
    {
        return new RunContext('invocation', $prompt->agent, $prompt->provider(), 'model', $this->app->make(Dispatcher::class), approvalStore: $store, conversationId: $conversationId);
    }

    /**
     * Create a step with a side-effecting tool call.
     */
    private function step(bool $answered = true): Step
    {
        return new Step('', [new ToolCall('call', 'send_email', [])], $answered ? [new ToolResult('call', 'send_email', [], 'Sent')] : [], FinishReason::ToolCalls, new TextUsage, new Meta('test', 'model'), '', []);
    }

    /**
     * Create a completed response.
     */
    private function response(): AgentResponse
    {
        return new AgentResponse('invocation', 'Hello back', new TextUsage, new Meta('test', 'model'));
    }

    /**
     * Require the original failure to pass through persistence unchanged.
     */
    private function assertThrowsSame(Throwable $expected, Closure $action): void
    {
        try {
            $action();
        } catch (Throwable $actual) {
            $this->assertSame($expected, $actual, $actual::class . ': ' . $actual->getMessage());

            return;
        }

        $this->fail('The operation did not throw.');
    }
}
