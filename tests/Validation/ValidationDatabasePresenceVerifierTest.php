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
        $db = m::mock(ConnectionResolverInterface::class);
        $verifier = new DatabasePresenceVerifier($db);
        $verifier->setConnection('connection');
        $conn = m::mock(ConnectionInterface::class);
        $db->expects('connection')->with('connection')->andReturn($conn);
        $builder = m::mock(Builder::class);
        $conn->expects('table')->with('table')->andReturn($builder);
        $builder->expects('useWritePdo')->andReturn($builder);
        $builder->expects('where')->with('column', '=', 'value')->andReturn($builder);
        $extra = ['foo' => 'NULL', 'bar' => 'NOT_NULL', 'baz' => 'taylor', 'faz' => true, 'not' => '!admin'];
        $builder->expects('whereNull')->with('foo');
        $builder->expects('whereNotNull')->with('bar');
        $builder->expects('where')->with('baz', 'taylor');
        $builder->expects('where')->with('faz', '1');
        $builder->expects('where')->with('not', '!=', 'admin');
        $builder->expects('count')->andReturn(100);

        $this->assertSame(100, $verifier->getCount('table', 'column', 'value', null, null, $extra));
    }

    public function testBasicCountWithClosures(): void
    {
        $db = m::mock(ConnectionResolverInterface::class);
        $verifier = new DatabasePresenceVerifier($db);
        $verifier->setConnection('connection');
        $conn = m::mock(ConnectionInterface::class);
        $db->expects('connection')->with('connection')->andReturn($conn);
        $builder = m::mock(Builder::class);
        $conn->expects('table')->with('table')->andReturn($builder);
        $builder->expects('useWritePdo')->andReturn($builder);
        $builder->expects('where')->with('column', '=', 'value')->andReturn($builder);
        $closure = function (Builder $query): void {
            $query->where('closure', 1);
        };
        $extra = ['foo' => 'NULL', 'bar' => 'NOT_NULL', 'baz' => 'taylor', 'faz' => true, 'not' => '!admin', 0 => $closure];
        $builder->expects('whereNull')->with('foo');
        $builder->expects('whereNotNull')->with('bar');
        $builder->expects('where')->with('baz', 'taylor');
        $builder->expects('where')->with('faz', '1');
        $builder->expects('where')->with('not', '!=', 'admin');
        $builder->expects('where')->with(m::type(Closure::class))->andReturnUsing(function (Closure $callback) use ($builder): Builder {
            $callback($builder);

            return $builder;
        });
        $builder->expects('where')->with('closure', 1);
        $builder->expects('count')->andReturn(100);

        $this->assertSame(100, $verifier->getCount('table', 'column', 'value', null, null, $extra));
    }

    public function testGetCountWithValidExcludeId(): void
    {
        $db = m::mock(ConnectionResolverInterface::class);
        $verifier = new DatabasePresenceVerifier($db);
        $verifier->setConnection('connection');
        $conn = m::mock(ConnectionInterface::class);
        $db->expects('connection')->with('connection')->andReturn($conn);
        $builder = m::mock(Builder::class);
        $conn->expects('table')->with('table')->andReturn($builder);
        $builder->expects('useWritePdo')->andReturn($builder);
        $builder->expects('where')->with('column', '=', 'value')->andReturn($builder);
        $builder->expects('where')->with('id', '<>', 123)->andReturn($builder);
        $builder->expects('count')->andReturn(100);

        $this->assertSame(100, $verifier->getCount('table', 'column', 'value', 123, 'id', []));
    }

    #[DataProvider('connections')]
    public function testGetExistingValuesUsesRequestedConnectionAndReturnsDistinctValues(?string $connection): void
    {
        $verifier = new DatabasePresenceVerifier($db = m::mock(ConnectionResolverInterface::class));
        $verifier->setConnection('stateful-connection');
        $db->expects('connection')->with($connection)->andReturn($database = m::mock(ConnectionInterface::class));
        $database->expects('table')->with('table')->andReturn($builder = m::mock(Builder::class));
        $builder->expects('useWritePdo')->andReturnSelf();
        $builder->expects('whereIn')->with('column', ['first', 'second'])->andReturnSelf();
        $builder->expects('where')->with('uuid', '<>', 'ignored')->andReturnSelf();
        $builder->expects('whereNull')->with('deleted_at');
        $builder->expects('where')->with('status', 'active');
        $builder->expects('distinct')->andReturnSelf();
        $builder->expects('pluck')->with('column')->andReturn(new Collection(['first']));

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
