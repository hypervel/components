<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\BroadcastPoolProxy;
use Hypervel\Container\Container as ApplicationContainer;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use Hypervel\Tests\TestCase;
use Mockery as m;

class BroadcastPoolProxyTest extends TestCase
{
    public function testAuthenticatedUserResolverIsAppliedToEachBorrowedBroadcaster(): void
    {
        $firstBroadcaster = new PoolProxyUserAuthenticationBroadcaster(m::mock(Container::class));
        $secondBroadcaster = new PoolProxyUserAuthenticationBroadcaster(m::mock(Container::class));

        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('get')
            ->twice()
            ->andReturn($firstBroadcaster, $secondBroadcaster);
        $pool->shouldReceive('release')
            ->once()
            ->with($firstBroadcaster);
        $pool->shouldReceive('release')
            ->once()
            ->with($secondBroadcaster);

        $proxy = $this->makeProxy($pool);
        $proxy->resolveAuthenticatedUserUsing(function (Request $request): array {
            return ['id' => 'user-' . $request->input('socket_id')];
        });

        $this->assertSame(
            ['id' => 'user-1.1'],
            $proxy->resolveAuthenticatedUser(Request::create('/broadcasting/user-auth', 'POST', ['socket_id' => '1.1']))
        );
        $this->assertSame(
            ['id' => 'user-2.2'],
            $proxy->resolveAuthenticatedUser(Request::create('/broadcasting/user-auth', 'POST', ['socket_id' => '2.2']))
        );
    }

    public function testReplacingAuthenticatedUserResolverOverridesBorrowedBroadcasterCallback(): void
    {
        $broadcaster = new PoolProxyUserAuthenticationBroadcaster(m::mock(Container::class));

        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('get')
            ->twice()
            ->andReturn($broadcaster);
        $pool->shouldReceive('release')
            ->twice()
            ->with($broadcaster);

        $proxy = $this->makeProxy($pool);
        $proxy->resolveAuthenticatedUserUsing(fn (): array => ['id' => 'first']);

        $this->assertSame(
            ['id' => 'first'],
            $proxy->resolveAuthenticatedUser(Request::create('/broadcasting/user-auth', 'POST'))
        );

        $proxy->resolveAuthenticatedUserUsing(fn (): array => ['id' => 'second']);

        $this->assertSame(
            ['id' => 'second'],
            $proxy->resolveAuthenticatedUser(Request::create('/broadcasting/user-auth', 'POST'))
        );
    }

    protected function makeProxy(ObjectPool $pool): BroadcastPoolProxy
    {
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('create')
            ->once()
            ->andReturn($pool);

        $container = new ApplicationContainer;
        $container->instance(PoolFactory::class, $poolFactory);
        ApplicationContainer::setInstance($container);

        return new BroadcastPoolProxy('broadcasting:test', fn () => null);
    }
}

class PoolProxyUserAuthenticationBroadcaster extends Broadcaster
{
    public function __construct(
        protected Container $container
    ) {
    }

    public function auth(Request $request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return null;
    }

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
    }
}
