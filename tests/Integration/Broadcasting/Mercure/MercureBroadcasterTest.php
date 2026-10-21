<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Broadcasting\Mercure;

use Closure;
use Hypervel\Auth\GenericUser;
use Hypervel\Broadcasting\Broadcasters\MercureBroadcaster;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\Concerns\InteractsWithMercure;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Testbench\TestCase;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\Dir;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Chunk\ServerSentEvent;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

class MercureBroadcasterTest extends TestCase
{
    use InteractsWithMercure;

    /**
     * Bound network operations and enable the encrypted-channel variant.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('broadcasting.connections.mercure.client_options', ['max_duration' => 5]);
        $app->make('config')->set('broadcasting.connections.mercure.encryption_key', base64_encode(random_bytes(32)));
    }

    #[DataProvider('channels')]
    public function testPublishesToAnAuthenticatedSubscriber(string $channel): void
    {
        $broadcaster = $this->broadcaster();
        $response = $this->authorize($broadcaster, $channel);
        [$client, $source] = $this->subscribe($broadcaster, $response->headers->getCookies()[0], $this->topic($channel));

        try {
            $broadcaster->broadcast([$channel], 'OrderShipped', ['id' => 42]);
            $data = $this->readEvent($client, $source);

            $this->assertSame([$channel], $data['channels']);

            if (str_starts_with($channel, 'private-encrypted-')) {
                $jwe = (new CompactSerializer)->unserialize($data['data']);
                $key = new JWK($response->getData(true)['channel_names'][0]['jwk']);
                $decrypter = new JWEDecrypter(new AlgorithmManager([new Dir, new A256GCM]));
                $this->assertTrue($decrypter->decryptUsingKey($jwe, $key, 0));
                $data = json_decode($jwe->getPayload(), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertSame('OrderShipped', $data['event']);
            $this->assertSame(['id' => 42], $data['payload']);
        } finally {
            $source->cancel();
        }
    }

    public static function channels(): array
    {
        return [['orders'], ['private-orders'], ['private-encrypted-orders']];
    }

    public function testRelativeUrlsProduceTokensAcceptedByTheHub(): void
    {
        $config = $this->app->make('config');
        $origin = Request::create($config->string('broadcasting.connections.mercure.url'))->getSchemeAndHttpHost();
        $config->set('broadcasting.connections.mercure.url', '/.well-known/mercure');
        $config->set('broadcasting.connections.mercure.public_url', '/.well-known/mercure');
        $this->app->make('url')->useOrigin($origin);
        $broadcaster = $this->broadcaster();
        $response = $this->authorize($broadcaster, 'private-orders');
        [$client, $source] = $this->subscribe($broadcaster, $response->headers->getCookies()[0], $this->topic('private-orders'));

        try {
            $broadcaster->broadcast(['private-orders'], 'OrderShipped', ['id' => 42]);
            $this->assertSame(['id' => 42], $this->readEvent($client, $source)['payload']);
        } finally {
            $source->cancel();
        }
    }

    public function testPresenceDeliversTheJoiningMembersAuthorizedPayload(): void
    {
        $broadcaster = $this->broadcaster();
        $first = $this->authorize($broadcaster, 'presence-orders', 1);
        $topic = $this->topic('presence-orders');
        $path = (string) parse_url($broadcaster->getHub()->getPublicUrl(), PHP_URL_PATH);
        $pattern = rtrim($path, '/') . '/subscriptions/:match_type/' . rawurlencode($topic) . '/:subscriber';
        [$client, $source] = $this->subscribe($broadcaster, $first->headers->getCookies()[0], $topic, $pattern);

        try {
            $second = $this->authorize($broadcaster, 'presence-orders', 2);
            [, $member] = $this->subscribe($broadcaster, $second->headers->getCookies()[0], $topic);

            try {
                $data = $this->readEvent($client, $source, static fn (array $data): bool => ($data['active'] ?? false) && ($data['payload']['user_id'] ?? null) === '2');

                $this->assertSame($topic, $data['match']);
                $this->assertSame('exact', $data['match_type']);
                $this->assertSame(['user_id' => '2', 'user_info' => ['name' => 'member-2']], $data['payload']);
            } finally {
                $member->cancel();
            }
        } finally {
            $source->cancel();
        }
    }

    public function testMemberCookiesCanPublishWhispersButNotServerEvents(): void
    {
        $broadcaster = $this->broadcaster();
        $response = $this->authorize($broadcaster, 'private-orders');
        $cookie = $response->headers->getCookies()[0];
        $topic = $this->mercureTopicPrefix . 'whisper/private-orders';
        [$client, $source] = $this->subscribe($broadcaster, $cookie, $topic);

        try {
            $publisher = HttpClient::create(['max_duration' => 5]);
            $options = [
                'headers' => [
                    'Cookie' => $cookie->getName() . '=' . $cookie->getValue(),
                    'Origin' => (string) env('MERCURE_JWT_ISSUER'),
                ],
                'body' => ['topic' => $topic, 'data' => '{"event":"client-wave"}', 'private' => 'on'],
            ];
            $published = $publisher->request('POST', $broadcaster->getHub()->getPublicUrl(), $options);
            $this->assertSame(200, $published->getStatusCode());
            $published->getContent();
            $this->assertSame(['event' => 'client-wave'], $this->readEvent($client, $source));

            $options['body']['topic'] = $this->topic('private-orders');
            $denied = $publisher->request('POST', $broadcaster->getHub()->getPublicUrl(), $options);
            $this->assertSame(403, $denied->getStatusCode());
            $denied->getContent(false);
        } finally {
            $source->cancel();
        }
    }

    /**
     * Get the configured broadcaster with an orders-channel authorizer.
     */
    protected function broadcaster(): MercureBroadcaster
    {
        $broadcaster = $this->app->make(BroadcastManager::class)->connection('mercure');
        $broadcaster->channel('orders', static fn (GenericUser $user): array => ['name' => $user->name]);

        return $broadcaster;
    }

