<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Ably\AblyRest;
use Ably\Exceptions\AblyRequestException;
use Ably\Http;
use Ably\Utils\Crypto;
use Hypervel\Broadcasting\Broadcasters\AblyBroadcaster;
use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\BindingRegistrar;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use JsonException;
use Mockery as m;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AblyBroadcasterTest extends TestCase
{
    protected AblyBroadcaster $broadcaster;

    protected AblyRest $ably;

    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(Container::class);
        $this->container->shouldReceive('bound')->with(BindingRegistrar::class)->andReturnFalse()->byDefault();
        $this->ably = m::mock(AblyRest::class, ['abcd:efg']);
        $this->broadcaster = m::mock(AblyBroadcaster::class, [$this->container, $this->ably])->makePartial();
    }

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue(): void
    {
        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->broadcaster->shouldReceive('generateAblySignature')
            ->once()
            ->with('private-test', 'abcd.1234')
            ->andReturn('signature');

        $this->assertSame(
            ['auth' => 'abcd:signature'],
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('private-test')
            ),
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenCallbackReturnFalse(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return false;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPrivateChannelWhenRequestUserNotFound(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return true;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('private-test')
        );
    }

    public function testAuthCallValidAuthenticationResponseWithPresenceChannelWhenCallbackReturnAnArray(): void
    {
        $returnData = [1, 2, 3, 4];
        $this->broadcaster->channel('test', function () use ($returnData) {
            return $returnData;
        });

        $this->broadcaster->shouldReceive('generateAblySignature')
            ->once()
            ->with(
                'presence-test',
                'abcd.1234',
                ['user_id' => '42', 'user_info' => $returnData],
            )
            ->andReturn('signature');

        $this->assertSame(
            [
                'auth' => 'abcd:signature',
                'channel_data' => json_encode([
                    'user_id' => '42',
                    'user_info' => $returnData,
                ]),
            ],
            $this->broadcaster->auth(
                $this->getMockRequestWithUserForChannel('presence-test')
            ),
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenCallbackReturnNull(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('presence-test')
        );
    }

    public function testAuthThrowAccessDeniedHttpExceptionWithPresenceChannelWhenRequestUserNotFound(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->channel('test', function () {
            return [1, 2, 3, 4];
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithoutUserForChannel('presence-test')
        );
    }

    public function testAuthUsesRewrittenChannelForConfiguredGuardAndOriginalNameForPresenceSignature(): void
    {
        $wireChannel = 'presence-application.tenant.orders.5';
        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn(42);

        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($wireChannel);
        $request->shouldReceive('input')->with('socket_id')->andReturn('abcd.1234');
        $request->shouldReceive('user')->times(3)->with('members')->andReturn($user);
        $request->shouldNotReceive('user')->withNoArgs();

        $calls = 0;
        Broadcaster::authorizeChannelsUsing(function (Request $request, string $channel) use (&$calls): ?string {
            ++$calls;

            return $channel === 'application.tenant.orders.5'
                ? 'application.orders.5'
                : null;
        });

        $this->broadcaster->channel(
            'application.orders.{order}',
            static fn ($authenticatedUser, string $order): array|false => $authenticatedUser === $user && $order === '5'
                ? ['role' => 'viewer']
                : false,
            ['guards' => ['members']],
        );

        $this->broadcaster->shouldReceive('generateAblySignature')
            ->once()
            ->with(
                $wireChannel,
                'abcd.1234',
                ['user_id' => '42', 'user_info' => ['role' => 'viewer']],
            )
            ->andReturn('signature');

        $this->assertSame(
            [
                'auth' => 'abcd:signature',
                'channel_data' => json_encode([
                    'user_id' => '42',
                    'user_info' => ['role' => 'viewer'],
                ]),
            ],
            $this->broadcaster->auth($request),
        );
        $this->assertSame(1, $calls);
    }

    public function testFormatsChannelsBeforeApplyingAblyNamespaces(): void
    {
        Broadcaster::formatChannelsUsing(
            static fn (array $channels): array => [
                'private-' . $channels[0],
                'presence-' . $channels[1],
                $channels[2],
            ],
        );

        $broadcaster = new InspectableAblyBroadcaster($this->container, $this->ably);

        $this->assertSame(
            ['private:orders', 'presence:users', 'public:status'],
            $broadcaster->formatOutgoingChannels(['orders', 'users', 'status']),
        );
    }

    public function testGenerateSignatureThrowsWhenUserDataCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        $this->broadcaster->generateAblySignature(
            'presence-test',
            'abcd.1234',
            ['invalid' => NAN],
        );
    }

    public function testPresenceAuthenticationThrowsWhenChannelDataCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        $this->broadcaster->shouldReceive('generateAblySignature')
            ->once()
            ->andReturn('signature');

        $this->broadcaster->validAuthenticationResponse(
            $this->getMockRequestWithUserForChannel('presence-test'),
            ['invalid' => NAN],
        );
    }

    public function testBroadcastReleasesOrdinaryChannelsAfterEveryPublication(): void
    {
        $ably = $this->createAbly();
        $broadcaster = new AblyBroadcaster($this->container, $ably);
        $channel = $ably->channels->get('public:orders');

        for ($publication = 0; $publication < 3; ++$publication) {
            $broadcaster->broadcast(['orders'], 'OrderCreated');

            $replacement = $ably->channels->get('public:orders');
            $this->assertNotSame($channel, $replacement);
            $channel = $replacement;
        }

        /** @var BroadcastingAblyHttpFake $http */
        $http = $ably->http;
        $this->assertSame(3, $http->requestCount);
    }

    public function testBroadcastReleasesOrdinaryChannelWhenPublicationFails(): void
    {
        $ably = $this->createAbly();
        $broadcaster = new AblyBroadcaster($this->container, $ably);
        $channel = $ably->channels->get('public:orders');

        /** @var BroadcastingAblyHttpFake $http */
        $http = $ably->http;
        $http->fail = true;

        try {
            $broadcaster->broadcast(['orders'], 'OrderCreated');
            $this->fail('Expected publication to fail.');
        } catch (BroadcastException $exception) {
            $this->assertSame('Ably error: Publication failed.', $exception->getMessage());
        }

        $this->assertNotSame($channel, $ably->channels->get('public:orders'));
    }

    public function testBroadcastRetainsConfiguredEncryptedChannel(): void
    {
        $ably = $this->createAbly();
        $broadcaster = new AblyBroadcaster($this->container, $ably);
        $channel = $ably->channels->get('private:orders', [
            'cipher' => ['key' => Crypto::generateRandomKey(128)],
        ]);

        $broadcaster->broadcast(['private-orders'], 'OrderCreated');

        $this->assertSame($channel, $ably->channels->get('private:orders'));
        $this->assertNotNull($channel->getCipherParams());
    }

    /**
     * Create a real Ably client with an in-memory HTTP transport.
     */
    protected function createAbly(): AblyRest
    {
        return new AblyRest([
            'key' => 'abcd:efg',
            'httpClass' => BroadcastingAblyHttpFake::class,
            'idempotentRestPublishing' => false,
        ]);
    }

    protected function getMockRequestWithUserForChannel(string $channel): Request
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($channel);
        $request->shouldReceive('input')->with('socket_id')->andReturn('abcd.1234');

        $user = m::mock('User');
        $user->shouldReceive('getAuthIdentifierForBroadcasting')->andReturn(42);
        $user->shouldReceive('getAuthIdentifier')->andReturn(42);

        $request->shouldReceive('user')->andReturn($user);

        return $request;
    }

    protected function getMockRequestWithoutUserForChannel(string $channel): Request
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('input')->with('channel_name')->andReturn($channel);

        $request->shouldReceive('user')->andReturn(null);

        return $request;
    }
}

class InspectableAblyBroadcaster extends AblyBroadcaster
{
    public function formatOutgoingChannels(array $channels): array
    {
        return parent::formatChannels($channels);
    }
}

class BroadcastingAblyHttpFake extends Http
{
    public bool $fail = false;

    public int $requestCount = 0;

    public function request($method, $url, $headers = [], $params = []): array
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
