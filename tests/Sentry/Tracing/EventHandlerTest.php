<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Tracing;

use Error;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\Events\TransactionCommitted;
use Hypervel\Database\Events\TransactionRolledBack;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Http\Request;
use Hypervel\Routing\Events\PreparingResponse;
use Hypervel\Routing\Events\ResponsePrepared;
use Hypervel\Sentry\Tracing\EventHandler;
use Hypervel\Tests\Sentry\SentryTestCase;
use Mockery as m;
use PDO;
use ReflectionClass;
use RuntimeException;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Swoole\Coroutine\Channel;
use Symfony\Component\HttpFoundation\Response;

class EventHandlerTest extends SentryTestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
    ];

    public function testMissingEventHandlerThrowsException(): void
    {
        $this->expectException(RuntimeException::class);

        $handler = new EventHandler(config()->array('sentry.tracing'));

        /* @noinspection PhpUndefinedMethodInspection */
        $handler->thisIsNotAHandlerAndShouldThrowAnException();
    }

    public function testAllMappedEventHandlersExist(): void
    {
        $this->tryAllEventHandlerMethods(
            $this->getEventHandlerMapFromEventHandler()
        );
    }

    public function testTransactionsAndQueriesAreOwnedByTheirExactConnection(): void
    {
        $handler = new EventHandler(config()->array('sentry.tracing'));
        $transaction = $this->startTransaction();
        $firstConnection = $this->connection('first');
        $secondConnection = $this->connection('second');

        $handler->transactionBeginning(new TransactionBeginning($firstConnection));
        $first = $this->currentTransactionSpan($firstConnection);
        $handler->transactionBeginning(new TransactionBeginning($secondConnection));
        $second = $this->currentTransactionSpan($secondConnection);
        $handler->queryExecuted(new QueryExecuted('select first', [], 2.0, $firstConnection));
        $firstQuery = $this->lastRecordedSpan($transaction);
        $handler->transactionBeginning(new TransactionBeginning($firstConnection));
        $nestedFirst = $this->currentTransactionSpan($firstConnection);
        $handler->queryExecuted(new QueryExecuted('select nested', [], 2.0, $firstConnection));
        $nestedQuery = $this->lastRecordedSpan($transaction);
        $handler->transactionCommitted(new TransactionCommitted($firstConnection));
        $handler->queryExecuted(new QueryExecuted('select outer', [], 2.0, $firstConnection));
        $outerQuery = $this->lastRecordedSpan($transaction);
        $handler->transactionRolledBack(new TransactionRolledBack($secondConnection));
        $handler->transactionCommitted(new TransactionCommitted($firstConnection));

        $this->assertEquals($transaction->getSpanId(), $first->getParentSpanId());
        $this->assertEquals($transaction->getSpanId(), $second->getParentSpanId());
        $this->assertEquals($first->getSpanId(), $firstQuery->getParentSpanId());
        $this->assertEquals($first->getSpanId(), $nestedFirst->getParentSpanId());
        $this->assertEquals($nestedFirst->getSpanId(), $nestedQuery->getParentSpanId());
        $this->assertEquals($first->getSpanId(), $outerQuery->getParentSpanId());
        $this->assertSame(SpanStatus::ok(), $nestedFirst->getStatus());
        $this->assertSame(SpanStatus::ok(), $first->getStatus());
        $this->assertSame(SpanStatus::internalError(), $second->getStatus());
        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
        $this->assertSame([], CoroutineContext::get(EventHandler::CONTEXT_TRANSACTION_SPANS_KEY, []));
    }

    public function testResponseAndTransactionSpansUseIndependentOwnership(): void
    {
        $handler = new EventHandler(config()->array('sentry.tracing'));
        $transaction = $this->startTransaction();
        $request = Request::create('/response');
        $connection = $this->connection('response');

        $handler->responsePreparing(new PreparingResponse($request, 'payload'));
        $responseSpan = SentrySdk::getCurrentHub()->getSpan();
        $handler->transactionBeginning(new TransactionBeginning($connection));
        $databaseSpan = $this->currentTransactionSpan($connection);

        $this->assertSame($responseSpan, SentrySdk::getCurrentHub()->getSpan());
        $this->assertEquals($responseSpan->getSpanId(), $databaseSpan->getParentSpanId());

        $handler->responsePrepared(new ResponsePrepared($request, new Response));

        $this->assertSame($transaction, SentrySdk::getCurrentHub()->getSpan());
        $this->assertNotNull($responseSpan->getEndTimestamp());
        $this->assertNull($databaseSpan->getEndTimestamp());

        $handler->transactionCommitted(new TransactionCommitted($connection));

        $this->assertSame(SpanStatus::ok(), $databaseSpan->getStatus());
    }

    public function testNullQueryTimeCreatesAnInstantaneousSpanWithoutOriginResolution(): void
    {
        $tracingConfig = config()->array('sentry.tracing');
        $tracingConfig['sql_origin'] = true;
        $tracingConfig['sql_origin_threshold_ms'] = 0;
        $handler = new EventHandler($tracingConfig);
        $transaction = $this->startTransaction();

        $handler->queryExecuted(new QueryExecuted(
            'select without timing',
            [],
            null,
            $this->connection('untimed'),
        ));

        $span = $this->lastRecordedSpan($transaction);
        $this->assertSame($span->getStartTimestamp(), $span->getEndTimestamp());
        $this->assertArrayNotHasKey('code.filepath', $span->getData());
    }

    public function testThrowableFromInstrumentationDoesNotReachApplicationCode(): void
    {
        $this->startTransaction();
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('throwing');
        $connection->shouldReceive('getDatabaseName')->once()->andThrow(new Error('broken instrumentation'));
        $handler = new EventHandler(config()->array('sentry.tracing'));

        $handler->queryExecuted(new QueryExecuted('select 1', [], 1.0, $connection));

        $this->addToAssertionCount(1);
    }

    public function testCoroutineExitFinishesOnlyAbandonedResponseAndTransactionSpans(): void
    {
        $this->startTransaction();
        $result = new Channel(1);
        $observedRestoredSpan = null;

        $coroutineId = Coroutine::create(function () use (&$observedRestoredSpan, $result): void {
            $hub = SentrySdk::getCurrentHub();
            $root = $hub->getSpan();
            Coroutine::defer(static function () use (&$observedRestoredSpan): void {
                $observedRestoredSpan = SentrySdk::getCurrentHub()->getSpan();
            });
            $handler = new EventHandler(config()->array('sentry.tracing'));
            $connection = $this->connection('abandoned');
            $handler->responsePreparing(new PreparingResponse(Request::create('/abandoned'), 'payload'));
            $responseSpan = $hub->getSpan();
            $handler->transactionBeginning(new TransactionBeginning($connection));
            $transactionSpan = $this->currentTransactionSpan($connection);
            $result->push([$root, $responseSpan, $transactionSpan]);
        });

        [$root, $responseSpan, $transactionSpan] = $result->pop(1.0);
        Coroutine::join([$coroutineId], 1.0);

        $this->assertSame($root, $observedRestoredSpan);
        $this->assertSame(SpanStatus::internalError(), $responseSpan->getStatus());
        $this->assertSame(SpanStatus::internalError(), $transactionSpan->getStatus());
        $this->assertNotNull($responseSpan->getEndTimestamp());
        $this->assertNotNull($transactionSpan->getEndTimestamp());
    }

    private function tryAllEventHandlerMethods(array $methods): void
    {
        $handler = new EventHandler(config()->array('sentry.tracing'));

        $methods = array_map(static function ($method) {
            return "{$method}Handler";
        }, array_unique(array_values($methods)));

        foreach ($methods as $handlerMethod) {
            $this->assertTrue(method_exists($handler, $handlerMethod));
        }
    }

    private function getEventHandlerMapFromEventHandler(): array
    {
        $class = new ReflectionClass(EventHandler::class);

        $attributes = $class->getStaticProperties();

        return $attributes['eventHandlerMap'];
    }

    private function connection(string $name): Connection
    {
        return new SQLiteConnection(
            new PDO('sqlite::memory:'),
            'database',
            '',
            ['driver' => 'sqlite', 'name' => $name],
        );
    }

    private function currentTransactionSpan(Connection $connection): Span
    {
        $transactionSpans = CoroutineContext::get(EventHandler::CONTEXT_TRANSACTION_SPANS_KEY, []);

        return end($transactionSpans[spl_object_id($connection)]);
    }

    private function lastRecordedSpan(Span $transaction): Span
    {
        return last($transaction->getSpanRecorder()->getSpans());
    }
}
