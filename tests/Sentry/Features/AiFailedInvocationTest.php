<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Events\AgentFailed;
use Hypervel\Ai\Events\AgentStreamAbandoned;
use Hypervel\Ai\Events\StreamingAgent;
use Hypervel\Ai\Gateway\ParentInvocation;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\RequestException;
use Hypervel\Sentry\Features\Ai\AiTracingScope;
use Hypervel\Sentry\Integration;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\FakeConversationStore;
use Hypervel\Tests\Sentry\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Tests\Ai\Fixtures\fakeGroqStreamResponse;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class AiFailedInvocationTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.tracing.http_client_requests' => false,
    ];

    /**
     * Load AI alongside the Sentry integration.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    /**
     * Configure a provider that never reaches an external service.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set('ai.providers.fixture', [
            'driver' => 'openai-compatible',
            'url' => 'https://model.test/v1',
            'key' => 'fixture-key',
            'models' => ['text' => ['default' => 'test-model']],
        ]);
    }

    /**
     * Reject requests not handled by a test fake.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function testFailureClosesTheInvocationAndRestoresTheParentForTheNextRequest(): void
    {
        Http::fakeSequence()
            ->push($this->answer(), 200)
            ->push(['error' => ['message' => 'upstream failed']], 500)
            ->push($this->answer(), 200);

        $failure = null;
        $this->app->make('events')->listen(AgentFailed::class, function (AgentFailed $event) use (&$failure): void {
            $failure = $event;
        });
        $transaction = $this->startTransaction();
        $hub = SentrySdk::getCurrentHub();
        $agent = $this->agent();

        $agent->prompt('first', [], 'fixture', 'test-model');
        $this->assertSame($transaction, $hub->getSpan());

        try {
            $agent->prompt('second', [], 'fixture', 'test-model');
            $this->fail('Expected the upstream exception.');
        } catch (RequestException $exception) {
            $this->assertSame(500, $exception->response->status());
        }

        $this->assertSame($transaction, $hub->getSpan());
        $invocations = $this->spans($transaction, 'gen_ai.invoke_agent');
        $this->assertCount(2, $invocations);
        $this->assertEquals(SpanStatus::internalError(), $invocations[1]->getStatus());
        $this->assertNotNull($invocations[1]->getEndTimestamp());

        $this->assertInstanceOf(AgentFailed::class, $failure);
        $beforeRepeat = count($transaction->getSpanRecorder()->getSpans());
        $this->dispatchHypervelEvent($failure);
        $this->assertCount($beforeRepeat, $transaction->getSpanRecorder()->getSpans());
        $this->assertSame($transaction, $hub->getSpan());

        $agent->prompt('third', [], 'fixture', 'test-model');
        $this->assertSame($transaction, $hub->getSpan());
        $this->assertCount(3, $this->spans($transaction, 'gen_ai.chat'));
        foreach ($this->spans($transaction, 'gen_ai.invoke_agent') as $span) {
            $this->assertNotNull($span->getEndTimestamp());
            $this->assertEquals($transaction->getSpanId(), $span->getParentSpanId());
        }
        Http::assertSentCount(3);
    }

    public function testAbandoningNestedAgentToolsClosesTheirSpansInOrder(): void
    {
        config(['ai.conversations.generate_title' => false]);
        $this->app->instance(ConversationStore::class, new FakeConversationStore);
        $outer = new SentryOuterRememberingAgent;
        SentryOuterRememberingAgent::fake([new ToolCall('middle-call', 'SentryMiddleRememberingAgent', ['task' => 'middle'])]);
        SentryMiddleRememberingAgent::fake([new ToolCall('inner-call', 'SentryInnerRememberingAgent', ['task' => 'inner'])]);
        SentryInnerRememberingAgent::fake(['Inner answer']);
        $started = [];
        $abandoned = [];
        $events = $this->app->make('events');
        $events->listen(StreamingAgent::class, function (StreamingAgent $event) use (&$started): void {
            $started[] = $event->invocationId;
        });
        $events->listen(AgentStreamAbandoned::class, function (AgentStreamAbandoned $event) use (&$abandoned): void {
            $abandoned[] = $event->invocationId;
        });
        $transaction = $this->startTransaction();

        foreach ($outer->continue('outer')->stream('Start', provider: 'fixture', model: 'test-model') as $event) {
            if (count($started) === 3) {
                break;
            }
        }

        $this->assertCount(3, $started);
        $this->assertSame(array_reverse($started), $abandoned);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
        $this->assertSame('outer', AiTracingScope::current()->conversation->conversationId);
        foreach ($this->spans($transaction, 'gen_ai.invoke_agent') as $span) {
            $this->assertNotNull($span->getEndTimestamp());
        }
        foreach ($this->spans($transaction, 'gen_ai.execute_tool') as $span) {
            $this->assertNotNull($span->getEndTimestamp());
        }
    }

    #[DataProvider('nestedInvocations')]
    public function testFailoverPreservesEarlierAttemptIdsAndTheCallingConversation(bool $nested): void
    {
        config(['ai.conversations.generate_title' => false]);
        $this->app->instance(ConversationStore::class, new FakeConversationStore);
        Http::fakeSequence()
            ->push(['error' => ['message' => 'Rate limited']], 429)
            ->push($this->answer());
        $transaction = $this->startTransaction();
        Integration::setConversationId('caller');
        $caller = AiTracingScope::current()->conversation;
        $agent = (new SentryInnerRememberingAgent)->forParticipant((object) ['id' => 1]);
        $response = ParentInvocation::within($nested ? 'parent' : null, 'tool', fn () => $agent->prompt(
            'Start',
            provider: ['fixture', 'fixture'],
            model: 'test-model',
        ));

        $this->assertNotNull($response->conversationId);
        $attempts = $this->spans($transaction, 'gen_ai.invoke_agent');
        $this->assertCount(2, $attempts);
        foreach ([...$attempts, ...$this->spans($transaction, 'gen_ai.chat')] as $span) {
            $this->assertSame($response->conversationId, $span->getData()['gen_ai.conversation.id']);
            $this->assertNotNull($span->getEndTimestamp());
        }
        $this->assertEquals(SpanStatus::resourceExhausted(), $attempts[0]->getStatus());
        $this->assertEquals(SpanStatus::ok(), $attempts[1]->getStatus());
        $this->assertSame('caller', $caller->conversationId);
        $this->assertSame($nested ? 'caller' : $response->conversationId, AiTracingScope::current()->conversation->conversationId);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
        Http::assertSentCount(2);
    }

    /**
     * Cover top-level and tool-delegated invocation ownership.
     */
    public static function nestedInvocations(): array
    {
        return ['top-level' => [false], 'nested' => [true]];
    }

    public function testStreamingSpansRemainOpenUntilConsumptionFinishes(): void
    {
        config(['ai.providers.stream' => ['driver' => 'groq', 'key' => 'test-key']]);
        Http::fakeSequence()->pushResponse(fakeGroqStreamResponse());
        $transaction = $this->startTransaction();
        $stream = $this->agent()->stream('Start', provider: 'stream', model: 'test-model');
        $this->assertSame([], $this->spans($transaction, 'gen_ai.invoke_agent'));

        $iterator = $stream->getIterator();
        $iterator->rewind();
        $chat = $this->spans($transaction, 'gen_ai.chat')[0];
        $this->assertNull($chat->getEndTimestamp());
        $this->assertSame($chat, SentrySdk::getCurrentHub()->getSpan());
        iterator_to_array($iterator);

        $this->assertNotNull($chat->getEndTimestamp());
        $this->assertEquals(SpanStatus::ok(), $chat->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testFailedSseWritesCloseTheInvocation(): void
    {
        $agent = $this->agent();
        $agent::fake(['Hello']);
        $transaction = $this->startTransaction();
        $response = $agent->stream('Start', provider: 'fixture', model: 'test-model')->toResponse(request());
        $response->streamTo(fn (string $chunk): bool => false);

        $agentSpan = $this->spans($transaction, 'gen_ai.invoke_agent')[0];
        $this->assertNotNull($agentSpan->getEndTimestamp());
        $this->assertEquals(SpanStatus::internalError(), $agentSpan->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testPersistenceFailureClosesTheInvocationAfterItsModelStep(): void
    {
        config(['ai.conversations.generate_title' => false]);
        SentryInnerRememberingAgent::fake(['Hello']);
        $failure = new RuntimeException('Storage failed.');
        $store = m::mock(FakeConversationStore::class)->makePartial();
        $store->shouldReceive('storeConversation')->once()->andThrow($failure);
        $this->app->instance(ConversationStore::class, $store);
        $transaction = $this->startTransaction();

        try {
            iterator_to_array((new SentryInnerRememberingAgent)->forParticipant((object) ['id' => 1])->stream('Start'));
            $this->fail('Persistence must fail.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $agentSpan = $this->spans($transaction, 'gen_ai.invoke_agent')[0];
        $this->assertNotNull($agentSpan->getEndTimestamp());
        $this->assertEquals(SpanStatus::internalError(), $agentSpan->getStatus());
        $this->assertEquals(SpanStatus::ok(), $this->spans($transaction, 'gen_ai.chat')[0]->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testEmbeddingFailureFinishesItsSpanAndRestoresTheParent(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Invalid key']], 401)]);
        $transaction = $this->startTransaction();

        try {
            Embeddings::for(['Text'])->generate(provider: 'openai');
            $this->fail('Embedding generation must fail.');
        } catch (RequestException $exception) {
            $this->assertSame(401, $exception->response->status());
        }

        $span = $this->spans($transaction, 'gen_ai.embeddings')[0];
        $this->assertNotNull($span->getEndTimestamp());
        $this->assertEquals(SpanStatus::unauthenticated(), $span->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }

    public function testNestedToolCancellationClosesSpansAndRestoresTheCaller(): void
    {
        $this->app->instance(ConversationStore::class, new FakeConversationStore);
        SentryMiddleRememberingAgent::fake([new ToolCall('inner-call', 'SentryInnerRememberingAgent', ['task' => 'inner'])]);
        $failure = new CanceledException('Canceled');
        SentryInnerRememberingAgent::fake([fn (): never => throw $failure]);
        $transaction = $this->startTransaction();

        try {
            iterator_to_array((new SentryMiddleRememberingAgent)->stream('Start'));
            $this->fail('Cancellation must escape.');
        } catch (CanceledException $caught) {
            $this->assertSame($failure, $caught);
        }

        $spans = [...$this->spans($transaction, 'gen_ai.invoke_agent'), ...$this->spans($transaction, 'gen_ai.execute_tool')];
        $this->assertCount(3, $spans);
        foreach ($spans as $span) {
            $this->assertNotNull($span->getEndTimestamp());
            $this->assertEquals(SpanStatus::internalError(), $span->getStatus());
        }
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
        $this->assertNull(AiTracingScope::current()->conversation);
    }

    /**
     * Create a one-step agent.
     */
    private function agent(): Agent
    {
        return new class implements Agent {
            use Promptable;

            /**
             * Return the instructions.
             */
            public function instructions(): string
            {
                return '';
            }

            /**
             * Limit the response to one model step.
             */
            public function maxSteps(): int
            {
                return 1;
            }
        };
    }

    /**
     * Return a compatible model response.
     */
    private function answer(): array
    {
        return ['model' => 'test-model', 'choices' => [[
            'message' => ['role' => 'assistant', 'content' => 'ok'],
            'finish_reason' => 'stop',
        ]], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 5]];
    }

    /**
     * Return recorded spans for an operation.
     *
     * @return list<Span>
     */
    private function spans(Transaction $transaction, string $operation): array
    {
        return array_values(array_filter($transaction->getSpanRecorder()->getSpans(), function (Span $span) use ($operation): bool {
            return $span->getOp() === $operation;
        }));
    }
}

class SentryInnerRememberingAgent implements Agent
{
    use Promptable;
    use RemembersConversations;

    /**
     * Return the instructions.
     */
    public function instructions(): string
    {
        return 'Answer the request.';
    }

    /**
     * Use the local provider fixture for delegated calls.
     */
    public function provider(): string
    {
        return 'fixture';
    }

    /**
     * Return the fixture model.
     */
    public function model(): string
    {
        return 'test-model';
    }
}

class SentryMiddleRememberingAgent extends SentryInnerRememberingAgent implements HasTools
{
    /**
     * Delegate to a remembering inner agent.
     */
    public function tools(): iterable
    {
        return [(new SentryInnerRememberingAgent)->continue('inner')];
    }
}

class SentryOuterRememberingAgent extends SentryMiddleRememberingAgent
{
    /**
     * Delegate to a remembering middle agent.
     */
    public function tools(): iterable
    {
        return [(new SentryMiddleRememberingAgent)->continue('middle')];
    }
}
