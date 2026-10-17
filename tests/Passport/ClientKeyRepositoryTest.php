<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Firebase\JWT\JWT;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientKeyRepository;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Passport\Fixtures\JsonWebKeyPair;
use PHPUnit\Framework\Attributes\TestWith;
use UnexpectedValueException;

use function Hypervel\Coroutine\parallel;

class ClientKeyRepositoryTest extends TestCase
{
    /**
     * Use a shared file store so child coroutines contend on the same refresh lock.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'passport' => require __DIR__ . '/../../src/passport/config/passport.php',
            'passport.cache.store' => 'client-keys',
            'cache.stores.client-keys' => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/client-keys'),
            ],
        ]);
    }

    /**
     * Remove cached keys and refresh markers owned by the test.
     */
    protected function tearDown(): void
    {
        try {
            $this->app->make('files')->deleteDirectory($this->app->storagePath('framework/cache/client-keys'));
        } finally {
            parent::tearDown();
        }
    }

    public function testWarmKeysAvoidRequestsAndUnknownKeyRefreshesAreBounded(): void
    {
        $key = $this->key();
        Http::fake(['https://keys.example/jwks' => Http::sequence()
            ->push(['keys' => [[...$key, 'kid' => 'first']]])
            ->push(['keys' => [[...$key, 'kid' => 'second']]])
            ->push(['keys' => [[...$key, 'kid' => 'third']]])]);
        $client = $this->client();
        $keys = $this->app->make(ClientKeyRepository::class);

        $this->assertNotNull($keys->find($client, 'first', 'RS256'));
        $this->assertNotNull($keys->find($client, 'first', 'RS256'));
        $this->assertNull($keys->find($client, 'unknown', 'RS256'));
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->assertNotNull($keys->find($client, 'second', 'RS256'));
        $this->assertNull($keys->find($client, 'another-unknown', 'RS256'));
        Http::assertSentCount(2);

        $this->travel(3601)->seconds();
        $this->assertNotNull($keys->find($client, 'third', 'RS256'));
        Http::assertSentCount(3);
    }

    public function testConcurrentColdLookupsShareOneDownload(): void
    {
        $key = $this->key();
        Http::fake(static function () use ($key) {
            usleep(10000);

            return Http::response(['keys' => [$key]]);
        });
        $client = $this->client();
        $keys = $this->app->make(ClientKeyRepository::class);

        [$first, $second] = parallel([
            static fn () => $keys->find($client, $key['kid'], 'RS256'),
            static fn () => $keys->find($client, $key['kid'], 'RS256'),
        ]);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        Http::assertSentCount(1);
    }

    public function testChangingTheRegisteredUrlDoesNotReuseOldKeys(): void
    {
        $key = $this->key();
        Http::fake([
            'https://keys.example/jwks' => Http::response(['keys' => [[...$key, 'kid' => 'old']]]),
            'https://replacement.example/jwks' => Http::response(['keys' => [[...$key, 'kid' => 'new']]]),
        ]);
        $client = $this->client();
        $keys = $this->app->make(ClientKeyRepository::class);
        $this->assertNotNull($keys->find($client, 'old', 'RS256'));
        $client->jwks_uri = 'https://replacement.example/jwks';
        $this->assertNotNull($keys->find($client, 'new', 'RS256'));
        $this->assertNull($keys->find($client, 'old', 'RS256'));
        Http::assertSentCount(2);
    }

    public function testRemoteMixedKeySetsSelectOnlyEligibleSigningKeys(): void
    {
        $key = $this->key();
        unset($key['alg']);
        Http::fake(['https://keys.example/jwks' => Http::response(['keys' => [
            [...$key, 'kid' => 'encryption', 'use' => 'enc'],
            JsonWebKeyPair::generate()->jwk,
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => JWT::urlsafeB64Encode(str_repeat('a', 32))],
            $key,
        ]])]);

        $keys = $this->app->make(ClientKeyRepository::class);
        $this->assertNotNull($keys->find($this->client(), null, 'RS256'));
        $this->assertNotNull($keys->find($this->client(), $key['kid'], 'RS256'));
        $this->assertNull($keys->find($this->client(), 'encryption', 'RS256'));
        Http::assertSentCount(1);
    }

    public function testDifferentKeyTypesMayShareAnIdentifier(): void
    {
        $key = $this->key();
        unset($key['alg']);
        $client = $this->client();
        $client->jwks_uri = null;
        $client->jwks = ['keys' => [$key, [...JsonWebKeyPair::generate()->jwk, 'kid' => $key['kid']]]];
        $keys = $this->app->make(ClientKeyRepository::class);
        $keys->validate($client->jwks, 'RS256');
        $keys->validate($client->jwks, 'ES256');

        $this->assertSame('RS256', $keys->find($client, $key['kid'], 'RS256')->getAlgorithm());
        $this->assertSame('ES256', $keys->find($client, $key['kid'], 'ES256')->getAlgorithm());
    }

    public function testInlineKeysNeedNoCacheAndAmbiguousKeysAreNotTriedInTurn(): void
    {
        Http::fake();
        $key = $this->key();
        $client = $this->client();
        $client->jwks_uri = null;
        $client->jwks = ['keys' => [$key]];
        $keys = $this->app->make(ClientKeyRepository::class);
        $this->assertNotNull($keys->find($client, null, 'RS256'));
        $this->assertNull($keys->find($client, 'unknown', 'RS256'));
        $client->jwks = ['keys' => [$key, [...$key, 'kid' => 'another']]];
        $this->assertNull($keys->find($client, null, 'RS256'));
        $this->assertNotNull($keys->find($client, 'another', 'RS256'));
        Http::assertNothingSent();
    }

    #[TestWith([['keys' => []]])]
    #[TestWith([['keys' => ['not-an-object']]])]
    #[TestWith([['keys' => [['kty' => 'RSA', 'use' => 'enc']]]])]
    #[TestWith([['keys' => [['kty' => 'oct', 'k' => 'private']]], true])]
    public function testInvalidKeySetsAreRejected(array $document, bool $includeSigner = false): void
    {
        if ($includeSigner) {
            $document['keys'][] = $this->key();
        }

        $this->expectException(UnexpectedValueException::class);
        $this->app->make(ClientKeyRepository::class)->validate($document, 'RS256');
    }

    public function testDuplicateKeyIdentifiersAreRejected(): void
    {
        $key = $this->key();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unique');
        $this->app->make(ClientKeyRepository::class)->validate(['keys' => [$key, $key]], 'RS256');
    }

    /**
     * Build a client with a remote public-key declaration.
     */
    protected function client(): Client
    {
        return new Client(['id' => 'client', 'jwks' => null, 'jwks_uri' => 'https://keys.example/jwks']);
    }

    /**
     * Return public signing material from the shared test key pair.
     */
    protected function key(): array
    {
        return ParsedKey::fromPublicKey((new SigningKeyFactory)->definition()['public_key'])->getJwk();
    }
}
