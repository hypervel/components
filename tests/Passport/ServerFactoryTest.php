<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use DateInterval;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Contracts\ServerSettingsResolver;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerKey;
use Hypervel\Passport\ServerSettings;
use Hypervel\Passport\SigningKey;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use LogicException;
use Mockery as m;
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
