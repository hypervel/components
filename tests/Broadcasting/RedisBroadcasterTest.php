<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Broadcasters\RedisBroadcaster;
use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Http\Request;
use Hypervel\Tests\Broadcasting\Fixtures\RedisBroadcasterConnection;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JsonException;
use RedisClusterException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RedisBroadcasterTest extends TestCase
{
    use VerifiesDoubles;

    protected RedisBroadcaster $broadcaster;

    protected Container $container;

    protected Redis&DoubleInterface $redis;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container;
        $this->redis = Double::for(Redis::class)->strict();
        $this->broadcaster = new RedisBroadcaster($this->container, $this->redis);
    }

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue(): void
    {
        $this->broadcaster->channel('test', function (): bool {
            return true;
        });

        $this->assertSame(
            json_encode(true),
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-test')
            ),
        );
    }

    public function testAuthRejectsMissingChannelName(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $request = new Request;

        $this->broadcaster->auth($request);
    }

    public function testAuthRejectsArrayChannelName(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->getMockRequestWithUserForChannel(['private-test']));
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenCallbackReturnFalse(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function (): bool {
            return false;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenRequestUserNotFound(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function (): bool {
            return true;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('private-test')
        );
    }

    public function testAuthCallValidAuthenticationResponseWithPresenceChannelWhenCallbackReturnAnArray(): void
    {
        $returnData = [1, 2, 3, 4];
        $this->broadcaster->channel('test', function () use ($returnData): array {
            return $returnData;
        });

        $this->assertSame(
            json_encode([
                'channel_data' => [
                    'user_id' => 42,
                    'user_info' => $returnData,
                ],
            ]),
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('presence-test')
            ),
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenCallbackReturnNull(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function (): void {
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenRequestUserNotFound(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function (): array {
            return [1, 2, 3, 4];
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('presence-test')
        );
    }

    public function testAuthUsesRewrittenChannelForConfiguredGuardAndPresenceUser(): void
    {
        $broadcaster = new RedisBroadcaster($this->container, $this->redis, 'default', 'redis.');
        $user = Double::for(Authenticatable::class)->strict();
        $user->expects('getAuthIdentifier')->returns(42);

        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('input')
            ->with('channel_name')
            ->returns('redis.presence-application.tenant.orders.5');
        $request->expects('user')->times(3)->with('members')->returns($user);
        $request->expects('user')->never()->with(Argument::none());

        $calls = 0;
        Broadcaster::authorizeChannelsUsing(function (Request $request, string $channel) use (&$calls): ?string {
            ++$calls;

            return $channel === 'application.tenant.orders.5'
                ? 'application.orders.5'
                : null;
        });

        $broadcaster->channel(
            'application.orders.{order}',
            static fn (object $authenticatedUser, string $order): array|false => $authenticatedUser === $user && $order === '5'
                ? ['role' => 'viewer']
                : false,
            ['guards' => ['members']],
        );

        $this->assertSame(
            json_encode([
                'channel_data' => [
                    'user_id' => 42,
                    'user_info' => ['role' => 'viewer'],
                ],
            ]),
            $broadcaster->auth($request->instance()),
        );
        $this->assertSame(1, $calls);
    }

    public function testAuthDoesNotRemoveConfiguredPrefixFromTheMiddleOfAChannel(): void
    {
        $broadcaster = new RedisBroadcaster($this->container, $this->redis, 'default', 'redis.');
        $broadcaster->channel('orders.redis.audit', static fn (): bool => true);

        $this->assertSame(
            json_encode(true),
            $broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-orders.redis.audit')
            ),
        );
    }

    public function testAuthLeavesAChannelWithoutTheConfiguredPrefixUnchanged(): void
    {
        $broadcaster = new RedisBroadcaster($this->container, $this->redis, 'default', 'redis.');
        $broadcaster->channel('orders', static fn (): bool => true);

        $this->assertSame(
            json_encode(true),
            $broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-orders')
            ),
        );
    }

    public function testValidAuthenticationResponseWithPrivateChannel(): void
    {
        $request = $this->getMockRequestWithUserForChannel('private-test');

        $this->assertEquals(
            json_encode(true),
            $this->broadcaster->validAuthenticationResponse($request, true)
        );
    }

    public function testValidAuthenticationResponseWithPresenceChannel(): void
    {
        $request = $this->getMockRequestWithUserForChannel('presence-test');

        $this->assertEquals(
            json_encode([
                'channel_data' => [
                    'user_id' => 42,
                    'user_info' => [
                        'a' => 'b',
                        'c' => 'd',
                    ],
                ],
            ]),
            $this->broadcaster->validAuthenticationResponse($request, [
                'a' => 'b',
                'c' => 'd',
            ])
        );
    }

    public function testPresenceAuthenticationThrowsWhenUserDataCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        $this->broadcaster->validAuthenticationResponse(
            $this->getMockRequestWithUserForChannel('presence-test'),
            ['invalid' => NAN],
        );
    }

    public function testBroadcastUsesPublishPerChannelOnCluster(): void
    {
        $connection = Double::for(RedisBroadcasterConnection::class)->strict();
        $connection->expects('isCluster')->returns(true);
        $connection->expects('publish')->with('test-channel-1', Argument::type('string'));
        $connection->expects('publish')->with('test-channel-2', Argument::type('string'));
        $connection->expects('eval')->never();

        $this->redis->expects('connection')->returns($connection);

        $broadcaster = new RedisBroadcaster($this->container, $this->redis);
        $broadcaster->broadcast(['test-channel-1', 'test-channel-2'], 'test-event', ['data' => 'value']);
    }

    public function testClusterBroadcastWrapsRedisClusterException(): void
    {
        $connection = Double::for(RedisBroadcasterConnection::class)->strict();
        $connection->expects('isCluster')->returns(true);
        $connection->expects('publish')
            ->with('test-channel', Argument::type('string'))
            ->throws(new RedisClusterException('Cluster unavailable'));

        $this->redis->expects('connection')->returns($connection);

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessageIs('Redis error: Cluster unavailable.');

        (new RedisBroadcaster($this->container, $this->redis))
            ->broadcast(['test-channel'], 'test-event', ['data' => 'value']);
    }

    public function testBroadcastUsesEvalOnNonCluster(): void
    {
        $connection = Double::for(RedisBroadcasterConnection::class)->strict();
        $connection->expects('isCluster')->returns(false);
        $connection->expects('eval');
        $connection->expects('publish')->never();

        $this->redis->expects('connection')->returns($connection);

        $broadcaster = new RedisBroadcaster($this->container, $this->redis);
        $broadcaster->broadcast(['test-channel'], 'test-event', ['data' => 'value']);
    }

    public function testClusterBroadcastLeavesRedisPrefixToNativePublishAfterFormattingChannels(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        $connection = Double::for(RedisBroadcasterConnection::class)->strict();
        $connection->expects('isCluster')->returns(true);
        $connection->expects('publish')
            ->with('application.orders', Argument::type('string'));

        $this->redis->expects('connection')->returns($connection);

        (new RedisBroadcaster(
            $this->container,
            $this->redis,
            prefix: 'redis.',
        ))->broadcast(['orders'], 'OrderCreated');
    }

    public function testLuaBroadcastAddsRedisPrefixAfterFormattingChannels(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => array_map(
                static fn (mixed $channel): string => 'application.' . $channel,
                $channels,
            ),
        );

        $connection = Double::for(RedisBroadcasterConnection::class)->strict();
        $connection->expects('isCluster')->returns(false);
        $connection->expects('eval')
            ->with(
                Argument::type('string'),
                0,
                Argument::type('string'),
                'redis.application.orders',
            );

        $this->redis->expects('connection')->returns($connection);

        (new RedisBroadcaster(
            $this->container,
            $this->redis,
            prefix: 'redis.',
        ))->broadcast(['orders'], 'OrderCreated');
    }

    public function testBroadcastThrowsWhenPayloadCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        $this->redis->expects('connection')->returns(
            Double::for(RedisBroadcasterConnection::class)->strict()
        );

        $this->broadcaster->broadcast(
            ['test-channel'],
            'test-event',
            ['invalid' => NAN],
        );
    }

    public function testBroadcastPayloadDoesNotDuplicateSocketInData(): void
    {
        $connection = Double::for(RedisBroadcasterConnection::class)->strict();
        $connection->allows('isCluster')->returns(false);
        $connection->expects('eval')->with(Argument::all(function (string $script, int $numberOfKeys, string $payload): bool {
            $decoded = json_decode($payload, true);

            // socket should be at top level only, not inside data
            return $decoded['socket'] === 'test-socket'
                && ! isset($decoded['data']['socket']);
        }));

        $this->redis->allows('connection')->returns($connection);

        $broadcaster = new RedisBroadcaster($this->container, $this->redis);
        $broadcaster->broadcast(['test-channel'], 'test-event', ['message' => 'hello', 'socket' => 'test-socket']);
    }

    /**
     * Create a channel request with an authenticated user.
     */
    protected function getMockRequestWithUserForChannel(string|array $channel): Request
    {
        $request = Request::create('/', 'POST', ['channel_name' => $channel]);

        $user = new class {
            /**
             * Get the user's broadcast identifier.
             */
            public function getAuthIdentifierForBroadcasting(): int
            {
                return 42;
            }

            /**
             * Get the user's authentication identifier.
             */
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        };

        $request->setUserResolver(fn () => $user);

        return $request;
    }

    /**
     * Create a channel request without an authenticated user.
     */
    protected function getMockRequestWithoutUserForChannel(string $channel): Request
    {
        return Request::create('/', 'POST', ['channel_name' => $channel]);
    }
}
