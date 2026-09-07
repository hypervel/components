<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseProcessorTest;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class DatabaseProcessorTest extends TestCase
{
    public function testInsertGetIdProcessing(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('insert')->once()->with('sql', ['foo']);
        $connection->shouldReceive('getLastInsertId')->once()->with('id')->andReturn('1');
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getConnection')->andReturn($connection);
        $processor = new Processor;
        $result = $processor->processInsertGetId($builder, 'sql', ['foo'], 'id');
        $this->assertSame(1, $result);
    }

    public function testInsertGetIdPreservesTheConnectionFailureAfterTheInsertCompletes(): void
    {
        $failure = new RuntimeException('The database driver could not retrieve the last insert ID.');
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('insert')->once()->with('sql', ['foo'])->andReturnTrue();
        $connection->shouldReceive('getLastInsertId')->once()->with('id')->andThrow($failure);
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getConnection')->twice()->andReturn($connection);

        $exception = null;

        try {
            (new Processor)->processInsertGetId($builder, 'sql', ['foo'], 'id');
        } catch (RuntimeException $thrown) {
            $exception = $thrown;
        }

        $this->assertSame($failure, $exception);
    }
}
