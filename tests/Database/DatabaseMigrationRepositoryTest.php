<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Migrations\DatabaseMigrationRepository;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseMigrationRepositoryTest extends TestCase
{
    use VerifiesDoubles;

    public function testGetRanMigrationsListMigrationsByPackage(): void
    {
        $repository = $this->getRepository();
        $query = Double::for(QueryBuilder::class)->strict();
        $connectionMock = Double::for(Connection::class)->strict();
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->returns($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->returns($query);
        $query->expects('orderBy')->with('batch', 'asc')->returns($query);
        $query->expects('orderBy')->with('migration', 'asc')->returns($query);
        $query->expects('pluck')->with('migration')->returns(new Collection(['bar']));
        $query->expects('useWritePdo')->returns($query);

        $this->assertSame(['bar'], $repository->getRan());
    }

    public function testGetLastMigrationsGetsAllMigrationsWithTheLatestBatchNumber(): void
    {
        $resolver = Double::for(ConnectionResolverInterface::class)->strict();
        $repository = $this->getMockBuilder(DatabaseMigrationRepository::class)->onlyMethods(['getLastBatchNumber'])->setConstructorArgs([
            $resolver, 'migrations',
        ])->getMock();
        $repository->expects($this->once())->method('getLastBatchNumber')->willReturn(1);
        $query = Double::for(QueryBuilder::class)->strict();
        $connectionMock = Double::for(Connection::class)->strict();
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->returns($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->returns($query);
        $query->expects('where')->with('batch', 1)->returns($query);
        $query->expects('orderBy')->with('migration', 'desc')->returns($query);
        $query->expects('get')->returns(new Collection(['foo']));
        $query->expects('useWritePdo')->returns($query);

        $this->assertSame(['foo'], $repository->getLast());
    }

    public function testLogMethodInsertsRecordIntoMigrationTable(): void
    {
        $repository = $this->getRepository();
        $query = Double::for(QueryBuilder::class)->strict();
        $connectionMock = Double::for(Connection::class)->strict();
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->returns($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->returns($query);
        $query->expects('insert')->with(['migration' => 'bar', 'batch' => 1]);
        $query->expects('useWritePdo')->returns($query);

        $repository->log('bar', 1);
    }

    public function testDeleteMethodRemovesAMigrationFromTheTable(): void
    {
        $repository = $this->getRepository();
        $query = Double::for(QueryBuilder::class)->strict();
        $connectionMock = Double::for(Connection::class)->strict();
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->returns($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->returns($query);
        $query->expects('where')->with('migration', 'foo')->returns($query);
        $query->expects('delete');
        $query->expects('useWritePdo')->returns($query);
        $migration = (object) ['migration' => 'foo'];

        $repository->delete($migration);
    }

    public function testGetNextBatchNumberReturnsLastBatchNumberPlusOne(): void
    {
        $repository = $this->getMockBuilder(DatabaseMigrationRepository::class)->onlyMethods(['getLastBatchNumber'])->setConstructorArgs([
            Double::for(ConnectionResolverInterface::class)->strict(), 'migrations',
        ])->getMock();
        $repository->expects($this->once())->method('getLastBatchNumber')->willReturn(1);

        $this->assertSame(2, $repository->getNextBatchNumber());
    }

    #[DataProvider('batchNumberProvider')]
    public function testGetLastBatchNumberReturnsMaxBatch(int|string|null $value, int $expected): void
    {
        $repository = $this->getRepository();
        $query = Double::for(QueryBuilder::class)->strict();
        $connectionMock = Double::for(Connection::class)->strict();
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->returns($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->returns($query);
        $query->expects('max')->with('batch')->returns($value);
        $query->expects('useWritePdo')->returns($query);

        $this->assertSame($expected, $repository->getLastBatchNumber());
    }

    /**
     * Provide native and string-valued migration batches.
     */
    public static function batchNumberProvider(): array
    {
        return [
            'native integer' => [1, 1],
            'numeric string' => ['42', 42],
            'empty repository' => [null, 0],
        ];
    }

    public function testCreateRepositoryCreatesProperDatabaseTable(): void
    {
        $repository = $this->getRepository();
        $schema = Double::for(SchemaBuilder::class)->strict();
        $connectionMock = Double::for(Connection::class)->strict();
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->returns($connectionMock);
        $repository->getConnection()->expects('getSchemaBuilder')->returns($schema);
        $schema->expects('createMigrationRepositoryTable')->with('migrations');

        $repository->createRepository();
    }

    /**
     * Create a migration repository.
     */
    protected function getRepository(): DatabaseMigrationRepository
    {
        return new DatabaseMigrationRepository(Double::for(ConnectionResolverInterface::class)->strict(), 'migrations');
    }
}
