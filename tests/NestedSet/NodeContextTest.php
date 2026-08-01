<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Model;
use Hypervel\NestedSet\NodeContext;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

class NodeContextTest extends TestCase
{
    public function testStructuralIdentityUsesTheNormalizedConnectionNameAndTableWithoutResolving(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->andReturn('primary::write');
        $resolver->shouldNotReceive('connection');

        Model::setConnectionResolver($resolver);

        $default = new NodeContextTestModel;
        $read = (new NodeContextTestAliasModel)->setConnection('primary::read');
        $write = (new NodeContextTestAliasModel)->setConnection('primary::write');
        $otherTable = new NodeContextTestOtherTableModel;
        $otherConnection = (new NodeContextTestModel)->setConnection('secondary');

        $this->assertSame(
            NodeContext::structuralIdentity($default),
            NodeContext::structuralIdentity($read),
        );
        $this->assertSame(
            NodeContext::structuralIdentity($default),
            NodeContext::structuralIdentity($write),
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
        $resolver = m::mock(ConnectionResolverInterface::class);
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
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->andReturn(null);

        Model::setConnectionResolver($resolver);

        $this->assertSame(
            '7:default:nodes',
            NodeContext::structuralIdentity(new NodeContextTestModel),
        );
    }

    public function testFreshnessRevisionIsSharedByLogicalTableAndSeparatedByConnectionAndTable(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->andReturn('primary');

        Model::setConnectionResolver($resolver);

        $model = new NodeContextTestModel;
        $alias = (new NodeContextTestAliasModel)->setConnection('primary::read');

        $this->assertTrue(NodeContext::isCurrent($model));

        NodeContext::markTreeChanged($model);

        $this->assertFalse(NodeContext::isCurrent($model));
        $this->assertFalse(NodeContext::isCurrent($alias));
        $this->assertTrue(NodeContext::isCurrent(new NodeContextTestOtherTableModel));
        $this->assertTrue(NodeContext::isCurrent(
            (new NodeContextTestModel)->setConnection('secondary'),
        ));

        NodeContext::markCurrent($alias);

        $this->assertTrue(NodeContext::isCurrent($alias));
        $this->assertFalse(NodeContext::isCurrent($model));

        NodeContext::markTreeChanged($model);

        $this->assertFalse(NodeContext::isCurrent($alias));
    }

    public function testFreshnessIsIsolatedBetweenCoroutines(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->andReturn('primary');

        Model::setConnectionResolver($resolver);

        $model = new NodeContextTestModel;

        [$writer, $reader] = parallel([
            function () use ($model): bool {
                NodeContext::markTreeChanged($model);
                usleep(5000);

                return NodeContext::isCurrent($model);
            },
            function () use ($model): bool {
                usleep(1000);

                return NodeContext::isCurrent($model);
            },
        ]);

        $this->assertFalse($writer);
        $this->assertTrue($reader);
    }

    public function testCopiedCoroutineFreshnessDoesNotCopyModelObservations(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->andReturn('primary');

        Model::setConnectionResolver($resolver);

        $model = new NodeContextTestModel;
        NodeContext::markTreeChanged($model);
        NodeContext::markCurrent($model);

        [$child] = parallel([
            function () use ($model): array {
                $before = NodeContext::isCurrent($model);
                NodeContext::markCurrent($model);
                $afterObservation = NodeContext::isCurrent($model);
                NodeContext::markTreeChanged($model);

                return [$before, $afterObservation, NodeContext::isCurrent($model)];
            },
        ], copyContext: true);

        $this->assertSame([false, true, false], $child);
        $this->assertTrue(NodeContext::isCurrent($model));
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
