<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Closure;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\DatabasePresenceVerifier;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidationDatabasePresenceVerifierTest extends TestCase
{
    public function testBasicCount(): void
    {
        $verifier = new DatabasePresenceVerifier($db = m::mock(ConnectionResolverInterface::class));
        $verifier->setConnection('connection');
        $db->shouldReceive('connection')->once()->with('connection')->andReturn($conn = m::mock(ConnectionInterface::class));
        $conn->shouldReceive('table')->once()->with('table')->andReturn($builder = m::mock(Builder::class));
        $builder->shouldReceive('useWritePdo')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('column', '=', 'value')->andReturn($builder);
        $extra = ['foo' => 'NULL', 'bar' => 'NOT_NULL', 'baz' => 'taylor', 'faz' => true, 'not' => '!admin'];
        $builder->shouldReceive('whereNull')->with('foo');
        $builder->shouldReceive('whereNotNull')->with('bar');
        $builder->shouldReceive('where')->with('baz', 'taylor');
        $builder->shouldReceive('where')->with('faz', true);
        $builder->shouldReceive('where')->with('not', '!=', 'admin');
        $builder->shouldReceive('count')->once()->andReturn(100);

        $this->assertSame(100, $verifier->getCount('table', 'column', 'value', null, null, $extra));
    }

    public function testBasicCountWithClosures(): void
    {
        $verifier = new DatabasePresenceVerifier($db = m::mock(ConnectionResolverInterface::class));
        $verifier->setConnection('connection');
        $db->shouldReceive('connection')->once()->with('connection')->andReturn($conn = m::mock(ConnectionInterface::class));
        $conn->shouldReceive('table')->once()->with('table')->andReturn($builder = m::mock(Builder::class));
        $builder->shouldReceive('useWritePdo')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('column', '=', 'value')->andReturn($builder);
        $closure = function ($query): void {
            $query->where('closure', 1);
        };
        $extra = ['foo' => 'NULL', 'bar' => 'NOT_NULL', 'baz' => 'taylor', 'faz' => true, 'not' => '!admin', 0 => $closure];
        $builder->shouldReceive('whereNull')->with('foo');
        $builder->shouldReceive('whereNotNull')->with('bar');
        $builder->shouldReceive('where')->with('baz', 'taylor');
        $builder->shouldReceive('where')->with('faz', true);
        $builder->shouldReceive('where')->with('not', '!=', 'admin');
        $builder->shouldReceive('where')->with(m::type(Closure::class))->andReturnUsing(function () use ($builder, $closure) {
            $closure($builder);

            return $builder;
        });
        $builder->shouldReceive('where')->with('closure', 1);
        $builder->shouldReceive('count')->once()->andReturn(100);

        $this->assertSame(100, $verifier->getCount('table', 'column', 'value', null, null, $extra));
    }

    public function testGetCountWithValidExcludeId(): void
    {
        $verifier = new DatabasePresenceVerifier($db = m::mock(ConnectionResolverInterface::class));
        $verifier->setConnection('connection');
        $db->shouldReceive('connection')->once()->with('connection')->andReturn($conn = m::mock(ConnectionInterface::class));
        $conn->shouldReceive('table')->once()->with('table')->andReturn($builder = m::mock(Builder::class));
        $builder->shouldReceive('useWritePdo')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('column', '=', 'value')->andReturn($builder);
        $builder->shouldReceive('where')->with('id', '<>', 123)->andReturn($builder);
        $builder->shouldReceive('count')->once()->andReturn(100);

        $this->assertSame(100, $verifier->getCount('table', 'column', 'value', 123, 'id', []));
    }

    #[DataProvider('connections')]
    public function testGetExistingValuesUsesRequestedConnectionAndQueryShape(?string $connection): void
    {
        $verifier = new DatabasePresenceVerifier($db = m::mock(ConnectionResolverInterface::class));
        $verifier->setConnection('stateful-connection');
        $db->shouldReceive('connection')->once()->with($connection)->andReturn($database = m::mock(ConnectionInterface::class));
        $database->shouldReceive('table')->once()->with('table')->andReturn($builder = m::mock(Builder::class));
        $builder->shouldReceive('useWritePdo')->once()->andReturnSelf();
        $builder->shouldReceive('whereIn')->once()->with('column', ['first', 'second'])->andReturnSelf();
        $builder->shouldReceive('where')->once()->with('uuid', '<>', 'ignored')->andReturnSelf();
        $builder->shouldReceive('whereNull')->once()->with('deleted_at');
        $builder->shouldReceive('where')->once()->with('status', 'active');
        $builder->shouldReceive('pluck')->once()->with('column')->andReturn(new Collection(['first']));

        $this->assertSame(['first'], $verifier->getExistingValues(
            'table',
            'column',
            ['first', 'second'],
            $connection,
            'ignored',
            'uuid',
            ['deleted_at' => 'NULL', 'status' => 'active'],
        ));
    }

    /**
     * Provide database connections.
     */
    public static function connections(): array
    {
        return [['named'], [null]];
    }
}
