<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Reverb\Connection;
use Hypervel\Reverb\Contracts\WebSocketConnection;
use Hypervel\Reverb\Events\ConnectionClosed;
use Hypervel\Reverb\Events\ConnectionEstablished;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\EventHandler;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Server;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ServerTest extends ReverbTestCase
{
    protected Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->app->make(Server::class);
    }

    public function testCanHandleAConnection(): void
    {
        $this->server->open($connection = new FakeConnection);

        $this->assertNotNull($connection->lastSeenAt());
        $this->assertTrue($connection->isEstablished());

        $connection->assertReceived([
            'event' => 'pusher:connection_established',
            'data' => json_encode([
                'socket_id' => $connection->id(),
                'activity_timeout' => 30,
            ]),
        ]);
    }

    public function testCanHandleADisconnection(): void
    {
        $scopedManager = m::spy(ScopedChannelManager::class);

        $channelManager = m::mock(ChannelManager::class);
        $channelManager->shouldReceive('for')->andReturn($scopedManager);

        $this->app->singleton(ChannelManager::class, fn () => $channelManager);
        $this->app->forgetInstance(Server::class);
        $server = $this->app->make(Server::class);

        $server->open($connection = new FakeConnection);
        $server->close($connection);

        $scopedManager->shouldHaveReceived('unsubscribeFromAll');
    }

    public function testCanHandleANewMessage(): void
    {
        $this->server->open($connection = new FakeConnection);
        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'test-channel',
                    'auth' => '123',
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:connection_established',
            'data' => json_encode([
                'socket_id' => $connection->id(),
                'activity_timeout' => 30,
            ]),
        ]);

        $connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
            'channel' => 'test-channel',
        ]);
    }

    public function testSendsAnErrorIfSomethingFails(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            'Hi'
        );

        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'private-test-channel',
                    'auth' => '123',
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4009,
                'message' => 'Connection is unauthorized',
            ]),
        ]);
    }

    public function testRejectsScalarSubscriptionDataBeforePublishingSharedMembership(): void
    {
        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->shouldNotReceive('report');
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'public-channel',
                    'channel_data' => '1',
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);

        $this->assertSame(
            0,
            $this->app->make(SharedState::class)->getSubscriptionCount('123456', 'public-channel'),
        );
        $this->assertNull($this->channels()->find('public-channel'));
    }

    public function testReportsUnexpectedMessageFailuresWithoutChangingTheClientPayload(): void
    {
        $exception = new RuntimeException('Internal handler failure');
        $handler = m::mock(EventHandler::class);
        $handler->shouldReceive('handle')->once()->andThrow($exception);

        $exceptionHandler = m::mock(ExceptionHandler::class);
        $exceptionHandler->shouldReceive('report')->once()->with($exception);
        $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        $server = new Server($this->app->make(ChannelManager::class), $handler);
        $server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:ping',
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);
    }

    public function testCanSubscribeAUserToAChannel(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'test-channel',
                    'auth' => '',
                ],
            ])
        );

        $this->assertNotNull($connection->lastSeenAt());

        $connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
            'channel' => 'test-channel',
        ]);
    }

    public function testCanSubscribeAUserToAPrivateChannel(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'private-test-channel',
                    'auth' => 'app-key:' . hash_hmac('sha256', $connection->id() . ':private-test-channel', 'reverb-secret'),
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
            'channel' => 'private-test-channel',
        ]);
    }

    public function testCanSubscribeAUserToAPresenceChannel(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'presence-test-channel',
                    'auth' => 'app-key:' . hash_hmac('sha256', $connection->id() . ':presence-test-channel', 'reverb-secret'),
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => json_encode([
                'presence' => [
                    'count' => 0,
                    'ids' => [],
                    'hash' => [],
                ],
            ]),
            'channel' => 'presence-test-channel',
        ]);
    }

    public function testReceivesNoDataWhenNoPreviousEventTriggeredWhenJoiningACacheChannel(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'cache-test-channel',
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
            'channel' => 'cache-test-channel',
        ]);
        $connection->assertReceived([
            'event' => 'pusher:cache_miss',
            'channel' => 'cache-test-channel',
        ]);
        $connection->assertReceivedCount(2);
    }

    public function testReceivesLastTriggeredEventWhenJoiningACacheChannel(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'cache-test-channel',
                ],
            ])
        );

        $channel = $this->channels()->find('cache-test-channel');

        $channel->broadcast(['foo' => 'bar']);

        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'cache-test-channel',
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher_internal:subscription_succeeded',
            'data' => '{}',
            'channel' => 'cache-test-channel',
        ]);
        $connection->assertReceived(['foo' => 'bar']);
        $connection->assertReceivedCount(2);
    }

    public function testUnsubscribesAUserFromAChannelOnDisconnection(): void
    {
        $this->server->open($connection = new FakeConnection);
        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'test-channel'],
            ])
        );

        $this->assertCount(1, $this->channels()->find('test-channel')->connections());

        $this->server->close($connection);

        $this->assertNull($this->channels()->find('test-channel'));
    }

    public function testUnsubscribesAUserFromAPrivateChannelOnDisconnection(): void
    {
        $connection = new FakeConnection;
        $this->server->open($connection);
        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'private-test-channel',
                    'auth' => static::validAuth($connection->id(), 'private-test-channel'),
                ],
            ])
        );

        $this->assertCount(1, $this->channels()->find('private-test-channel')->connections());

        $this->server->close($connection);

        $this->assertNull($this->channels()->find('private-test-channel'));
    }

    public function testUnsubscribesAUserFromAPresenceChannelOnDisconnection(): void
    {
        $connection = new FakeConnection;
        $this->server->open($connection);
        $data = json_encode(['user_id' => 1, 'user_info' => ['name' => 'Test']]);
        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'presence-test-channel',
                    'auth' => static::validAuth($connection->id(), 'presence-test-channel', $data),
                    'channel_data' => $data,
                ],
            ])
        );

        $this->assertCount(1, $this->channels()->find('presence-test-channel')->connections());

        $this->server->close($connection);

        $this->assertNull($this->channels()->find('presence-test-channel'));
    }

    #[DataProvider('invalidOriginProvider')]
    public function testRejectsAConnectionFromAnInvalidOrigin(string $origin, array $allowedOrigins): void
    {
        $this->app['config']->set('reverb.apps.apps.0.allowed_origins', $allowedOrigins);
        $this->server->open($connection = new FakeConnection(origin: $origin));

        $this->assertFalse($connection->isEstablished());
        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4009,
                'message' => 'Origin not allowed',
            ]),
        ]);
    }

    public static function invalidOriginProvider(): array
    {
        return [
            'localhost' => ['http://localhost', ['laravel.com']],
            'subdomain' => ['http://sub.laravel.com', ['laravel.com']],
            'wildcard' => ['http://laravel.com', ['*.laravel.com']],
        ];
    }

    public function testRejectsAConnectionWithoutAnOrigin(): void
    {
        $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['localhost']);

        $webSocket = m::mock(WebSocketConnection::class);
        $webSocket->shouldReceive('send')->once();
        $application = $this->app->make(\Hypervel\Reverb\Contracts\ApplicationProvider::class)
            ->findByKey('reverb-key');
        $connection = new Connection($webSocket, $application, null);

        $this->server->open($connection);

        $this->assertFalse($connection->isEstablished());
    }

    #[DataProvider('validOriginProvider')]
    public function testAcceptsAConnectionFromAValidOrigin(string $origin, array $allowedOrigins): void
    {
        $this->app['config']->set('reverb.apps.apps.0.allowed_origins', $allowedOrigins);
        $this->server->open($connection = new FakeConnection(origin: $origin));

        $this->assertTrue($connection->isEstablished());
        $connection->assertReceived([
            'event' => 'pusher:connection_established',
            'data' => json_encode([
                'socket_id' => $connection->id(),
                'activity_timeout' => 30,
            ]),
        ]);
    }

    public static function validOriginProvider(): array
    {
        return [
            'localhost' => ['http://localhost', ['localhost']],
            'wildcard' => ['http://sub.localhost', ['localhost', '*.localhost']],
        ];
    }

    public function testRejectsAConnectionWhenTheAppIsOverTheConnectionLimit(): void
    {
        $this->app['config']->set('reverb.apps.apps.0.max_connections', 1);
        $this->server->open($connection = new FakeConnection);
        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'my-channel',
                ],
            ])
        );
        $this->server->open($connectionTwo = new FakeConnection);

        $this->assertTrue($connection->isEstablished());
        $this->assertFalse($connectionTwo->isEstablished());
        $connectionTwo->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4004,
                'message' => 'Application is over connection quota',
            ]),
        ]);
    }

    public function testSendsAnErrorIfSomethingFailsForEventType(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => [],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);
    }

    public function testSendsAnErrorIfSomethingFailsForDataType(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => 'sfsfsfs',
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);
    }

    public function testSendsAnErrorIfSomethingFailsForDataChannelType(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => []],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);

        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => null],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);
    }

    public function testSendsAnErrorIfSomethingFailsForDataAuthType(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'presence-test-channel',
                    'auth' => [],
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);
    }

    public function testSendsAnErrorIfSomethingFailsForDataChannelDataType(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'presence-test-channel',
                    'auth' => '',
                    'channel_data' => [],
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);

        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => [
                    'channel' => 'presence-test-channel',
                    'auth' => '',
                    'channel_data' => 'Hello',
                ],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);
    }

    public function testSendsAnErrorIfSomethingFailsForChannelType(): void
    {
        $this->server->message(
            $connection = new FakeConnection,
            json_encode([
                'event' => 'client-start-typing',
                'channel' => [],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]);
    }

    public function testRejectsAMessageWhenTheRateLimitIsExceeded(): void
    {
        $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
            'enabled' => true,
            'max_attempts' => 3,
            'decay_seconds' => 1,
            'terminate_on_limit' => false,
        ]);

        $this->server->open($connection = new FakeConnection);

        for ($i = 0; $i < 3; ++$i) {
            $this->server->message(
                $connection,
                json_encode([
                    'event' => 'pusher:subscribe',
                    'data' => ['channel' => 'test-channel-' . $i],
                ])
            );
        }

        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'test-channel-overflow'],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4301,
                'message' => 'Rate limit exceeded',
            ]),
        ]);

        $this->assertFalse($connection->wasTerminated);
    }

    public function testMessageRateLimiterUsesWorkerLifetimeCacheStore(): void
    {
        $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
            'enabled' => true,
            'max_attempts' => 1,
            'decay_seconds' => 60,
            'terminate_on_limit' => false,
        ]);

        $this->server->open($connection = new FakeConnection);

        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'test-channel'],
            ])
        );

        $this->assertTrue(
            $this->app->make('cache')->store('worker-array')->has('reverb:message:' . $connection->id())
        );
        $this->assertFalse(
            $this->app->make('cache')->store('array')->has('reverb:message:' . $connection->id())
        );

        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'test-channel-overflow'],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4301,
                'message' => 'Rate limit exceeded',
            ]),
        ]);

        $this->assertFalse($connection->wasTerminated);
    }

    public function testCloseClearsInitializedMessageRateLimiterState(): void
    {
        $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
            'enabled' => true,
            'max_attempts' => 1,
            'decay_seconds' => 60,
            'terminate_on_limit' => false,
        ]);

        $this->server->open($connection = new FakeConnection);
        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'test-channel'],
            ])
        );

        $cache = $this->app->make('cache')->store('worker-array');
        $key = 'reverb:message:' . $connection->id();

        $this->assertTrue($connection->hasInitializedRateLimiter());
        $this->assertTrue($cache->has($key));

        $this->server->close($connection);

        $this->assertFalse($connection->hasInitializedRateLimiter());
        $this->assertFalse($cache->has($key));
    }

    public function testTerminatesTheConnectionWhenRateLimitIsExceededAndConfiguredToTerminate(): void
    {
        $this->app['config']->set('reverb.apps.apps.0.rate_limiting', [
            'enabled' => true,
            'max_attempts' => 1,
            'decay_seconds' => 1,
            'terminate_on_limit' => true,
        ]);

        $this->server->open($connection = new FakeConnection);

        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'test-channel'],
            ])
        );

        $this->server->message(
            $connection,
            json_encode([
                'event' => 'pusher:subscribe',
                'data' => ['channel' => 'test-channel-2'],
            ])
        );

        $connection->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4301,
                'message' => 'Rate limit exceeded',
            ]),
        ]);

        $this->assertTrue($connection->wasTerminated);
    }

    public function testAllowsUnlimitedMessagesWhenNoRateLimitIsConfigured(): void
    {
        $this->server->open($connection = new FakeConnection);

        for ($i = 0; $i < 10; ++$i) {
            $this->server->message(
                $connection,
                json_encode([
                    'event' => 'pusher:subscribe',
                    'data' => ['channel' => 'test-channel-' . $i],
                ])
            );
        }

        $connection->assertReceivedCount(11);
    }

    public function testAllowReceivingClientEventWithEmptyData(): void
    {
        $channel = $this->channels()->findOrCreate('private-chat.1');

        $connection = collect(static::factory(data: ['user_info' => ['name' => 'Joe'], 'user_id' => 1]))->first();
        $channel->subscribe(
            $connection->connection(),
            static::validAuth($connection->id(), 'private-chat.1', $data = json_encode($connection->data())),
            $data
        );

        $this->server->message(
            $connection->connection(),
            json_encode([
                'event' => 'client-start-typing',
                'channel' => 'private-chat.1',
            ])
        );

        $connection->connection()->assertNothingReceived();
    }

    public function testCloseDoesNotTerminateTheConnection(): void
    {
        $scopedManager = m::spy(ScopedChannelManager::class);

        $channelManager = m::mock(ChannelManager::class);
        $channelManager->shouldReceive('for')->andReturn($scopedManager);

        $this->app->singleton(ChannelManager::class, fn () => $channelManager);
        $this->app->forgetInstance(Server::class);
        $server = $this->app->make(Server::class);

        $connection = new FakeConnection;
        $server->open($connection);
        $server->close($connection);

        // close() is the "client already disconnected" cleanup path.
        // It should NOT try to terminate/disconnect the connection again —
        // the fd is already gone.
        $this->assertFalse($connection->wasTerminated);
    }

    public function testCloseSetsDisconnectingFlag(): void
    {
        $scopedManager = m::spy(ScopedChannelManager::class);

        $channelManager = m::mock(ChannelManager::class);
        $channelManager->shouldReceive('for')->andReturn($scopedManager);

        $this->app->singleton(ChannelManager::class, fn () => $channelManager);
        $this->app->forgetInstance(Server::class);
        $server = $this->app->make(Server::class);

        $connection = new FakeConnection;
        $this->assertFalse($connection->isDisconnecting());

        $server->open($connection);
        $server->close($connection);

        $this->assertTrue($connection->isDisconnecting());
    }

    public function testConnectionEstablishedEventIsDispatched(): void
    {
        Event::fake();

        $this->server->open($connection = new FakeConnection);

        Event::assertDispatched(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($connection) {
            return $event->connection === $connection;
        });
    }

    public function testConnectionEstablishedEventNotDispatchedOnFailure(): void
    {
        Event::fake();

        $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['laravel.com']);
        $this->server->open(new FakeConnection(origin: 'http://localhost'));

        Event::assertNotDispatched(ConnectionEstablished::class);
    }

    public function testConnectionClosedEventIsDispatched(): void
    {
        Event::fake();

        $connection = new FakeConnection;
        $this->server->open($connection);
        $this->server->close($connection);

        Event::assertDispatched(ConnectionClosed::class, function (ConnectionClosed $event) use ($connection) {
            return $event->connection === $connection;
        });
    }
}