    /**
     * Authenticate a member through the real broadcasting cookie response.
     */
    protected function authorize(MercureBroadcaster $broadcaster, string $channel, int $id = 1): JsonResponse
    {
        $request = Request::create($broadcaster->getHub()->getPublicUrl(), 'POST', ['channel_names' => [$channel]]);
        $request->setUserResolver(static fn (): GenericUser => new GenericUser(['id' => $id, 'name' => 'member-' . $id]));

        return $broadcaster->auth($request);
    }

    /**
     * Subscribe using Echo's matcher parameters and wait until registration finishes.
     *
     * @return array{EventSourceHttpClient, ResponseInterface}
     */
    protected function subscribe(MercureBroadcaster $broadcaster, Cookie $cookie, string $topic, ?string $pattern = null): array
    {
        $client = new EventSourceHttpClient(HttpClient::create(['max_duration' => 5]));
        $query = http_build_query(['match' => $topic, 'match_urlpattern' => $pattern]);
        $source = $client->connect($broadcaster->getHub()->getPublicUrl() . '?' . $query, [
            'headers' => ['Cookie' => $cookie->getName() . '=' . $cookie->getValue()],
        ]);

        try {
            $this->assertSame(200, $source->getStatusCode());
        } catch (Throwable $exception) {
            $source->cancel();
            throw $exception;
        }

        return [$client, $source];
    }

    /**
     * Read the next matching JSON event within the subscription deadline.
     *
     * @param null|Closure(array): bool $matches
     */
    protected function readEvent(EventSourceHttpClient $client, ResponseInterface $source, ?Closure $matches = null): array
    {
        foreach ($client->stream($source, 2) as $chunk) {
            if ($chunk->isTimeout()) {
                $this->fail('Mercure did not deliver the expected event.');
            }

            if ($chunk instanceof ServerSentEvent) {
                $data = json_decode($chunk->getData(), true, flags: JSON_THROW_ON_ERROR);

                if ($matches === null || $matches($data)) {
                    return $data;
                }
            }
        }

        $this->fail('Mercure closed the subscription before delivering the expected event.');
    }

    /**
     * Get this test's channel topic.
     */
    protected function topic(string $channel): string
    {
        return $this->mercureTopicPrefix . 'channel/' . rawurlencode($channel);
    }
}
