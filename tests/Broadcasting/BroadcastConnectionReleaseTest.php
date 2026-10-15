<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Ably\AblyRest;
use Ably\Models\Message;
use Closure;
use Hypervel\Broadcasting\Broadcasters\AblyBroadcaster;
use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Broadcasting\Mercure\Hub;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\ObjectPool\Factory;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Pusher\Pusher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;

class BroadcastConnectionReleaseTest extends TestCase
{
    /**
     * Configure a pooled session that broadcasting can return before sending.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.connections.broadcast', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'pool' => ['testing_enabled' => true, 'max_connections' => 1],
        ]);
    }

    public function testPusherReleasesIdleSessionsAndPreservesTransactionsAndPins(): void
    {
        $observed = [];
        $pusher = m::mock(Pusher::class);
        $pusher->shouldReceive('trigger')->times(9)->andReturnUsing(function () use (&$observed): object {
            $observed[] = $this->borrowedConnections();
            DB::connection('broadcast')->selectOne('select 1');

            return (object) [];
        });
        $broadcaster = new PusherBroadcaster($this->app, $pusher);

        $channels = array_map(static fn (int $id): string => 'orders.' . $id, range(1, 201));
        $this->sendInEachScope(fn () => $broadcaster->broadcast($channels, 'created'));

        $this->assertSame([0, 0, 0, 1, 1, 1, 1, 1, 1], $observed);
    }

    public function testAblyReleasesAfterPreparingTheMessage(): void
    {
        $observed = [];
        $ably = m::mock(AblyRest::class);
        $channel = m::mock();
        $channel->shouldReceive('publish')->times(3)->andReturnUsing(function () use (&$observed): void {
            $observed[] = $this->borrowedConnections();
        });
        $channel->shouldReceive('getCipherParams')->andReturnNull();
        $ably->channels = m::mock();
        $ably->channels->shouldReceive('get')->andReturn($channel);
        $ably->channels->shouldReceive('release')->times(3);
        $broadcaster = m::mock(AblyBroadcaster::class, [$this->app, $ably])->makePartial()->shouldAllowMockingProtectedMethods();
        $broadcaster->shouldReceive('buildAblyMessage')->times(3)->andReturnUsing(static function (): Message {
            DB::connection('broadcast')->selectOne('select 1');

            return new Message;
        });

        $this->sendInEachScope(fn () => $broadcaster->broadcast(['orders'], 'created'));

        $this->assertSame([0, 1, 1], $observed);
    }

    public function testMercureReleasesBeforeBorrowingItsHttpClient(): void
    {
        $observed = [];
        $token = m::mock(TokenProviderInterface::class);
        $token->shouldReceive('getJwt')->andReturn('test.token.signature');
        $hub = new Hub(
            new PoolDefinition('release-test', 'mercure', 'release-test', PoolOptions::fromArray([])),
            function () use (&$observed): MockHttpClient {
                $this->assertSame(0, $this->borrowedConnections());

                return new MockHttpClient(function () use (&$observed): MockResponse {
                    $observed[] = $this->borrowedConnections();

                    return new MockResponse('event-id');
                });
            },
            $this->app->make(Factory::class),
            $this->app->make(UrlGenerator::class),
            'https://hub.test/.well-known/mercure',
            $token,
        );

        $this->sendInEachScope(fn () => $hub->publish(new Update('orders', 'created')));

        $this->assertSame([0, 1, 1], $observed);
    }

    /**
     * Exercise ordinary sending and the two explicit session ownership scopes.
     */
    protected function sendInEachScope(Closure $send): void
    {
        $connection = DB::connection('broadcast');
        $connection->selectOne('select 1');
        $send();
        $connection->transaction($send);
        $connection->withPinnedSession(function () use ($connection, $send): void {
            $connection->selectOne('select 1');
            $send();
        });
    }

    /**
     * Observe database occupancy at the outbound transport boundary.
     */
    protected function borrowedConnections(): int
    {
        return $this->app->make(PoolManager::class)->pool('broadcast')->getBorrowedCount();
    }
}
