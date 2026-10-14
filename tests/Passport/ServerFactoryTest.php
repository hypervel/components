<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use DateInterval;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Client;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerKey;
use Hypervel\Passport\ServerSettings;
use Hypervel\Passport\SigningKey;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Passport\Token;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use LogicException;
use Mockery as m;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class ServerFactoryTest extends TestCase
{
    /**
     * Configure the files store with reusable PEM material.
     */
    protected function defineEnvironment(Application $app): void
    {
        $keys = (new SigningKeyFactory)->definition();
        $app->make('config')->set([
            'passport' => require __DIR__ . '/../../src/passport/config/passport.php',
            'passport.private_key' => $keys['private_key'],
            'passport.public_key' => $keys['public_key'],
            'passport.issuer' => 'https://auth.example',
            'passport.server_cache_size' => 2,
        ]);
    }

    public function testFileSettingsAndParsedKeysAreReusedWithoutReadingTheDatabase(): void
    {
        $servers = $this->app->make(ServerFactory::class);
        DB::enableQueryLog();
        $entry = $servers->entry();

        $this->assertSame('https://auth.example', $servers->settings()->issuer);
        $this->assertSame($entry, $servers->entry());
        $this->assertSame($servers->authorizationServer(), $servers->authorizationServer());
        $this->assertSame($servers->resourceServer(), $servers->resourceServer());
        $this->assertSame($servers->signingKey(new DateInterval('PT1H')), $servers->signingKey(new DateInterval('P1Y')));
        $this->assertSame([], DB::getQueryLog());
    }

    public function testRetainedFileKeysVerifyTokensThroughDatabaseMigrationAndRotation(): void
    {
        foreach ([
            '2016_06_01_000002_create_oauth_access_tokens_table.php',
            '2016_06_01_000004_create_oauth_clients_table.php',
            '2016_06_01_000007_create_oauth_signing_keys_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../src/passport/database/migrations/' . $migration)->up();
        }

        $originalPrivate = config('passport.private_key');
        $originalPublic = config('passport.public_key');
        $current = (new KeyGenerator)->generate(2048);
        config([
            'passport.private_key' => $current['private'],
            'passport.public_key' => $current['public'],
            'passport.verification_keys' => [$originalPublic],
            'passport.key_activation_delay' => 120,
            'cache.default' => 'passport-file-migration',
            'cache.stores.passport-file-migration' => [
                'driver' => 'file', 'path' => $this->app->storagePath('framework/cache/data/passport-file-migration'),
            ],
        ]);
        $this->beforeApplicationDestroyed(fn () => $this->app->make('cache')->store('passport-file-migration')->flush());
        $now = CarbonImmutable::now();
        $client = Client::forceCreate([
            'name' => 'Client', 'secret' => 'client-secret', 'revoked' => false,
            'redirect_uris' => [], 'grant_types' => ['client_credentials'],
        ]);
        $token = Token::forceCreate([
            'id' => 'retained-token', 'client_id' => $client->id, 'revoked' => false,
            'scopes' => [], 'expires_at' => $now->addYear(),
        ]);
        $originalId = ParsedKey::fromPublicKey($originalPublic)->getKeyId();
        $currentId = ParsedKey::fromPublicKey($current['public'])->getKeyId();
        $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->identifiedBy($token->id)->permittedFor($client->id)->relatedTo($client->id)
            ->issuedAt($now)->expiresAt($now->addYear())->withClaim('scopes', []);
        $withoutKid = $builder->getToken(new Sha256, InMemory::plainText($originalPrivate))->toString();
        $withKid = $builder->withHeader('kid', $originalId)->getToken(new Sha256, InMemory::plainText($originalPrivate))->toString();
        $servers = $this->app->make(ServerFactory::class);

        foreach ([$withoutKid, $withKid] as $jwt) {
            $validated = $servers->resourceServer()->validateAuthenticatedRequest(
                new ServerRequest('GET', '/api', ['Authorization' => 'Bearer ' . $jwt]),
            );
            $this->assertSame($token->id, $validated->getAttribute('oauth_access_token_id'));
        }

        $this->assertEqualsCanonicalizing([$originalId, $currentId], array_column($servers->jwks()['keys'], 'kid'));
        $response = $servers->authorizationServer()->respondToAccessTokenRequest(
            (new ServerRequest('POST', '/oauth/token'))->withParsedBody([
                'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => 'client-secret',
            ]),
            new Response,
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($currentId, (new Parser(new JoseEncoder))->parse($body['access_token'])->headers()->get('kid'));

        $repository = $this->app->make(SigningKeyRepository::class);
        $repository->importFiles();
        config(['passport.key_store' => 'database']);
        $replacement = $repository->rotate(2048);
        CarbonImmutable::setTestNow($replacement->activates_at->addSecond());
        $repository->prune();
        $validated = $servers->resourceServer()->validateAuthenticatedRequest(
            new ServerRequest('GET', '/api', ['Authorization' => 'Bearer ' . $withoutKid]),
        );
        $this->assertSame($token->id, $validated->getAttribute('oauth_access_token_id'));
        $this->assertSame(ParsedKey::fromPublicKey($replacement->public_key)->getKeyId(), $servers->signingKey(new DateInterval('PT1H'))->getKeyId());
    }

    #[TestWith(['files', null])]
    #[TestWith(['files', ''])]
    #[TestWith(['database', null])]
    #[TestWith(['database', ''])]
    public function testUnsetIssuersAreOmittedFromDefaultSettings(string $store, ?string $issuer): void
    {
        config(['passport.key_store' => $store, 'passport.issuer' => $issuer]);

        if ($store === 'database') {
            (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000007_create_oauth_signing_keys_table.php')->up();
            SigningKey::factory()->create();
        }

        $this->assertNull($this->app->make(ServerFactory::class)->settings()->issuer);
    }

    public function testSettingsCacheEvictsTheLeastRecentlyUsedEntryAndKeepsScopesSeparate(): void
    {
        $identifier = 'first';
        $resolver = m::mock(ServerSettingsResolver::class);
        $resolver->shouldReceive('current')->andReturnUsing(static function () use (&$identifier): string {
            return $identifier;
        });
        $key = new ServerKey(config('passport.public_key'), config('passport.private_key'));
        $resolver->shouldReceive('resolve')->with('first')->once()->andReturn(new ServerSettings([$key], scopes: ['first' => 'First']));
        $resolver->shouldReceive('resolve')->with('second')->twice()->andReturn(new ServerSettings([$key], scopes: ['second' => 'Second']));
        $resolver->shouldReceive('resolve')->with('third')->once()->andReturn(new ServerSettings([$key], scopes: ['third' => 'Third']));
        $this->app->instance(ServerSettingsResolver::class, $resolver);
        $servers = $this->app->make(ServerFactory::class);
        Passport::tokensCan(['global' => 'Global']);
        $first = $servers->entry();
        $this->assertSame(['global', 'first'], Passport::scopeIds());

        $identifier = 'second';
        $second = $servers->entry();
        $this->assertSame(['global', 'second'], Passport::scopeIds());
        $identifier = 'first';
        $this->assertSame($first, $servers->entry());
        $identifier = 'third';
        $servers->entry();
        $identifier = 'first';
        $this->assertSame($first, $servers->entry());
        $identifier = 'second';
        $this->assertNotSame($second, $servers->entry());
    }

    public function testPublicOnlySettingsExposeVerificationWithoutARequiredPrivateKey(): void
    {
        $resolver = m::mock(ServerSettingsResolver::class);
        $resolver->shouldReceive('current')->andReturn('verification');
        $resolver->shouldReceive('resolve')->once()->with('verification')->andReturn(new ServerSettings([
            new ServerKey(config('passport.public_key')),
        ]));
        $this->app->instance(ServerSettingsResolver::class, $resolver);
        $servers = $this->app->make(ServerFactory::class);
        $this->assertSame($servers->resourceServer(), $servers->resourceServer());
        $jwk = $servers->jwks()['keys'][0];
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertArrayNotHasKey('d', $jwk);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No active Passport signing key is available.');
        $servers->authorizationServer();
    }

    public function testDatabaseRefreshReadsOncePerIntervalAndRebuildsOnlyWhenKeysChange(): void
    {
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000007_create_oauth_signing_keys_table.php')->up();
        config(['passport.key_store' => 'database']);
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        SigningKey::factory()->create();
        $servers = $this->app->make(ServerFactory::class);
        DB::enableQueryLog();
        $entry = $servers->entry();
        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame($entry, $servers->entry());
        $this->assertCount(1, DB::getQueryLog());

        CarbonImmutable::setTestNow($now->addSeconds(60));
        $this->assertSame($entry, $servers->entry());
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();

        $material = (new KeyGenerator)->generate(2048);
        $waiting = SigningKey::forceCreate([
            'public_key' => $material['public'], 'private_key' => $material['private'],
            'activates_at' => $now->addSeconds(180),
        ]);
        $this->assertSame($entry, $servers->entry());
        CarbonImmutable::setTestNow($now->addSeconds(120));
        $replacement = $servers->entry();
        $this->assertNotSame($entry, $replacement);
        $this->assertCount(2, $servers->jwks()['keys']);

        $oldKey = $replacement->signingKey(CarbonImmutable::now());
        CarbonImmutable::setTestNow($waiting->activates_at);
        $this->assertSame($replacement, $servers->entry());
        $this->assertNotSame($oldKey, $replacement->signingKey(CarbonImmutable::now()));
    }

    public function testCustomSettingsPreserveStoredKeyRetentionAndDoNotPublishRolledBackBounds(): void
    {
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000007_create_oauth_signing_keys_table.php')->up();
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        $key = SigningKey::factory()->create();
        $repository = $this->app->make(SigningKeyRepository::class);
        $resolver = m::mock(ServerSettingsResolver::class);
        $resolver->shouldReceive('current')->andReturn('custom');
        $resolver->shouldReceive('resolve')->with('custom')->once()->andReturnUsing(
            static fn (): ServerSettings => new ServerSettings($repository->serverKeys()),
        );
        $this->app->instance(ServerSettingsResolver::class, $resolver);
        $servers = $this->app->make(ServerFactory::class);
        $entry = $servers->entry();
        $failure = new RuntimeException('Response failed.');

        try {
            TokenIssuance::run($servers, function () use ($servers, $key, $failure): void {
                $servers->signingKey(new DateInterval('PT1H'));
                $this->assertSame(3600, $key->fresh()->max_token_lifetime);

                throw $failure;
            });
            $this->fail('The response failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, $key->fresh()->max_token_lifetime);
        $this->assertSame(0, $entry->retentionBounds[$key->id]);
        $servers->signingKey(new DateInterval('PT2H'));
        $this->assertSame(7200, $key->fresh()->max_token_lifetime);
        $this->assertSame(7200, $entry->retentionBounds[$key->id]);
    }
}
