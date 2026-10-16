<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Events\EmbeddingsGenerated;
use Hypervel\Ai\Events\GeneratingEmbeddings;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Sentry\Features\Ai\AiTracingScope;
use Hypervel\Sentry\Integration;
use Hypervel\Sentry\Tracing\EventHandler as TracingEventHandler;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingAssistantAgent;
use Hypervel\Tests\Ai\Fixtures\FakeConversationStore;
use Mockery as m;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Swoole\Coroutine\Channel;

use function Hypervel\Coroutine\parallel;

class CoroutineSafetyTest extends TestCase
{
    protected array $defaultSetupConfig = ['sentry.traces_sample_rate' => 1.0];

    /**
     * Load AI for tracing ownership checks.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    public function testIntegrationTransactionNameIsIsolatedPerCoroutine(): void
    {
        // Set transaction name in parent coroutine
        Integration::setTransaction('/parent-route');

        $childTransaction = null;
        $channel = new Channel(1);

        Coroutine::create(function () use (&$childTransaction, $channel) {
            // Child coroutine should NOT see parent's transaction name
            $childTransaction = Integration::getTransaction();

            // Set a different name in the child
            Integration::setTransaction('/child-route');

            $channel->push(true);
        });

        $this->assertTrue($channel->pop(1.0));

        // Parent should still have its own transaction name
        $this->assertSame('/parent-route', Integration::getTransaction());

        // Child should not have inherited parent's transaction name
        $this->assertNull($childTransaction);
    }

    public function testTracingEventHandlerSpanStacksAreIsolatedPerCoroutine(): void
    {
        $handler = new TracingEventHandler(config()->array('sentry.tracing'));

        // We need a transaction on the hub for span operations to work
        $hub = SentrySdk::getCurrentHub();
        $transaction = $hub->startTransaction(new TransactionContext('test'));
        $transaction->setSampled(true);
        $hub->setSpan($transaction);

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('parent');
        $handler->transactionBeginning(new TransactionBeginning($connection));

        // Verify parent has a span on its stack
        $parentStackKey = TracingEventHandler::CONTEXT_TRANSACTION_SPANS_KEY;
        $parentStack = CoroutineContext::get($parentStackKey, []);
        $this->assertCount(1, $parentStack);

        $childStackCount = null;
        $channel = new Channel(1);

        Coroutine::create(function () use ($parentStackKey, &$childStackCount, $channel) {
            // Child coroutine should have an empty span stack
            $childStackCount = count(CoroutineContext::get($parentStackKey, []));
            $channel->push(true);
        });

        $channel->pop(1.0);

        // Child should not see parent's span stack
        $this->assertSame(0, $childStackCount);

        // Parent's stack should be unaffected
        $this->assertCount(1, CoroutineContext::get($parentStackKey, []));
    }

    public function testConcurrentRememberingAgentsKeepTheirConversationSelections(): void
    {
        $this->app->instance(ConversationStore::class, new FakeConversationStore);
        RememberingAssistantAgent::fake(function (string $prompt): string {
            usleep(5000);

            return $prompt;
        });
        $transaction = $this->startTransaction();
        Integration::setConversationId('parent');
        $parentScope = AiTracingScope::current();

        $selections = parallel([
            function (): string {
                (new RememberingAssistantAgent)->continue('first')->prompt('First', provider: 'openai');

                return AiTracingScope::current()->conversation->conversationId;
            },
            function (): string {
                (new RememberingAssistantAgent)->continue('second')->prompt('Second', provider: 'openai');

                return AiTracingScope::current()->conversation->conversationId;
            },
        ]);

        $this->assertSame(['first', 'second'], $selections);
        $this->assertSame('parent', $parentScope->conversation->conversationId);
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
        $conversationIds = [];
        foreach ($transaction->getSpanRecorder()->getSpans() as $span) {
            if ($span->getOp() === 'gen_ai.invoke_agent') {
                $conversationIds[] = $span->getData()['gen_ai.conversation.id'];
                $this->assertEquals($transaction->getSpanId(), $span->getParentSpanId());
                $this->assertNotNull($span->getEndTimestamp());
            }
        }
        $this->assertEqualsCanonicalizing(['first', 'second'], $conversationIds);
    }

    public function testChildEvictionRestoresTheCapturedParentScopeWithoutReplacingItsOwnSpan(): void
    {
        $transaction = $this->startTransaction();
        $provider = $this->app->make(AiManager::class)->embeddingProvider('openai');
        $prompt = new EmbeddingsPrompt(['text'], 1, $provider, 'embedding-model');
        $response = new EmbeddingsResponse([[0.5]], new Usage(1), new Meta('openai', 'embedding-model'));
        $this->dispatchHypervelEvent(new GeneratingEmbeddings('parent', $provider, $prompt->model, $prompt));
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();
        $parentScope = AiTracingScope::sentryScope();
        $observed = [];

        $child = Coroutine::create(function () use ($provider, $prompt, $response, $parentScope, &$observed): void {
            for ($index = 0; $index < 100; ++$index) {
                $this->dispatchHypervelEvent(new GeneratingEmbeddings('child-' . $index, $provider, $prompt->model, $prompt));
            }
            $observed = [SentrySdk::getCurrentHub()->getSpan(), $parentScope->getSpan()];

            for ($index = 99; $index >= 0; --$index) {
                $this->dispatchHypervelEvent(new EmbeddingsGenerated('child-' . $index, $provider, $prompt->model, $prompt, $response));
            }
        });
        Coroutine::join([$child]);

        $this->assertNotSame($transaction, $observed[0]);
        $this->assertNotSame($parentSpan, $observed[0]);
        $this->assertSame($transaction, $observed[1]);
        $this->assertEquals(SpanStatus::deadlineExceeded(), $parentSpan->getStatus());
        $finishedAt = $parentSpan->getEndTimestamp();
        $this->assertNotNull($finishedAt);
        $this->dispatchHypervelEvent(new EmbeddingsGenerated('parent', $provider, $prompt->model, $prompt, $response));
        $this->assertSame($finishedAt, $parentSpan->getEndTimestamp());
        $this->assertEquals(SpanStatus::deadlineExceeded(), $parentSpan->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
    }
}
