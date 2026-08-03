<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Closure;
use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\BroadcastPoolProxy;
use Hypervel\Contracts\Broadcasting\Broadcaster as BroadcasterContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class BroadcastPoolProxyTest extends TestCase
{
    /** @var list<PoolManager> */
    private array $poolManagers = [];

    protected function tearDownInCoroutine(): void
    {
        foreach ($this->poolManagers as $poolManager) {
            $poolManager->flush();
        }
    }

    public function testAuthenticatedUserResolverStateIsWrittenOnEveryBorrowIncludingNull(): void
    {
        $broadcaster = new PoolProxyUserAuthenticationBroadcaster(m::mock(ContainerContract::class));
        [$configured, $pools, $definition] = $this->proxy(fn () => $broadcaster);
        [$unconfigured] = $this->proxy(fn () => $broadcaster, $pools, $definition);
        $configured->resolveAuthenticatedUserUsing(function (Request $request): array {
            return ['id' => 'user-' . $request->input('socket_id')];
        });

        $this->assertSame(
            ['id' => 'user-1.1'],
            $configured->resolveAuthenticatedUser(
                Request::create('/broadcasting/user-auth', 'POST', ['socket_id' => '1.1'])
            )
        );
        $this->assertNull(
            $unconfigured->resolveAuthenticatedUser(
                Request::create('/broadcasting/user-auth', 'POST', ['socket_id' => '2.2'])
            )
        );
        $this->assertSame(
            ['id' => 'user-3.3'],
            $configured->resolveAuthenticatedUser(
                Request::create('/broadcasting/user-auth', 'POST', ['socket_id' => '3.3'])
            )
        );
    }

    public function testReplacingAndClearingAuthenticatedUserResolverUpdatesBorrowedBroadcaster(): void
    {
        [$proxy] = $this->proxy(
            fn () => new PoolProxyUserAuthenticationBroadcaster(m::mock(ContainerContract::class))
        );
        $request = Request::create('/broadcasting/user-auth', 'POST');

        $proxy->resolveAuthenticatedUserUsing(fn (): array => ['id' => 'first']);
        $this->assertSame(['id' => 'first'], $proxy->resolveAuthenticatedUser($request));

        $proxy->resolveAuthenticatedUserUsing(fn (): array => ['id' => 'second']);
        $this->assertSame(['id' => 'second'], $proxy->resolveAuthenticatedUser($request));

        $proxy->resolveAuthenticatedUserUsing(null);
        $this->assertNull($proxy->resolveAuthenticatedUser($request));
    }

    public function testContractOnlyBroadcasterSupportsContractMethodsWhenNoCallbackIsConfigured(): void
    {
        $broadcaster = new PoolProxyContractOnlyBroadcaster;
        [$proxy] = $this->proxy(fn () => $broadcaster);
        $request = Request::create('/broadcasting/auth', 'POST');

        $this->assertSame('authenticated', $proxy->auth($request));
        $this->assertSame('valid-result', $proxy->validAuthenticationResponse($request, 'result'));
        $proxy->broadcast(['channel'], 'event', ['payload' => true]);
        $this->assertSame([
            [['channel'], 'event', ['payload' => true]],
        ], $broadcaster->broadcasts);
        $this->assertSame(['contract-channel'], $proxy->getChannels()->all());
    }

    public function testConfiguredCallbackFailsClearlyForContractOnlyBroadcaster(): void
    {
        [$proxy] = $this->proxy(fn () => new PoolProxyContractOnlyBroadcaster);
        $proxy->resolveAuthenticatedUserUsing(fn (): array => ['id' => 'user']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Authenticated-user resolver callbacks on pooled broadcasters require an instance of '
            . Broadcaster::class . '; [' . PoolProxyContractOnlyBroadcaster::class . '] was returned.'
        );

        $proxy->auth(Request::create('/broadcasting/auth', 'POST'));
    }

    public function testChannelRegistrationReturnsTheProxy(): void
    {
        [$proxy] = $this->proxy(
            fn () => new PoolProxyUserAuthenticationBroadcaster(m::mock(ContainerContract::class))
        );

        $this->assertSame($proxy, $proxy->channel('orders.{order}', fn (): bool => true));
        $this->assertArrayHasKey('orders.{order}', $proxy->getChannels()->all());
    }

    /**
     * Create a broadcast proxy and its isolated registry.
     *
     * @return array{BroadcastPoolProxy, PoolManager, PoolDefinition}
     */
    protected function proxy(
        Closure $resolver,
        ?PoolManager $pools = null,
        ?PoolDefinition $definition = null,
    ): array {
        if ($pools === null) {
            $this->poolManagers[] = $pools = new PoolManager;
        }

        $definition ??= new PoolDefinition(
            'broadcast-test',
            'broadcast-test',
            'auto:broadcast-test',
            PoolOptions::fromArray([]),
        );

        return [
            new BroadcastPoolProxy($definition, $resolver, $pools),
            $pools,
            $definition,
        ];
    }
}

class PoolProxyUserAuthenticationBroadcaster extends Broadcaster
{
    public function __construct(
        protected ContainerContract $container
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

class PoolProxyContractOnlyBroadcaster implements BroadcasterContract
{
    public array $broadcasts = [];

    public function auth(Request $request): mixed
    {
        return 'authenticated';
    }

    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return 'valid-' . $result;
    }

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        $this->broadcasts[] = [$channels, $event, $payload];
    }

    public function getChannels(): Collection
    {
        return new Collection(['contract-channel']);
    }
}
