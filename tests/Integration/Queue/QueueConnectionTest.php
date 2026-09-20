<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\QueueConnectionTest;

use Aws\MockHandler;
use Aws\Result;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Bus;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;

#[WithConfig('queue.default', 'sqs')]
#[WithConfig('queue.connections.sqs.after_commit', true)]
class QueueConnectionTest extends TestCase
{
    protected MockHandler $sqs;

    /**
     * Configure SQS with an in-memory response handler.
     */
    protected function defineEnvironment(Application $app): void
    {
        $this->sqs = new MockHandler([new Result(['MessageId' => 'test-job'])]);

        $app->make('config')->set([
            'queue.connections.sqs.region' => 'us-east-1',
            'queue.connections.sqs.prefix' => 'https://sqs.us-east-1.amazonaws.com/123456789012',
            'queue.connections.sqs.queue' => 'default',
            'queue.connections.sqs.suffix' => '',
            'queue.connections.sqs.credentials' => false,
            'queue.connections.sqs.handler' => $this->sqs,
            // The SDK mock cannot be used to derive an automatic pool identity.
            'queue.connections.sqs.pool.fingerprint' => self::class,
        ]);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        QueueConnectionTestJob::$ran = false;
        QueueConnectionTestUniqueJob::$ran = false;

        parent::tearDown();
    }

    public function testJobWontGetDispatchedInsideATransaction(): void
    {
        $this->app->singleton('db.transactions', function (): DatabaseTransactionsManager {
            $transactionManager = m::mock(DatabaseTransactionsManager::class);
            $transactionManager->expects('callbackApplicableTransactions')
                ->andReturn(new Collection([new DatabaseTransactionRecord('sqlite', 1)]));
            $transactionManager->expects('addCallback')->andReturn(null);
            $transactionManager->shouldNotReceive('addCallbackForRollback');

            return $transactionManager;
        });

        Bus::dispatch(new QueueConnectionTestJob);

        $this->assertNull($this->sqs->getLastCommand());
    }

    public function testJobWillGetDispatchedInsideATransactionWhenExplicitlyIndicated(): void
    {
        $this->app->singleton('db.transactions', function (): DatabaseTransactionsManager {
            $transactionManager = m::mock(DatabaseTransactionsManager::class);
            $transactionManager->shouldNotReceive('addCallback')->andReturn(null);
            $transactionManager->shouldNotReceive('addCallbackForRollback');

            return $transactionManager;
        });

        Bus::dispatch((new QueueConnectionTestJob)->beforeCommit());

        $this->assertSame('SendMessage', $this->sqs->getLastCommand()?->getName());
    }

    public function testJobWontGetDispatchedInsideATransactionWhenExplicitlyIndicated(): void
    {
        $this->app->make('config')->set('queue.connections.sqs.after_commit', false);

        $this->app->singleton('db.transactions', function (): DatabaseTransactionsManager {
            $transactionManager = m::mock(DatabaseTransactionsManager::class);
            $transactionManager->expects('callbackApplicableTransactions')
                ->andReturn(new Collection([new DatabaseTransactionRecord('sqlite', 1)]));
            $transactionManager->expects('addCallback')->andReturn(null);
            $transactionManager->shouldNotReceive('addCallbackForRollback');

            return $transactionManager;
        });

        Bus::dispatch((new QueueConnectionTestJob)->afterCommit());

        $this->assertNull($this->sqs->getLastCommand());
    }

    public function testUniqueJobWontGetDispatchedInsideATransaction(): void
    {
        $this->app->singleton('db.transactions', function (): DatabaseTransactionsManager {
            $transactionManager = m::mock(DatabaseTransactionsManager::class);
            $transactionManager->expects('callbackApplicableTransactions')
                ->andReturn(new Collection([new DatabaseTransactionRecord('sqlite', 1)]));
            $transactionManager->expects('addCallback')->andReturn(null);
            $transactionManager->expects('addCallbackForRollback')->andReturn(null);

            return $transactionManager;
        });

        QueueConnectionTestUniqueJob::dispatch();

        $this->assertNull($this->sqs->getLastCommand());
    }

    public function testUniqueJobWillGetDispatchedInsideATransactionWhenExplicitlyIndicated(): void
    {
        $this->app->singleton('db.transactions', function (): DatabaseTransactionsManager {
            $transactionManager = m::mock(DatabaseTransactionsManager::class);
            $transactionManager->shouldNotReceive('addCallback')->andReturn(null);
            $transactionManager->shouldNotReceive('addCallbackForRollback')->andReturn(null);

            return $transactionManager;
        });

        QueueConnectionTestUniqueJob::dispatch()->beforeCommit();

        $this->assertSame('SendMessage', $this->sqs->getLastCommand()?->getName());
    }

    public function testUniqueJobWontGetDispatchedInsideATransactionWhenExplicitlyIndicated(): void
    {
        $this->app->make('config')->set('queue.connections.sqs.after_commit', false);

        $this->app->singleton('db.transactions', function (): DatabaseTransactionsManager {
            $transactionManager = m::mock(DatabaseTransactionsManager::class);
            $transactionManager->expects('callbackApplicableTransactions')
                ->andReturn(new Collection([new DatabaseTransactionRecord('sqlite', 1)]));
            $transactionManager->expects('addCallback')->andReturn(null);
            $transactionManager->expects('addCallbackForRollback')->andReturn(null);

            return $transactionManager;
        });

        QueueConnectionTestUniqueJob::dispatch()->afterCommit();

        $this->assertNull($this->sqs->getLastCommand());
    }
}

class QueueConnectionTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$ran = true;
    }
}

class QueueConnectionTestUniqueJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        static::$ran = true;
    }
}
