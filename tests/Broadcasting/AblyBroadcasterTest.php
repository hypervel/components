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
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JsonException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AblyBroadcasterTest extends TestCase
{
    use VerifiesDoubles;

    protected AblyBroadcaster $broadcaster;

    protected AblyRest $ably;

    protected Container $container;

    /**
     * Set up the broadcaster and its dependencies.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(AblyRest::class)) {
            $this->markTestSkipped(sprintf('%s class is not available.', AblyRest::class));
        }

        $this->container = new Container;
        $this->ably = new AblyRest('abcd:efg');
        $this->broadcaster = Double::for(AblyBroadcaster::class)->passthru(new AblyBroadcaster($this->container, $this->ably));
    }

    public function testAuthCallValidAuthenticationResponseWithPrivateChannelWhenCallbackReturnTrue(): void
    {
        $this->broadcaster->channel('test', function (): bool {
            return true;
        });

        $this->broadcaster->expects('generateAblySignature')
            ->with('private-test', 'abcd.1234')
            ->returns('signature');

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

        $this->broadcaster->channel('test', function (): bool {
            return false;
        });

        $this->broadcaster->auth(
            $this->getMockRequestWithUserForChannel('private-test')
        );
    }

    public function testAuthRejectsArrayChannelName(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->getMockRequestWithUserForChannel(['private-test']));
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
            ->with(
                'presence-test',
                'abcd.1234',
                ['user_id' => '42', 'user_info' => $returnData],
            )
            ->returns('signature');

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

    public function testAuthUsesRewrittenChannelForConfiguredGuardAndOriginalNameForPresenceSignature(): void
    {
        $wireChannel = 'presence-application.tenant.orders.5';
        $user = Double::for(Authenticatable::class)->strict();
        $user->expects('getAuthIdentifier')->returns(42);

        $request = Double::for(Request::class, override: true)->strict();
        $request->allows('input')->with('channel_name')->returns($wireChannel);
        $request->allows('input')->with('socket_id')->returns('abcd.1234');
        $request->expects('user')->times(3)->with('members')->returns($user);
        $request->expects('user')->never()->with(Argument::none());

        $calls = 0;
        Broadcaster::authorizeChannelsUsing(function (Request $request, string $channel) use (&$calls): ?string {
            ++$calls;

            return $channel === 'application.tenant.orders.5'
                ? 'application.orders.5'
                : null;
        });

        $this->broadcaster->channel(
            'application.orders.{order}',
            static fn (object $authenticatedUser, string $order): array|false => $authenticatedUser === $user && $order === '5'
                ? ['role' => 'viewer']
                : false,
            ['guards' => ['members']],
        );

        $this->broadcaster->expects('generateAblySignature')
            ->with(
                $wireChannel,
                'abcd.1234',
                ['user_id' => '42', 'user_info' => ['role' => 'viewer']],
            )
            ->returns('signature');

        $this->assertSame(
            [
                'auth' => 'abcd:signature',
                'channel_data' => json_encode([
                    'user_id' => '42',
                    'user_info' => ['role' => 'viewer'],
                ]),
            ],
            $this->broadcaster->auth($request->instance()),
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

        $this->broadcaster->expects('generateAblySignature')
            ->returns('signature');

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

    /**
     * Create an authenticated channel request.
     */
    protected function getMockRequestWithUserForChannel(string|array $channel): Request
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

class InspectableAblyBroadcaster extends AblyBroadcaster
{
    /**
     * Format outgoing channel names for inspection.
     */
    public function formatOutgoingChannels(array $channels): array
    {
        return parent::formatChannels($channels);
    }
}

class BroadcastingAblyHttpFake extends Http
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
