<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Passport\Database\Postgres;

use DateInterval;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Engine\Channel;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\SigningKey;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\parallel;

#[RequiresDatabase('pgsql')]
#[ResetRefreshDatabaseState]
class SigningKeyConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<array{private: string, public: string}> */
    protected static array $keys = [];

    /**
     * Use independent database sessions and a shared lock store.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'passport' => require dirname(__DIR__, 5) . '/src/passport/config/passport.php',
            'passport.key_store' => 'database',
            'passport.key_activation_delay' => 120,
            'database.connections.pgsql.pool.testing_enabled' => true,
            'cache.default' => 'passport-key-locks',
            'cache.stores.passport-key-locks' => [
                'driver' => 'file', 'path' => $app->storagePath('framework/cache/data/passport-key-locks'),
            ],
        ]);

        if (self::$keys === []) {
            $generator = new KeyGenerator;
            self::$keys = [$generator->generate(2048), $generator->generate(2048), $generator->generate(2048)];
        }
    }

    /**
     * Create only the key table needed by management and retention.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        (require dirname(__DIR__, 5) . '/src/passport/database/migrations/2016_06_01_000007_create_oauth_signing_keys_table.php')->up();
    }

    /**
     * Remove this test's lock files.
     */
    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store('passport-key-locks')->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testConcurrentProvisioningCommitsOneKeyBeforeReleasingTheLock(): void
    {
        $ready = new Channel(2);
        $release = new Channel(2);
        $generator = m::mock(KeyGenerator::class);
        $generator->shouldReceive('generate')->with(2048)->twice()->andReturnUsing(function () use ($ready, $release): array {
            $this->assertSame(0, DB::transactionLevel());
            $ready->push(true);
            $this->assertTrue($release->pop(5));

            return self::$keys[0];
        });
        $this->app->instance(KeyGenerator::class, $generator);
        $repository = $this->app->make(SigningKeyRepository::class);

        [$first, $second] = parallel([
            fn () => $repository->create(2048),
            fn () => $repository->create(2048),
            function () use ($ready, $release): void {
                try {
                    $this->assertTrue($ready->pop(5));
                    $this->assertTrue($ready->pop(5));
                } finally {
                    $release->push(true);
                    $release->push(true);
                }
            },
        ]);

        $this->assertCount(1, array_filter([$first, $second]));
        $this->assertSame(1, SigningKey::count());
        $this->assertNull($repository->create(2048));
        $this->assertFalse($this->app->make('cache')->store()->getStore()->lock('passport:signing-keys:default')->isLocked());
    }

    public function testImportWaitsForRotationAndPublishesAfterItsWaitingKey(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        SigningKey::forceCreate([
            'public_key' => self::$keys[0]['public'], 'private_key' => self::$keys[0]['private'],
            'activates_at' => $now->subDay(),
        ]);
        $generator = m::mock(KeyGenerator::class);
        $generator->shouldReceive('generate')->with(2048)->once()->andReturn(self::$keys[1]);
        $this->app->instance(KeyGenerator::class, $generator);
        $repository = $this->app->make(SigningKeyRepository::class);
        $ready = new Channel(1);
        $importing = new Channel(1);
        SigningKey::creating(function (SigningKey $key) use ($ready, $importing): void {
            if ($key->public_key === self::$keys[1]['public']) {
                $ready->push(true);
                $this->assertTrue($importing->pop(5));
                usleep(10000);
            }
        });

        [$rotated, $imported] = parallel([
            fn () => $repository->rotate(2048),
            function () use ($repository, $ready, $importing): SigningKey {
                $this->assertTrue($ready->pop(5));
                $importing->push(true);

                return $repository->import(self::$keys[2]['private']);
            },
        ]);

        $this->assertSame(3, SigningKey::count());
        $this->assertSame($now->addSeconds(120)->getTimestamp(), $rotated->activates_at->getTimestamp());
        $this->assertSame($now->addSeconds(240)->getTimestamp(), $imported->activates_at->getTimestamp());
        $this->assertSame($imported->id, $repository->serverKeys()[0]->id);
        $this->assertSame($imported->id, $repository->rotate(2048)->id);
    }

    public function testPruningCannotDeleteAKeyWhoseRetentionWasRaisedAfterItsRead(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        $original = SigningKey::forceCreate([
            'public_key' => self::$keys[0]['public'], 'private_key' => self::$keys[0]['private'],
            'activates_at' => $now->subDay(),
        ]);
        $generator = m::mock(KeyGenerator::class);
        $generator->shouldReceive('generate')->with(2048)->once()->andReturn(self::$keys[1]);
        $this->app->instance(KeyGenerator::class, $generator);
        $repository = $this->app->make(SigningKeyRepository::class);
        $servers = $this->app->make(ServerFactory::class);
        $servers->entry();
        $pinned = new Channel(1);
        $pruning = new Channel(1);
        $raised = new Channel(1);
        $intercept = false;

        SigningKey::retrieved(function (SigningKey $key) use ($original, $pruning, $raised, &$intercept): void {
            if ($intercept && $key->id === $original->id) {
                $intercept = false;
                $pruning->push(true);
                $this->assertTrue($raised->pop(5));
            }
        });

        [$keyId] = parallel([
            function () use ($servers, $pinned, $pruning, $raised): string {
                try {
                    return TokenIssuance::run($servers, function () use ($servers, $pinned, $pruning): string {
                        $pinned->push(true);
                        $this->assertTrue($pruning->pop(5));

                        return $servers->signingKey(new DateInterval('PT1H'))->getKeyId();
                    });
                } finally {
                    $raised->push(true);
                }
            },
            function () use ($repository, $now, $pinned, &$intercept): void {
                $this->assertTrue($pinned->pop(5));
                $repository->rotate(2048);
                CarbonImmutable::setTestNow($now->addSeconds(121));
                $intercept = true;
                $repository->prune();
            },
        ]);

        $this->assertSame(3721, $original->fresh()->max_token_lifetime);
        $this->assertSame(2, SigningKey::count());
        $this->assertContains($keyId, array_column($servers->jwks()['keys'], 'kid'));
        $repository->prune();
        $this->assertSame($now->addSeconds(120 + 3721)->getTimestamp(), $original->fresh()->retires_at->getTimestamp());
    }
}
