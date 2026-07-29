<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\NodeContext;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class NodeContextTest extends TestCase
{
    public function testStructuralIdentityUsesTheResolvedConnectionAndTable(): void
    {
        $primary = m::mock(Connection::class);
        $primary->shouldReceive('getName')->andReturn('primary');

        $secondary = m::mock(Connection::class);
        $secondary->shouldReceive('getName')->andReturn('secondary');

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($primary);
        $resolver->shouldReceive('connection')->with('alias')->andReturn($primary);
        $resolver->shouldReceive('connection')->with('secondary')->andReturn($secondary);

        Model::setConnectionResolver($resolver);

        $default = new NodeContextTestModel;
        $alias = (new NodeContextTestAliasModel)->setConnection('alias');
        $otherTable = new NodeContextTestOtherTableModel;
        $otherConnection = (new NodeContextTestModel)->setConnection('secondary');

        $this->assertSame(
            NodeContext::structuralIdentity($default),
            NodeContext::structuralIdentity($alias),
        );
        $this->assertNotSame(
            NodeContext::structuralIdentity($default),
            NodeContext::structuralIdentity($otherTable),
        );
        $this->assertNotSame(
            NodeContext::structuralIdentity($default),
            NodeContext::structuralIdentity($otherConnection),
        );
    }

    public function testStructuralIdentityFallsBackToTheResolversDefaultConnection(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn(null);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);
        $resolver->shouldReceive('connection')->with('primary')->andReturn($connection);
        $resolver->shouldReceive('getDefaultConnection')->andReturn('primary');

        Model::setConnectionResolver($resolver);

        $default = new NodeContextTestModel;
        $explicit = (new NodeContextTestModel)->setConnection('primary');

        $this->assertSame(
            NodeContext::structuralIdentity($default),
            NodeContext::structuralIdentity($explicit),
        );
    }

    public function testStructuralIdentityUsesAStableMarkerWithoutAnyConnectionName(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn(null);

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);
        $resolver->shouldReceive('getDefaultConnection')->andReturn(null);

        Model::setConnectionResolver($resolver);

        $this->assertSame(
            '7:default:nodes',
            NodeContext::structuralIdentity(new NodeContextTestModel),
        );
    }

    public function testFreshnessIsSharedByLogicalTableAndSeparatedByConnectionAndTable(): void
    {
        $primary = m::mock(Connection::class);
        $primary->shouldReceive('getName')->andReturn('primary');

        $secondary = m::mock(Connection::class);
        $secondary->shouldReceive('getName')->andReturn('secondary');

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($primary);
        $resolver->shouldReceive('connection')->with('alias')->andReturn($primary);
        $resolver->shouldReceive('connection')->with('secondary')->andReturn($secondary);

        Model::setConnectionResolver($resolver);

        NodeContext::setHasPerformed(new NodeContextTestModel);

        $this->assertTrue(NodeContext::hasPerformed((new NodeContextTestAliasModel)->setConnection('alias')));
        $this->assertFalse(NodeContext::hasPerformed(new NodeContextTestOtherTableModel));
        $this->assertFalse(NodeContext::hasPerformed(
            (new NodeContextTestModel)->setConnection('secondary'),
        ));
    }

    public function testFreshnessIsIsolatedBetweenCoroutines(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('primary');

        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->with(null)->andReturn($connection);

        Model::setConnectionResolver($resolver);

        $model = new NodeContextTestModel;

        [$writer, $reader] = parallel([
            function () use ($model): bool {
                NodeContext::setHasPerformed($model);
                usleep(5000);

                return NodeContext::hasPerformed($model);
            },
            function () use ($model): bool {
                usleep(1000);

                return NodeContext::hasPerformed($model);
            },
        ]);

        $this->assertTrue($writer);
        $this->assertFalse($reader);
    }
}

class NodeContextTestModel extends Model
{
    protected ?string $table = 'nodes';
}

class NodeContextTestAliasModel extends Model
{
    protected ?string $table = 'nodes';
}

class NodeContextTestOtherTableModel extends Model
{
    protected ?string $table = 'other_nodes';
}
