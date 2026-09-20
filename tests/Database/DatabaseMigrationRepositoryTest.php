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
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseMigrationRepositoryTest extends TestCase
{
    public function testGetRanMigrationsListMigrationsByPackage(): void
    {
        $repository = $this->getRepository();
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->andReturn($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->andReturn($query);
        $query->expects('orderBy')->with('batch', 'asc')->andReturn($query);
        $query->expects('orderBy')->with('migration', 'asc')->andReturn($query);
        $query->expects('pluck')->with('migration')->andReturn(new Collection(['bar']));
        $query->expects('useWritePdo')->andReturn($query);

        $this->assertSame(['bar'], $repository->getRan());
    }

    public function testGetLastMigrationsGetsAllMigrationsWithTheLatestBatchNumber(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $repository = $this->getMockBuilder(DatabaseMigrationRepository::class)->onlyMethods(['getLastBatchNumber'])->setConstructorArgs([
            $resolver, 'migrations',
        ])->getMock();
        $repository->expects($this->once())->method('getLastBatchNumber')->willReturn(1);
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->andReturn($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->andReturn($query);
        $query->expects('where')->with('batch', 1)->andReturn($query);
        $query->expects('orderBy')->with('migration', 'desc')->andReturn($query);
        $query->expects('get')->andReturn(new Collection(['foo']));
        $query->expects('useWritePdo')->andReturn($query);

        $this->assertSame(['foo'], $repository->getLast());
    }

    public function testLogMethodInsertsRecordIntoMigrationTable(): void
    {
        $repository = $this->getRepository();
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->andReturn($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->andReturn($query);
        $query->expects('insert')->with(['migration' => 'bar', 'batch' => 1]);
        $query->expects('useWritePdo')->andReturn($query);

        $repository->log('bar', 1);
    }

    public function testDeleteMethodRemovesAMigrationFromTheTable(): void
    {
        $repository = $this->getRepository();
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->andReturn($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->andReturn($query);
        $query->expects('where')->with('migration', 'foo')->andReturn($query);
        $query->expects('delete');
        $query->expects('useWritePdo')->andReturn($query);
        $migration = (object) ['migration' => 'foo'];

        $repository->delete($migration);
    }

    public function testGetNextBatchNumberReturnsLastBatchNumberPlusOne(): void
    {
        $repository = $this->getMockBuilder(DatabaseMigrationRepository::class)->onlyMethods(['getLastBatchNumber'])->setConstructorArgs([
            m::mock(ConnectionResolverInterface::class), 'migrations',
        ])->getMock();
        $repository->expects($this->once())->method('getLastBatchNumber')->willReturn(1);

        $this->assertSame(2, $repository->getNextBatchNumber());
    }

    #[DataProvider('batchNumberProvider')]
    public function testGetLastBatchNumberReturnsMaxBatch(int|string|null $value, int $expected): void
    {
        $repository = $this->getRepository();
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->andReturn($connectionMock);
        $repository->getConnection()->expects('table')->with('migrations')->andReturn($query);
        $query->expects('max')->with('batch')->andReturn($value);
        $query->expects('useWritePdo')->andReturn($query);

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
        $schema = m::mock(SchemaBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repository->getConnectionResolver()->expects('connection')->times(2)->with(null)->andReturn($connectionMock);
        $repository->getConnection()->expects('getSchemaBuilder')->andReturn($schema);
        $schema->expects('createMigrationRepositoryTable')->with('migrations');

        $repository->createRepository();
    }

    /**
     * Create a migration repository.
     */
    protected function getRepository(): DatabaseMigrationRepository
    {
        return new DatabaseMigrationRepository(m::mock(ConnectionResolverInterface::class), 'migrations');
    }
}
