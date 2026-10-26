<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Hypervel\Bus\Queueable;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Queue\Outbox\DatabaseOutboxRepository;
use Hypervel\Queue\Outbox\OutboxDispatcher;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class OutboxDispatcherTest extends TestCase
{
    #[DataProvider('ownershipCases')]
    public function testStorageResolvesTheExactTransactionOwner(array $active, ?string $explicit, array $configured, ?string $expected, ?string $error): void
    {
        $transactions = new DatabaseTransactionsManager;
        $connections = [];

        foreach (['business', 'business::write', 'business::read', 'secondary'] as $name) {
            [$base, $role] = array_pad(explode('::', $name), 2, null);
            $connection = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => $base]);
            $connection->setTransactionManager($transactions);

            if ($role !== null) {
                $connection->setReadWriteType($role);
            }

            $connections[$name] = $connection;
        }

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturnUsing(fn (string $name): SQLiteConnection => $connections[$name]);
        $repository = new DatabaseOutboxRepository($resolver, $configured, 'job_outbox', 300);
        $dispatcher = new OutboxDispatcher($repository, $resolver, $transactions);
        $job = new OutboxOwnershipJob;

        if ($explicit !== null) {
            $job->onOutboxDatabase($explicit);
        }

        try {
            foreach ($active as $name) {
                $connections[$name]->beginTransaction();
            }

            if ($error !== null) {
                $this->expectException(LogicException::class);
                $this->expectExceptionMessage($error);
            }

            $owner = $dispatcher->databaseFor($job);

            $this->assertSame($connections[$expected], $owner);
        } finally {
            foreach ($connections as $connection) {
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack(0);
                }
            }
        }
    }

    /**
     * Provide distinct owner sessions and realistic selection conflicts.
     */
    public static function ownershipCases(): array
    {
        return [
            'configured default without transaction' => [[], null, ['business'], 'business', null],
            'explicit configured source without transaction' => [[], 'secondary', ['business', 'secondary'], 'secondary', null],
            'single active source overrides default' => [['secondary'], null, ['business', 'secondary'], 'secondary', null],
            'nested transactions retain one owner' => [['business', 'business'], null, ['business'], 'business', null],
            'writer is covered by base source' => [['business::write'], null, ['business'], 'business::write', null],
            'explicit writer selects one of two sessions' => [['business', 'business::write'], 'business::write', ['business'], 'business::write', null],
            'distinct sessions require explicit owner' => [['business', 'business::write'], null, ['business'], null, 'onOutboxDatabase()'],
            'explicit owner must participate' => [['business'], 'secondary', ['business', 'secondary'], null, 'does not own an active transaction'],
            'active source must be drainable' => [['secondary'], null, ['business'], null, 'not covered by queue.outbox.databases'],
            'active read session rejected' => [['business::read'], null, ['business'], null, 'is a read connection'],
            'explicit read session rejected' => [[], 'business::read', ['business'], null, 'is a read connection'],
        ];
    }
}

class OutboxOwnershipJob
{
    use Queueable;
}
