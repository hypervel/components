<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Ably\PubSub\Exceptions\AblyRequestException;
use Ably\PubSub\Http;
use Ably\PubSub\PubSubHttpClient;
use Ably\PubSub\Server;
use Ably\PubSub\Utils\Crypto;
use Hypervel\Broadcasting\Broadcasters\Ably2Broadcaster;
use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\BindingRegistrar;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class Ably2BroadcasterTest extends TestCase
{
    public Ably2Broadcaster $broadcaster;

    public PubSubHttpClient $ably;

    protected Container $container;

    /**
     * Set up the broadcaster and its dependencies.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(PubSubHttpClient::class)) {
            $this->markTestSkipped(sprintf('%s class is not available.', PubSubHttpClient::class));
        }

        $this->container = m::mock(Container::class);
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnFalse()->byDefault();
        $this->ably = m::mock(PubSubHttpClient::class, ['abcd:efgh']);

        $this->broadcaster = m::mock(Ably2Broadcaster::class, [$this->container, $this->ably])->makePartial();
    }

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue(): void
    {
        $this->broadcaster->channel('test', function (): bool {
            return true;
        });

        $this->broadcaster->expects('generateAblySignature')
            ->with('private-test', 'abcd.1234')
            ->andReturn('signature');

        $this->assertSame(
            ['auth' => 'abcd:signature'],
            $this->broadcaster->auth($this->getMockRequestWithUserForChannel('private-test')),
        );
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

        $this->broadcaster->expects('generateAblySignature')
            ->with('presence-test', 'abcd.1234', ['user_id' => '42', 'user_info' => $returnData])
            ->andReturn('signature');

        $this->assertSame(
            [
                'auth' => 'abcd:signature',
                'channel_data' => json_encode(['user_id' => '42', 'user_info' => $returnData]),
            ],
            $this->broadcaster->auth($this->getMockRequestWithUserForChannel('presence-test')),
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

    public function testBroadcastReleasesOrdinaryChannelsAndRetainsConfiguredCipherChannels(): void
    {
        $ably = $this->createAbly();
        $broadcaster = new Ably2Broadcaster($this->container, $ably);
        $ordinary = $ably->channels->get('public:orders');
        $encrypted = $ably->channels->get('private:orders', [
            'cipher' => ['key' => Crypto::generateRandomKey(128)],
        ]);

        $broadcaster->broadcast(['orders', 'private-orders'], 'OrderCreated');

        $this->assertNotSame($ordinary, $ably->channels->get('public:orders'));
        $this->assertSame($encrypted, $ably->channels->get('private:orders'));
        $this->assertNotNull($encrypted->getCipherParams());
        $this->assertSame(2, $ably->http->requestCount);
    }

    public function testBroadcastWrapsSdkExceptionsAndReleasesTheChannel(): void
    {
        $ably = $this->createAbly();
        $broadcaster = new Ably2Broadcaster($this->container, $ably);
        $channel = $ably->channels->get('public:orders');
        $ably->http->fail = true;

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('Ably error: Publication failed.');

        try {
            $broadcaster->broadcast(['orders'], 'OrderCreated');
        } finally {
            $this->assertNotSame($channel, $ably->channels->get('public:orders'));
        }
    }

    /**
     * Create a real Ably client with an in-memory HTTP transport.
     */
    protected function createAbly(): PubSubHttpClient
    {
        return Server::createHttpClient([
            'key' => 'abcd:efgh',
            'httpClass' => BroadcastingAbly2HttpFake::class,
            'idempotentRestPublishing' => false,
        ]);
    }

    /**
     * Create an authenticated channel request.
     */
    protected function getMockRequestWithUserForChannel(string $channel): Request
    {
        $request = new Request(['channel_name' => $channel, 'socket_id' => 'abcd.1234']);

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
        $request = new Request(['channel_name' => $channel]);

        $request->setUserResolver(fn () => null);

        return $request;
    }
}

class BroadcastingAbly2HttpFake extends Http
{
    public bool $fail = false;

    public int $requestCount = 0;

    /**
     * Record a publication without sending an HTTP request.
     */
    public function request(mixed $method, mixed $url, mixed $headers = [], mixed $params = []): array
    {
        ++$this->requestCount;

        if ($this->fail) {
            throw new AblyRequestException('Publication failed.', 40000, 400);
        }

        return [
            'headers' => "HTTP/1.1 200 OK\n",
            'body' => [],
        ];
    }
}
