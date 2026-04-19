<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Events\ConnectionClosed;
use Hypervel\Reverb\Events\ConnectionEstablished;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Managers\ScopedChannelManager;
use Hypervel\Reverb\Protocols\Pusher\Server;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Reverb\Fixtures\FakeConnection;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class ServerTest extends ReverbTestCase
{
    protected Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = $this->app->make(Server::class);
    }

    public function testCanHandleAConnection()
    {
        $this->server->open($connection = new FakeConnection);

        $this->assertNotNull($connection->lastSeenAt());

        $connection->assertReceived([
            'event' => 'pusher:connection_established',
            'data' => json_encode([
                'socket_id' => $connection->id(),
                'activity_timeout' => 30,
            ]),
        ]);
    }

    public function testCanHandleADisconnection()
    {
        $scopedManager = m::spy(ScopedChannelManager::class);

        $channelManager = m::mock(ChannelManager::class);
        $channelManager->shouldReceive('for')->andReturn($scopedManager);

        $this->app->singleton(ChannelManager::class, fn () => $channelManager);
        $this->app->forgetInstance(Server::class);
        $server = $this->app->make(Server::class);

        $server->close(new FakeConnection);

        $scopedManager->shouldHaveReceived('unsubscribeFromAll');
    }

    public function testCanHandleANewMessage()
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

    public function testSendsAnErrorIfSomethingFails()
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

    public function testCanSubscribeAUserToAChannel()
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

    public function testCanSubscribeAUserToAPrivateChannel()
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

    public function testCanSubscribeAUserToAPresenceChannel()
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

    public function testReceivesNoDataWhenNoPreviousEventTriggeredWhenJoiningACacheChannel()
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

    public function testReceivesLastTriggeredEventWhenJoiningACacheChannel()
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

    public function testUnsubscribesAUserFromAChannelOnDisconnection()
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

    public function testUnsubscribesAUserFromAPrivateChannelOnDisconnection()
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

    public function testUnsubscribesAUserFromAPresenceChannelOnDisconnection()
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
    public function testRejectsAConnectionFromAnInvalidOrigin(string $origin, array $allowedOrigins)
    {
        $this->app['config']->set('reverb.apps.apps.0.allowed_origins', $allowedOrigins);
        $this->server->open($connection = new FakeConnection(origin: $origin));

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

    #[DataProvider('validOriginProvider')]
    public function testAcceptsAConnectionFromAValidOrigin(string $origin, array $allowedOrigins)
    {
        $this->app['config']->set('reverb.apps.apps.0.allowed_origins', $allowedOrigins);
        $this->server->open($connection = new FakeConnection(origin: $origin));

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

    public function testRejectsAConnectionWhenTheAppIsOverTheConnectionLimit()
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

        $connectionTwo->assertReceived([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4004,
                'message' => 'Application is over connection quota',
            ]),
        ]);
    }

    public function testSendsAnErrorIfSomethingFailsForEventType()
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

    public function testSendsAnErrorIfSomethingFailsForDataType()
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

    public function testSendsAnErrorIfSomethingFailsForDataChannelType()
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

    public function testSendsAnErrorIfSomethingFailsForDataAuthType()
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

    public function testSendsAnErrorIfSomethingFailsForDataChannelDataType()
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

    public function testSendsAnErrorIfSomethingFailsForChannelType()
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

    public function testRejectsAMessageWhenTheRateLimitIsExceeded()
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

    public function testTerminatesTheConnectionWhenRateLimitIsExceededAndConfiguredToTerminate()
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

    public function testAllowsUnlimitedMessagesWhenNoRateLimitIsConfigured()
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

    public function testAllowReceivingClientEventWithEmptyData()
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

    public function testCloseDoesNotTerminateTheConnection()
    {
        $scopedManager = m::spy(ScopedChannelManager::class);

        $channelManager = m::mock(ChannelManager::class);
        $channelManager->shouldReceive('for')->andReturn($scopedManager);

        $this->app->singleton(ChannelManager::class, fn () => $channelManager);
        $this->app->forgetInstance(Server::class);
        $server = $this->app->make(Server::class);

        $connection = new FakeConnection;
        $server->close($connection);

        // close() is the "client already disconnected" cleanup path.
        // It should NOT try to terminate/disconnect the connection again —
        // the fd is already gone.
        $this->assertFalse($connection->wasTerminated);
    }

    public function testCloseSetsDisconnectingFlag()
    {
        $scopedManager = m::spy(ScopedChannelManager::class);

        $channelManager = m::mock(ChannelManager::class);
        $channelManager->shouldReceive('for')->andReturn($scopedManager);

        $this->app->singleton(ChannelManager::class, fn () => $channelManager);
        $this->app->forgetInstance(Server::class);
        $server = $this->app->make(Server::class);

        $connection = new FakeConnection;
        $this->assertFalse($connection->isDisconnecting());

        $server->close($connection);

        $this->assertTrue($connection->isDisconnecting());
    }

    public function testConnectionEstablishedEventIsDispatched()
    {
        Event::fake();

        $this->server->open($connection = new FakeConnection);

        Event::assertDispatched(ConnectionEstablished::class, function (ConnectionEstablished $event) use ($connection) {
            return $event->connection === $connection;
        });
    }

    public function testConnectionEstablishedEventNotDispatchedOnFailure()
    {
        Event::fake();

        $this->app['config']->set('reverb.apps.apps.0.allowed_origins', ['laravel.com']);
        $this->server->open(new FakeConnection(origin: 'http://localhost'));

        Event::assertNotDispatched(ConnectionEstablished::class);
    }

    public function testConnectionClosedEventIsDispatched()
    {
        Event::fake();

        $connection = new FakeConnection;
        $this->server->close($connection);

        Event::assertDispatched(ConnectionClosed::class, function (ConnectionClosed $event) use ($connection) {
            return $event->connection === $connection;
        });
    }
}
