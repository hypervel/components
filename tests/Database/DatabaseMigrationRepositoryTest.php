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
    public function testGetRanMigrationsListMigrationsByPackage()
    {
        $repo = $this->getRepository();
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repo->getConnectionResolver()->shouldReceive('connection')->with(null)->andReturn($connectionMock);
        $repo->getConnection()->shouldReceive('table')->once()->with('migrations')->andReturn($query);
        $query->shouldReceive('orderBy')->once()->with('batch', 'asc')->andReturn($query);
        $query->shouldReceive('orderBy')->once()->with('migration', 'asc')->andReturn($query);
        $query->shouldReceive('pluck')->once()->with('migration')->andReturn(new Collection(['bar']));
        $query->shouldReceive('useWritePdo')->once()->andReturn($query);

        $this->assertEquals(['bar'], $repo->getRan());
    }

    public function testGetLastMigrationsGetsAllMigrationsWithTheLatestBatchNumber()
    {
        $repo = $this->getMockBuilder(DatabaseMigrationRepository::class)->onlyMethods(['getLastBatchNumber'])->setConstructorArgs([
            $resolver = m::mock(ConnectionResolverInterface::class), 'migrations',
        ])->getMock();
        $repo->expects($this->once())->method('getLastBatchNumber')->willReturn(1);
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repo->getConnectionResolver()->shouldReceive('connection')->with(null)->andReturn($connectionMock);
        $repo->getConnection()->shouldReceive('table')->once()->with('migrations')->andReturn($query);
        $query->shouldReceive('where')->once()->with('batch', 1)->andReturn($query);
        $query->shouldReceive('orderBy')->once()->with('migration', 'desc')->andReturn($query);
        $query->shouldReceive('get')->once()->andReturn(new Collection(['foo']));
        $query->shouldReceive('useWritePdo')->once()->andReturn($query);

        $this->assertEquals(['foo'], $repo->getLast());
    }

    public function testLogMethodInsertsRecordIntoMigrationTable()
    {
        $repo = $this->getRepository();
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repo->getConnectionResolver()->shouldReceive('connection')->with(null)->andReturn($connectionMock);
        $repo->getConnection()->shouldReceive('table')->once()->with('migrations')->andReturn($query);
        $query->shouldReceive('insert')->once()->with(['migration' => 'bar', 'batch' => 1]);
        $query->shouldReceive('useWritePdo')->once()->andReturn($query);

        $repo->log('bar', 1);
    }

    public function testDeleteMethodRemovesAMigrationFromTheTable()
    {
        $repo = $this->getRepository();
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repo->getConnectionResolver()->shouldReceive('connection')->with(null)->andReturn($connectionMock);
        $repo->getConnection()->shouldReceive('table')->once()->with('migrations')->andReturn($query);
        $query->shouldReceive('where')->once()->with('migration', 'foo')->andReturn($query);
        $query->shouldReceive('delete')->once();
        $query->shouldReceive('useWritePdo')->once()->andReturn($query);
        $migration = (object) ['migration' => 'foo'];

        $repo->delete($migration);
    }

    public function testGetNextBatchNumberReturnsLastBatchNumberPlusOne()
    {
        $repo = $this->getMockBuilder(DatabaseMigrationRepository::class)->onlyMethods(['getLastBatchNumber'])->setConstructorArgs([
            m::mock(ConnectionResolverInterface::class), 'migrations',
        ])->getMock();
        $repo->expects($this->once())->method('getLastBatchNumber')->willReturn(1);

        $this->assertEquals(2, $repo->getNextBatchNumber());
    }

    #[DataProvider('batchNumberProvider')]
    public function testGetLastBatchNumberReturnsMaxBatch(int|string|null $value, int $expected): void
    {
        $repo = $this->getRepository();
        $query = m::mock(QueryBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repo->getConnectionResolver()->shouldReceive('connection')->with(null)->andReturn($connectionMock);
        $repo->getConnection()->shouldReceive('table')->once()->with('migrations')->andReturn($query);
        $query->shouldReceive('max')->once()->with('batch')->andReturn($value);
        $query->shouldReceive('useWritePdo')->once()->andReturn($query);

        $this->assertSame($expected, $repo->getLastBatchNumber());
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

    public function testCreateRepositoryDelegatesItsTableDefinitionToTheSchemaBuilder(): void
    {
        $repo = $this->getRepository();
        $schema = m::mock(SchemaBuilder::class);
        $connectionMock = m::mock(Connection::class);
        $repo->getConnectionResolver()->shouldReceive('connection')->with(null)->andReturn($connectionMock);
        $repo->getConnection()->shouldReceive('getSchemaBuilder')->once()->andReturn($schema);
        $schema->shouldReceive('createMigrationRepositoryTable')->once()->with('migrations');

        $repo->createRepository();
    }

    protected function getRepository()
    {
        return new DatabaseMigrationRepository(m::mock(ConnectionResolverInterface::class), 'migrations');
    }
}
