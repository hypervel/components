<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use ArrayObject;
use Hypervel\Broadcasting\Broadcasters\Broadcaster;
use Hypervel\Broadcasting\Broadcasters\MercureBroadcaster;
use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Broadcasting\Mercure\ChannelEncrypter;
use Hypervel\Container\Container;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\Dir;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mercure\Exception\InvalidArgumentException as MercureInvalidArgumentException;
use Symfony\Component\Mercure\Exception\RuntimeException as MercureRuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\DefaultClaimsTokenFactory;
use Symfony\Component\Mercure\Jwt\WebTokenFactory;
use Symfony\Component\Mercure\Update;

class MercureBroadcasterTest extends TestCase
{
    use VerifiesDoubles;

    public MercureBroadcaster $broadcaster;

    public HubInterface&DoubleInterface $hub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hub = Double::for(HubInterface::class)->strict();
        $this->hub->allows('getPublicUrl')->returns('https://localhost/.well-known/mercure');
        $this->hub->allows('getCookieName')->returns('__Secure-mercure_access_token');
        $this->hub->allows('getFactory')->returns($this->tokenFactory());

        $this->broadcaster = new MercureBroadcaster(new Container, $this->hub);
    }

    /**
     * Create a token factory with fixed issuer and audience claims.
     */
    protected function tokenFactory(): DefaultClaimsTokenFactory
    {
        return new DefaultClaimsTokenFactory(
            WebTokenFactory::fromSecret(
                'this-is-a-very-long-secret-used-for-hmac-sha256-signing!!',
                'HS256',
                300,
            ),
            [
                'iss' => 'https://app.example.com',
                'aud' => 'https://localhost/.well-known/mercure',
                'client_id' => 'test-app',
                'sub' => 'anonymous',
            ],
        );
    }

    public function testConstructingRegistersTheHubsCookieNameAsNeverEncrypted(): void
    {
        $neverEncrypt = (new ReflectionClass(EncryptCookies::class))
            ->getProperty('neverEncrypt')
            ->getValue();

        $this->assertContains('__Secure-mercure_access_token', $neverEncrypt);
    }

    public function testSettingAHubRegistersItsCookieNameAsNeverEncrypted(): void
    {
        $hub = Double::for(HubInterface::class)->strict();
        $hub->expects('getCookieName')->returns('custom_mercure_cookie');

        $this->broadcaster->setHub($hub);

        $neverEncrypt = (new ReflectionClass(EncryptCookies::class))
            ->getProperty('neverEncrypt')
            ->getValue();

        $this->assertContains('custom_mercure_cookie', $neverEncrypt);
    }

    public function testAuthThrowsAccessDeniedWhenNoChannelIsRequested(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->requestFor([], 1));
    }

    public function testAuthThrowsAccessDeniedForNonStringChannelNames(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->requestFor([['nested']], 1));
    }

    public function testAuthThrowsAccessDeniedForOversizedChannelBatches(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->requestFor(array_map(static fn (int $index): string => 'news.' . $index, range(1, 101)), 1));
    }

    public function testAuthDeduplicatesRequestedChannels(): void
    {
        $this->broadcaster->channel('room.1', static fn (): bool => true);

        $response = $this->broadcaster->auth($this->requestFor(['private-room.1', 'private-room.1'], 42));

        $this->assertSame([['name' => 'private-room.1']], $response->getData(true)['channel_names']);

        $claims = $this->decodeJwtClaims($this->cookieValue($response));

        $this->assertSame([['match' => 'https://laravel.alt/echo/channel/private-room.1']], $claims['authorization_details'][0]['topics']);
        $this->assertSame([['match' => 'https://laravel.alt/echo/whisper/private-room.1']], $claims['authorization_details'][1]['topics']);
    }

    public function testAuthStillMintsATokenWithNoGrantsWhenEveryChannelIsPublic(): void
    {
        $response = $this->broadcaster->auth($this->requestFor(['news'], 1));

        $this->assertSame([['name' => 'news']], $response->getData(true)['channel_names']);
        $this->assertSame(300, $response->getData(true)['expires_in']);
        $this->assertSame('https://laravel.alt/echo/', $response->getData(true)['topic_prefix']);
        $this->assertTrue($response->getData(true)['client_events']);

        $claims = $this->decodeJwtClaims($this->cookieValue($response));

        $this->assertArrayNotHasKey('authorization_details', $claims);
    }

    public function testAuthFlagsAGuardedChannelDeniedForAGuest(): void
    {
        $response = $this->broadcaster->auth($this->requestFor(['private-room.1'], null));

        $this->assertSame([['name' => 'private-room.1', 'denied' => true]], $response->getData(true)['channel_names']);

        $claims = $this->decodeJwtClaims($this->cookieValue($response));

        $this->assertArrayNotHasKey('authorization_details', $claims);
    }

    public function testAuthFlagsADeniedChannelAndKeepsTheGrantedOnes(): void
    {
        $this->broadcaster->channel('secret', static fn (): bool => false);
        $this->broadcaster->channel('room.1', static fn (): bool => true);

        $response = $this->broadcaster->auth($this->requestFor(['private-secret', 'private-room.1'], 42));

        $this->assertSame([
            ['name' => 'private-secret', 'denied' => true],
            ['name' => 'private-room.1'],
        ], $response->getData(true)['channel_names']);

        $details = $this->decodeJwtClaims($this->cookieValue($response))['authorization_details'];

        $this->assertSame([['match' => 'https://laravel.alt/echo/channel/private-room.1']], $details[0]['topics']);
        $this->assertSame([['match' => 'https://laravel.alt/echo/whisper/private-room.1']], $details[1]['topics']);
    }

    public function testAuthExcludesPublicChannelsAndGrantsOnlyGuardedOnes(): void
    {
        $this->broadcaster->channel('room.1', static fn (): bool => true);

        $response = $this->broadcaster->auth($this->requestFor(['news', 'private-room.1'], 42));

        $this->assertSame([['name' => 'news'], ['name' => 'private-room.1']], $response->getData(true)['channel_names']);

        $claims = $this->decodeJwtClaims($this->cookieValue($response));

        $this->assertSame([
            [
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => ['subscribe'],
                'topics' => [['match' => 'https://laravel.alt/echo/channel/private-room.1']],
            ],
            [
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => ['subscribe', 'publish'],
                'topics' => [['match' => 'https://laravel.alt/echo/whisper/private-room.1']],
            ],
        ], $claims['authorization_details']);
        $this->assertSame('42', $claims['sub']);
        $this->assertSame('https://app.example.com', $claims['iss']);
    }

    public function testAuthGrantsPresenceChannelWithPayloadAndSubscriptionEventsPattern(): void
    {
        $this->broadcaster->channel('room.1', static fn (GenericBroadcastingTestUser $user): array => ['id' => $user->getAuthIdentifier(), 'name' => 'alice']);

        $response = $this->broadcaster->auth($this->requestFor(['presence-room.1'], 42));

        $claims = $this->decodeJwtClaims($this->cookieValue($response));
        $detail = $claims['authorization_details'][0];

        $this->assertSame(['user_id' => '42', 'user_info' => ['id' => 42, 'name' => 'alice']], $detail['payload']);
        $this->assertSame([
            ['match' => 'https://laravel.alt/echo/channel/presence-room.1'],
            [
                'match' => '/.well-known/mercure/subscriptions/:match_type/https%3A%2F%2Flaravel.alt%2Fecho%2Fchannel%2Fpresence-room.1{/:subscriber}?',
                'match_type' => 'urlpattern',
            ],
        ], $detail['topics']);
    }

    #[TestWith(['https://localhost/.well-known/mercure', '__Secure-mercure_access_token', '/.well-known/mercure'])]
    #[TestWith(['https://localhost/', '__Host-mercure', '/'])]
    public function testAuthSetsTheCookieProvidedByTheHub(string $publicUrl, string $cookieName, string $path): void
    {
        $broadcaster = $this->broadcasterForHub($publicUrl, $cookieName);
        $broadcaster->channel('room.1', static fn (): bool => true);

        $response = $broadcaster->auth($this->requestFor(['private-room.1'], 42));

        $cookie = $response->headers->getCookies()[0];

        $this->assertSame($cookieName, $cookie->getName());
        $this->assertSame($path, $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
    }

    /**
     * Capture updates published by the broadcaster.
     *
     * @return ArrayObject<int, Update>
     */
    protected function capturePublishedUpdates(): ArrayObject
    {
        $updates = new ArrayObject;

        $this->hub->allows('publish')->resolves(function (Update $update) use ($updates): string {
            $updates[] = $update;

            return 'id';
        });

        return $updates;
    }

    public function testBroadcastPublishesOneUnprivatedUpdateWhenEveryChannelIsPublic(): void
    {
        $this->hub->expects('publish')->with(Argument::satisfies(function (Update $update): bool {
            $data = json_decode($update->getData(), true);

            return $update->getTopics() === ['https://laravel.alt/echo/channel/news', 'https://laravel.alt/echo/channel/weather']
                && ! $update->isPrivate()
                && $data['channels'] === ['news', 'weather'];
        }));

        $this->broadcaster->broadcast(['news', 'weather'], 'Tick', ['time' => 'now']);
    }

    public function testBroadcastPublishesOnePrivateUpdatePerGuardedChannel(): void
    {
        $updates = $this->capturePublishedUpdates();

        $this->broadcaster->broadcast(['private-room.1', 'presence-room.2'], 'MessageSent', []);

        $this->assertCount(2, $updates);

        foreach (['private-room.1', 'presence-room.2'] as $index => $channel) {
            $data = json_decode($updates[$index]->getData(), true);

            $this->assertSame(['https://laravel.alt/echo/channel/' . $channel], $updates[$index]->getTopics());
            $this->assertTrue($updates[$index]->isPrivate());
            $this->assertSame([$channel], $data['channels']);
        }
    }

    public function testBroadcastStripsTheSocketKeyFromThePayloadAndEmbedsItInTheEnvelope(): void
    {
        $this->hub->expects('publish')->with(Argument::satisfies(function (Update $update): bool {
            $data = json_decode($update->getData(), true);

            return $data['socket'] === 'abcd.1234' && $data['payload'] === ['text' => 'hi'];
        }));

        $this->broadcaster->broadcast(['news'], 'MessageSent', ['text' => 'hi', 'socket' => 'abcd.1234']);
    }

    public function testBroadcastOmitsTheSocketKeyFromTheEnvelopeWhenAbsent(): void
    {
        $this->hub->expects('publish')->with(Argument::satisfies(function (Update $update): bool {
            return ! array_key_exists('socket', json_decode($update->getData(), true));
        }));

        $this->broadcaster->broadcast(['news'], 'Tick', ['time' => 'now']);
    }

    public function testBroadcastSplitsAMixedBatchIntoTwoUpdates(): void
    {
        $updates = $this->capturePublishedUpdates();

        $this->broadcaster->broadcast(['news', 'private-room.1'], 'MessageSent', ['text' => 'hi']);

        $this->assertCount(2, $updates);
        $this->assertSame(['https://laravel.alt/echo/channel/news'], $updates[0]->getTopics());
        $this->assertFalse($updates[0]->isPrivate());
        $this->assertSame(['https://laravel.alt/echo/channel/private-room.1'], $updates[1]->getTopics());
        $this->assertTrue($updates[1]->isPrivate());
    }

    public function testTopicsEncodeChannelNamesIntoASinglePathSegment(): void
    {
        $this->hub->expects('publish')->with(Argument::satisfies(function (Update $update): bool {
            return $update->getTopics() === ['https://laravel.alt/echo/channel/order%2F1%20%2A%27%28%29%21'];
        }));

        $this->broadcaster->broadcast(['order/1 *\'()!'], 'Tick');
    }

    public function testBroadcastWrapsHubExceptionsIntoABroadcastException(): void
    {
        $this->expectException(BroadcastException::class);

        $this->hub->expects('publish')->throws(
            new MercureRuntimeException('unreachable')
        );

        $this->broadcaster->broadcast(['news'], 'Tick');
    }

    public function testBroadcastSurfacesTheUnderlyingCauseAndChainsTheHubException(): void
    {
        $hubException = new MercureRuntimeException(
            'Failed to send an update.',
            0,
            new RuntimeException('HTTP/2 401 from the hub')
        );

        $this->hub->expects('publish')->throws($hubException);

        try {
            $this->broadcaster->broadcast(['news'], 'Tick');
            $this->fail('A BroadcastException should have been thrown.');
        } catch (BroadcastException $e) {
            $this->assertSame('Mercure error: HTTP/2 401 from the hub.', $e->getMessage());
            $this->assertSame($hubException, $e->getPrevious());
        }
    }

    public function testBroadcastWrapsBuiltInHubRuntimeExceptionsIntoABroadcastException(): void
    {
        $hubException = new RuntimeException('No Mercure hub configured');

        $this->hub->expects('publish')->throws($hubException);

        try {
            $this->broadcaster->broadcast(['news'], 'Tick');
            $this->fail('A BroadcastException should have been thrown.');
        } catch (BroadcastException $e) {
            $this->assertSame('Mercure error: No Mercure hub configured.', $e->getMessage());
            $this->assertSame($hubException, $e->getPrevious());
        }
    }

    public function testAuthMintsAnAnonymousTokenForAGuestOnPublicOnlyChannels(): void
    {
        $response = $this->broadcaster->auth($this->requestFor(['news'], null));

        $claims = $this->decodeJwtClaims($this->cookieValue($response));

        $this->assertSame('anonymous', $claims['sub']);
        $this->assertArrayNotHasKey('authorization_details', $claims);
    }

    public function testAuthGrantsEachPresenceChannelItsOwnPayload(): void
    {
        $this->broadcaster->channel('room.{room}', static fn (GenericBroadcastingTestUser $user, string $room): array => ['id' => $user->getAuthIdentifier(), 'room' => $room]);
        $this->broadcaster->channel('inbox.{id}', static fn (): bool => true);

        $response = $this->broadcaster->auth(
            $this->requestFor(['presence-room.1', 'presence-room.2', 'private-inbox.42'], 42)
        );

        $claims = $this->decodeJwtClaims($this->cookieValue($response));
        $details = $claims['authorization_details'];

        $this->assertCount(4, $details);

        $this->assertSame([['match' => 'https://laravel.alt/echo/channel/private-inbox.42']], $details[0]['topics']);
        $this->assertArrayNotHasKey('payload', $details[0]);

        $this->assertSame([
            ['match' => 'https://laravel.alt/echo/channel/presence-room.1'],
            ['match' => '/.well-known/mercure/subscriptions/:match_type/https%3A%2F%2Flaravel.alt%2Fecho%2Fchannel%2Fpresence-room.1{/:subscriber}?', 'match_type' => 'urlpattern'],
        ], $details[1]['topics']);
        $this->assertSame(['user_id' => '42', 'user_info' => ['id' => 42, 'room' => '1']], $details[1]['payload']);

        $this->assertSame([
            ['match' => 'https://laravel.alt/echo/channel/presence-room.2'],
            ['match' => '/.well-known/mercure/subscriptions/:match_type/https%3A%2F%2Flaravel.alt%2Fecho%2Fchannel%2Fpresence-room.2{/:subscriber}?', 'match_type' => 'urlpattern'],
        ], $details[2]['topics']);
        $this->assertSame(['user_id' => '42', 'user_info' => ['id' => 42, 'room' => '2']], $details[2]['payload']);

        $this->assertSame(['subscribe', 'publish'], $details[3]['actions']);
        $this->assertSame([
            ['match' => 'https://laravel.alt/echo/whisper/presence-room.1'],
            ['match' => 'https://laravel.alt/echo/whisper/presence-room.2'],
            ['match' => 'https://laravel.alt/echo/whisper/private-inbox.42'],
        ], $details[3]['topics']);
        $this->assertArrayNotHasKey('payload', $details[3]);
    }

    public function testAuthDeniesAGuestBeforeRevealingEncryptionConfigurationState(): void
    {
        $response = $this->broadcaster->auth($this->requestFor(['private-encrypted-room.1'], null));

        // Denied before the encrypter check: no key material, no hint that
        // encryption is even configured.
        $this->assertSame([['name' => 'private-encrypted-room.1', 'denied' => true]], $response->getData(true)['channel_names']);
    }

    public function testCookieSubMatchesTheChannelGuardResolvedUser(): void
    {
        $this->broadcaster->channel('room.1', static fn (): bool => true, ['guards' => ['admin']]);

        $request = Request::create('/broadcasting/auth', 'POST', ['channel_names' => ['private-room.1']]);
        $request->setUserResolver(static fn (?string $guard = null): ?GenericBroadcastingTestUser => $guard === 'admin' ? new GenericBroadcastingTestUser(7) : null);

        $response = $this->broadcaster->auth($request);

        $claims = $this->decodeJwtClaims($this->cookieValue($response));

        $this->assertSame('7', $claims['sub']);
    }

    public function testAuthUsesTheRewrittenGuardAndPreservesResponseOverrides(): void
    {
        $broadcaster = new class(new Container, $this->hub) extends MercureBroadcaster {
            /**
             * Add application data to an authorized presence response.
             */
            public function validAuthenticationResponse(Request $request, mixed $result): mixed
            {
                return $result + ['role' => 'viewer'];
            }
        };
        $broadcaster->channel('room.1', static fn (): array => ['name' => 'alice'], ['guards' => ['members']]);
        Broadcaster::authorizeChannelsUsing(static fn (Request $request, string $channel): ?string => $channel === 'tenant.room.1' ? 'room.1' : null);
        $request = $this->requestFor(['presence-tenant.room.1'], null);
        $request->setUserResolver(static fn (?string $guard = null): ?GenericBroadcastingTestUser => $guard === 'members' ? new GenericBroadcastingTestUser(7) : null);

        $response = $broadcaster->auth($request);
        $claims = $this->decodeJwtClaims($this->cookieValue($response));

        $this->assertSame('7', $claims['sub']);
        $this->assertSame([['name' => 'presence-tenant.room.1']], $response->getData(true)['channel_names']);
        $this->assertSame([
            'user_id' => '7',
            'user_info' => ['name' => 'alice', 'role' => 'viewer'],
        ], $claims['authorization_details'][0]['payload']);
        $this->assertSame('https://laravel.alt/echo/channel/presence-tenant.room.1', $claims['authorization_details'][0]['topics'][0]['match']);
    }

    public function testAuthRejectsEncryptedChannelsWithoutAnEncryptionKey(): void
    {
        $this->broadcaster->channel('room.1', static fn (): bool => true);

        try {
            $this->broadcaster->auth($this->requestFor(['private-encrypted-room.1'], 42));
            $this->fail('A BroadcastException should have been thrown.');
        } catch (BroadcastException $e) {
            $this->assertStringContainsString('encryption_key', $e->getMessage());
        }
    }

    public function testBroadcastRejectsEncryptedChannelsWithoutAnEncryptionKey(): void
    {
        try {
            $this->broadcaster->broadcast(['private-encrypted-room.1'], 'MessageSent');
            $this->fail('A BroadcastException should have been thrown.');
        } catch (BroadcastException $e) {
            $this->assertStringContainsString('encryption_key', $e->getMessage());
        }
    }

    public function testAuthReturnsAJwkForEachEncryptedChannel(): void
    {
        $broadcaster = $this->encryptedBroadcaster();
        $broadcaster->channel('orders.{id}', static fn (): bool => true);
        $broadcaster->channel('room.1', static fn (): bool => true);

        $response = $broadcaster->auth($this->requestFor(['private-encrypted-orders.1', 'private-room.1'], 42));

        $channels = $response->getData(true)['channel_names'];

        $this->assertSame([
            'name' => 'private-encrypted-orders.1',
            'jwk' => [
                'kty' => 'oct',
                'k' => rtrim(strtr(base64_encode(hash_hkdf('sha256', $this->encryptionKey(), 32, 'private-encrypted-orders.1')), '+/', '-_'), '='),
                'alg' => 'A256GCM',
                'use' => 'enc',
            ],
        ], $channels[0]);
        $this->assertSame(['name' => 'private-room.1'], $channels[1]);
    }

    public function testAuthGrantsEncryptedChannelsInTheSharedPrivateGrant(): void
    {
        $broadcaster = $this->encryptedBroadcaster();
        $broadcaster->channel('orders.{id}', static fn (): bool => true);
        $broadcaster->channel('room.1', static fn (): bool => true);

        $response = $broadcaster->auth($this->requestFor(['private-room.1', 'private-encrypted-orders.1'], 42));

        $claims = $this->decodeJwtClaims($this->cookieValue($response));

        $this->assertSame([
            [
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => ['subscribe'],
                'topics' => [
                    ['match' => 'https://laravel.alt/echo/channel/private-room.1'],
                    ['match' => 'https://laravel.alt/echo/channel/private-encrypted-orders.1'],
                ],
            ],
            [
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => ['subscribe', 'publish'],
                'topics' => [
                    ['match' => 'https://laravel.alt/echo/whisper/private-room.1'],
                    ['match' => 'https://laravel.alt/echo/whisper/private-encrypted-orders.1'],
                ],
            ],
        ], $claims['authorization_details']);
    }

    public function testAuthDeniesAnEncryptedChannelLikeAnyGuardedOne(): void
    {
        $broadcaster = $this->encryptedBroadcaster();
        $broadcaster->channel('orders.{id}', static fn (): bool => false);

        $response = $broadcaster->auth($this->requestFor(['private-encrypted-orders.1'], 42));

        // Denied entries never carry the channel's JWK.
        $this->assertSame([['name' => 'private-encrypted-orders.1', 'denied' => true]], $response->getData(true)['channel_names']);
    }

    public function testBroadcastPublishesOnePrivateUpdatePerEncryptedChannel(): void
    {
        $broadcaster = $this->encryptedBroadcaster();

        $updates = $this->capturePublishedUpdates();

        $broadcaster->broadcast(['news', 'private-room.1', 'private-encrypted-a', 'private-encrypted-b'], 'MessageSent', ['text' => 'hi']);

        $this->assertCount(4, $updates);
        $this->assertSame(['https://laravel.alt/echo/channel/news'], $updates[0]->getTopics());
        $this->assertFalse($updates[0]->isPrivate());
        $this->assertSame(['https://laravel.alt/echo/channel/private-room.1'], $updates[1]->getTopics());
        $this->assertTrue($updates[1]->isPrivate());

        foreach (['private-encrypted-a', 'private-encrypted-b'] as $index => $channel) {
            $update = $updates[$index + 2];
            $data = json_decode($update->getData(), true);

            $this->assertSame(['https://laravel.alt/echo/channel/' . $channel], $update->getTopics());
            $this->assertTrue($update->isPrivate());
            $this->assertSame(['channels', 'data'], array_keys($data));
            $this->assertSame([$channel], $data['channels']);
            $this->assertCount(5, explode('.', $data['data']));
        }
    }

    public function testBroadcastEncryptedUpdateRoundTrips(): void
    {
        $broadcaster = $this->encryptedBroadcaster();

        $captured = null;
        $this->hub->expects('publish')->with(Argument::capture($captured));

        $broadcaster->broadcast(['private-encrypted-room.1'], 'MessageSent', ['text' => 'hi', 'socket' => 'abcd.1234']);

        $jwe = (new CompactSerializer)->unserialize(json_decode($captured->getData(), true)['data']);
        $decrypter = new JWEDecrypter(new AlgorithmManager([new Dir, new A256GCM]));

        $this->assertTrue($decrypter->decryptUsingKey($jwe, $this->channelJwk('private-encrypted-room.1'), 0));
        $this->assertSame(
            ['event' => 'MessageSent', 'payload' => ['text' => 'hi'], 'socket' => 'abcd.1234'],
            json_decode($jwe->getPayload(), true)
        );
        $this->assertSame(['alg' => 'dir', 'enc' => 'A256GCM'], $jwe->getSharedProtectedHeader());
    }

    public function testBroadcastEncryptedChannelsUseDistinctKeys(): void
    {
        $broadcaster = $this->encryptedBroadcaster();

        $captured = null;
        $this->hub->expects('publish')->with(Argument::capture($captured));

        $broadcaster->broadcast(['private-encrypted-a'], 'Tick');

        $jwe = (new CompactSerializer)->unserialize(json_decode($captured->getData(), true)['data']);
        $decrypter = new JWEDecrypter(new AlgorithmManager([new Dir, new A256GCM]));

        $this->assertFalse($decrypter->decryptUsingKey($jwe, $this->channelJwk('private-encrypted-b'), 0));
    }

    public function testCookieDomainIsOmittedWhenTheHubSharesTheAppHost(): void
    {
        $this->broadcaster->channel('room.1', static fn (): bool => true);

        $response = $this->broadcaster->auth($this->requestFor(['private-room.1'], 42));

        $this->assertNull($response->headers->getCookies()[0]->getDomain());
    }

    public function testCookieDomainCoversAHubOnASubdomainOfTheApp(): void
    {
        $broadcaster = $this->broadcasterForHub('https://hub.example.com/.well-known/mercure');
        $broadcaster->channel('room.1', static fn (): bool => true);

        $request = Request::create('https://example.com/broadcasting/auth', 'POST', ['channel_names' => ['private-room.1']]);
        $request->setUserResolver(static fn (): GenericBroadcastingTestUser => new GenericBroadcastingTestUser(42));

        $response = $broadcaster->auth($request);

        $this->assertSame('example.com', $response->headers->getCookies()[0]->getDomain());
    }

    public function testCookieDomainCoversAHubAndAppOnSiblingSubdomains(): void
    {
        $broadcaster = $this->broadcasterForHub('https://hub.example.com/.well-known/mercure');
        $broadcaster->channel('room.1', static fn (): bool => true);

        $request = Request::create('https://app.example.com/broadcasting/auth', 'POST', ['channel_names' => ['private-room.1']]);
        $request->setUserResolver(static fn (): GenericBroadcastingTestUser => new GenericBroadcastingTestUser(42));

        $response = $broadcaster->auth($request);

        $this->assertSame('.example.com', $response->headers->getCookies()[0]->getDomain());
    }

    public function testAuthRejectsASecurePrefixedCookieNameOnAPlainHttpHub(): void
    {
        $broadcaster = $this->broadcasterForHub('http://localhost/.well-known/mercure');

        $this->expectException(MercureInvalidArgumentException::class);

        $broadcaster->auth($this->requestFor(['news'], null));
    }

    #[TestWith(['https://localhost/.well-known/mercure', '__host-mercure', 'Path=/'])]
    #[TestWith(['https://hub.localhost/', '__Host-mercure', 'no Domain'])]
    #[TestWith(['http://localhost/.well-known/mercure', '__secure-mercure', 'HTTPS'])]
    public function testAuthRejectsInvalidPrefixedCookieAttributes(string $publicUrl, string $cookieName, string $message): void
    {
        $broadcaster = $this->broadcasterForHub($publicUrl, $cookieName);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $broadcaster->auth($this->requestFor(['news'], null));
    }

    public function testAuthRejectsAHubOnADifferentSecondLevelDomain(): void
    {
        $broadcaster = $this->broadcasterForHub('https://hub.other.com/.well-known/mercure');
        $broadcaster->channel('room.1', static fn (): bool => true);

        $request = Request::create('https://example.com/broadcasting/auth', 'POST', ['channel_names' => ['private-room.1']]);
        $request->setUserResolver(static fn (): GenericBroadcastingTestUser => new GenericBroadcastingTestUser(42));

        try {
            $broadcaster->auth($request);
            $this->fail('A BroadcastException should have been thrown.');
        } catch (BroadcastException $e) {
            $this->assertStringContainsString('public_url', $e->getMessage());
            $this->assertStringContainsString('hub.other.com', $e->getMessage());
            $this->assertStringContainsString('example.com', $e->getMessage());
        }
    }

    public function testAuthOmitsTheWhisperGrantWhenClientEventsAreDisabled(): void
    {
        $broadcaster = new MercureBroadcaster(new Container, $this->hub, clientEvents: false);
        $broadcaster->channel('room.1', static fn (): bool => true);

        $response = $broadcaster->auth($this->requestFor(['private-room.1'], 42));

        $this->assertFalse($response->getData(true)['client_events']);

        $details = $this->decodeJwtClaims($this->cookieValue($response))['authorization_details'];

        $this->assertCount(1, $details);
        $this->assertSame(['subscribe'], $details[0]['actions']);
    }

    public function testAuthAndBroadcastHonorACustomTopicPrefix(): void
    {
        $broadcaster = new MercureBroadcaster(new Container, $this->hub, topicPrefix: 'https://app.example.com/broadcasting/');
        $broadcaster->channel('room.1', static fn (): bool => true);

        $response = $broadcaster->auth($this->requestFor(['private-room.1'], 42));

        $this->assertSame('https://app.example.com/broadcasting/', $response->getData(true)['topic_prefix']);

        $details = $this->decodeJwtClaims($this->cookieValue($response))['authorization_details'];

        $this->assertSame([['match' => 'https://app.example.com/broadcasting/channel/private-room.1']], $details[0]['topics']);
        $this->assertSame([['match' => 'https://app.example.com/broadcasting/whisper/private-room.1']], $details[1]['topics']);

        $this->hub->expects('publish')->with(Argument::satisfies(
            static fn (Update $update): bool => $update->getTopics() === ['https://app.example.com/broadcasting/channel/news']
        ));

        $broadcaster->broadcast(['news'], 'Tick');
    }

    /**
     * Create a broadcaster for a public hub URL.
     */
    protected function broadcasterForHub(string $publicUrl, string $cookieName = '__Secure-mercure_access_token'): MercureBroadcaster
    {
        $hub = Double::for(HubInterface::class)->strict();
        $hub->allows('getPublicUrl')->returns($publicUrl);
        $hub->allows('getCookieName')->returns($cookieName);
        $hub->allows('getFactory')->returns($this->tokenFactory());

        return new MercureBroadcaster(new Container, $hub);
    }

    /**
     * Get the fixed test encryption key.
     */
    protected function encryptionKey(): string
    {
        return hash('sha256', 'mercure-e2e-test-key', true);
    }

    /**
     * Create a broadcaster with encrypted channels enabled.
     */
    protected function encryptedBroadcaster(): MercureBroadcaster
    {
        return new MercureBroadcaster(new Container, $this->hub, 300, new ChannelEncrypter($this->encryptionKey()));
    }

    /**
     * Build the given channel's JWK independently of the encrypter, so the
     * tests prove the exact derivation contract the JS connector relies on.
     */
    protected function channelJwk(string $channel): JWK
    {
        return new JWK([
            'kty' => 'oct',
            'k' => rtrim(strtr(base64_encode(hash_hkdf('sha256', $this->encryptionKey(), 32, $channel)), '+/', '-_'), '='),
        ]);
    }

    /**
     * Create an authentication request for the channels and user.
     */
    protected function requestFor(array $channelNames, mixed $userId): Request
    {
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_names' => $channelNames,
        ]);

        $request->setUserResolver(static fn (): ?GenericBroadcastingTestUser => is_null($userId) ? null : new GenericBroadcastingTestUser($userId));

        return $request;
    }

    /**
     * Get the authorization token from the response cookie.
     */
    protected function cookieValue(JsonResponse $response): string
    {
        return $response->headers->getCookies()[0]->getValue();
    }

    /**
     * Decode the generated token claims for assertions.
     */
    protected function decodeJwtClaims(string $jwt): array
    {
        [, $payload] = explode('.', $jwt);

        return json_decode(base64_decode(str_pad(
            strtr($payload, '-_', '+/'),
            strlen($payload) + (4 - strlen($payload) % 4) % 4,
            '='
        )), true);
    }
}

class GenericBroadcastingTestUser
{
    /**
     * Create a user with a broadcasting identifier.
     */
    public function __construct(protected mixed $id)
    {
    }

    /**
     * Get the broadcasting identifier.
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }
}
