<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Carbon\CarbonImmutable as BaseCarbonImmutable;
use DateInterval;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerKey;
use Hypervel\Passport\SigningKey;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Passport\Token;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class SigningKeyRepositoryTest extends TestCase
{
    /** @var list<array{private: string, public: string}> */
    protected static array $keys = [];

    protected CarbonImmutable $now;

    /**
     * Use a shared file lock store owned by this test class.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'passport' => require __DIR__ . '/../../src/passport/config/passport.php',
            'cache.default' => 'passport-keys-file',
            'cache.stores.passport-keys-file' => [
                'driver' => 'file', 'path' => $app->storagePath('framework/cache/data/passport-keys'),
            ],
        ]);
    }

    /**
     * Create key and token storage with reusable test key material.
     */
    protected function setUpInCoroutine(): void
    {
        foreach ([
            '2016_06_01_000002_create_oauth_access_tokens_table.php',
            '2016_06_01_000007_create_oauth_signing_keys_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../src/passport/database/migrations/' . $migration)->up();
        }

        $this->now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($this->now);

        if (self::$keys === []) {
            $generator = new KeyGenerator;
            self::$keys = [$generator->generate(2048), $generator->generate(2048), $generator->generate(2048)];
        }
    }

    /**
     * Remove the file locks and cache data owned by this class.
     */
    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store('passport-keys-file')->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testCreateIsIdempotentAndGeneratesBeforeTakingTheLockOrTransaction(): void
    {
        $generator = m::mock(KeyGenerator::class);
        $generator->shouldReceive('generate')->with(2048)->once()->andReturnUsing(function (): array {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse($this->app->make('cache')->store()->getStore()->lock('passport:signing-keys:default')->isLocked());

            return self::$keys[0];
        });
        $this->app->instance(KeyGenerator::class, $generator);
        $repository = $this->app->make(SigningKeyRepository::class);
        $key = $repository->create(2048);

        $this->assertNull($repository->create(2048));
        $this->assertSame(1, SigningKey::count());
        $this->assertSame(self::$keys[0]['private'], $key->private_key);
        $this->assertNotSame(self::$keys[0]['private'], $key->getRawOriginal('private_key'));
        $this->assertSame($this->now->getTimestamp(), $key->activates_at->getTimestamp());
        $this->assertSame($key->id, $repository->serverKeys()[0]->id);
    }

    public function testRotationKeepsWaitingKeysAndRetainsRetiredKeysUntilTheirDeadline(): void
    {
        $this->generator([0, 1]);
        $repository = $this->app->make(SigningKeyRepository::class);
        $first = $repository->create(2048);
        $first->forceFill(['max_token_lifetime' => 3600])->save();
        $second = $repository->rotate(2048);
        $this->assertSame($second->id, $repository->rotate(2048)->id);
        $this->assertSame($this->now->addSeconds(config()->integer('passport.key_activation_delay'))->getTimestamp(), $second->activates_at->getTimestamp());
        $this->assertCount(2, $repository->serverKeys());

        CarbonImmutable::setTestNow($second->activates_at->addSecond());
        $repository->prune();
        $projected = $repository->serverKeys();
        $this->assertCount(2, $projected);
        $this->assertNotNull($projected[0]->privateKey);
        $this->assertNull($projected[1]->privateKey);
        $this->assertSame($first->id, $projected[1]->id);
        $this->assertSame(3600, $projected[1]->maxTokenLifetime);

        CarbonImmutable::setTestNow($second->activates_at->addHour());
        $repository->prune();
        $this->assertSame($second->id, SigningKey::sole()->id);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testImportQueuesAfterWaitingKeysAndRejectsAnExistingThumbprint(bool $customDateClass): void
    {
        if ($customDateClass) {
            Date::useClass(BaseCarbonImmutable::class);
        }

        $this->generator([0, 1]);
        $repository = $this->app->make(SigningKeyRepository::class);
        $repository->create(2048);
        $waiting = $repository->rotate(2048);
        $imported = $repository->import(self::$keys[2]['private']);
        $this->assertSame($waiting->activates_at->addSeconds(config()->integer('passport.key_activation_delay'))->getTimestamp(), $imported->activates_at->getTimestamp());

        try {
            $repository->import(self::$keys[0]['private']);
            $this->fail('An existing key must not be imported again.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('This signing key is already present in the key set.', $exception->getMessage());
        }

        $this->assertSame(3, SigningKey::count());
    }

    public function testScheduledRotationWaitsUntilDueAndReusesPublishedKeys(): void
    {
        $this->generator([0, 1]);
        $repository = $this->app->make(SigningKeyRepository::class);
        $first = $repository->create(2048);
        $dueAt = $this->now->addDays(config()->integer('passport.key_rotation.days'));

        CarbonImmutable::setTestNow($dueAt->subSecond());
        $repository->rotateDueKeys(2048);
        $this->assertSame($first->id, SigningKey::sole()->id);

        CarbonImmutable::setTestNow($dueAt);
        $repository->rotateDueKeys(2048);
        $waiting = $repository->all()->first();
        $this->assertNotSame($first->id, $waiting->id);
        $this->assertSame($dueAt->addSeconds(config()->integer('passport.key_activation_delay'))->getTimestamp(), $waiting->activates_at->getTimestamp());

        $repository->rotateDueKeys(2048);
        $this->assertSame(2, SigningKey::count());
        $this->assertSame($waiting->id, $repository->all()->first()->id);

        CarbonImmutable::setTestNow($waiting->activates_at->addSecond());
        $repository->rotateDueKeys(2048);
        $this->assertSame($waiting->id, SigningKey::sole()->id);
    }

    public function testRevokeReplacesTheSigningKeyAtomically(): void
    {
        $this->generator([0, 1]);
        $repository = $this->app->make(SigningKeyRepository::class);
        $first = $repository->create(2048);
        $this->assertTrue($repository->revoke(ParsedKey::fromPublicKey($first->public_key)->getKeyId(), 2048));
        $replacement = SigningKey::sole();

        $this->assertNotSame($first->id, $replacement->id);
        $this->assertSame(self::$keys[1]['public'], $replacement->public_key);
        $this->assertSame($this->now->getTimestamp(), $replacement->activates_at->getTimestamp());
    }

    public function testFailedReplacementKeepsTheOriginalSigningKey(): void
    {
        $this->generator([0, 1]);
        $repository = $this->app->make(SigningKeyRepository::class);
        $first = $repository->create(2048);
        $failure = new RuntimeException('Key observer failed.');
        SigningKey::created(static function () use ($failure): void {
            throw $failure;
        });

        try {
            $repository->revoke(ParsedKey::fromPublicKey($first->public_key)->getKeyId(), 2048);
            $this->fail('The failed replacement must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($first->id, SigningKey::sole()->id);
        $this->assertSame(0, DB::transactionLevel());
    }

    #[TestWith([-1])]
    #[TestWith([1])]
    public function testRevokingANonSigningKeyDoesNotGenerateAReplacement(int $activationDays): void
    {
        $this->generator([0]);
        $repository = $this->app->make(SigningKeyRepository::class);
        $signing = $repository->create(2048);
        $other = SigningKey::forceCreate([
            'public_key' => self::$keys[1]['public'], 'private_key' => self::$keys[1]['private'],
            'activates_at' => $this->now->addDays($activationDays),
        ]);

        $this->assertTrue($repository->revoke(ParsedKey::fromPublicKey($other->public_key)->getKeyId(), 2048));
        $this->assertFalse($repository->revoke('unknown', 2048));
        $this->assertSame($signing->id, SigningKey::sole()->id);
    }

    #[TestWith([1])]
    #[TestWith([3])]
    public function testFileImportDeduplicatesKeysAndRetainsAlreadyIssuedTokens(int $retentionDays): void
    {
        Passport::tokensExpireIn(new DateInterval('PT15M'));
        Passport::personalAccessTokensExpireIn(new DateInterval('PT15M'));
        $factory = m::mock(ServerFactory::class);
        $factory->shouldReceive('fileKeys')->once()->andReturn([
            new ServerKey(self::$keys[0]['public'], self::$keys[0]['private']),
            new ServerKey(self::$keys[0]['public']),
            new ServerKey(self::$keys[1]['public']),
        ]);
        $this->app->instance(ServerFactory::class, $factory);
        Token::forceCreate(['id' => 'imported', 'client_id' => 'client', 'revoked' => false, 'expires_at' => $this->now->addDays(2)]);
        $this->generator([2]);
        $repository = $this->app->make(SigningKeyRepository::class);
        $keys = $repository->importFiles($this->now->addDays($retentionDays));

        $this->assertCount(2, $keys);
        $this->assertSame(2, SigningKey::count());
        $this->assertCount(1, $keys->filter(static fn (SigningKey $key): bool => $key->private_key !== null));

        foreach ($keys as $key) {
            $this->assertSame($this->now->addDays(max(2, $retentionDays))->getTimestamp(), $key->retain_until->getTimestamp());
        }

        $replacement = $repository->rotate(2048);
        CarbonImmutable::setTestNow($replacement->activates_at->addSecond());
        $repository->prune();
        $this->assertSame(3, SigningKey::count());

        CarbonImmutable::setTestNow($this->now->addDays(max(2, $retentionDays)));
        $repository->prune();
        $this->assertSame($replacement->id, SigningKey::sole()->id);
    }

    public function testFileImportRetainsTokensIssuedBeforeFileWorkersRestart(): void
    {
        Passport::tokensExpireIn(new DateInterval('PT15M'));
        Passport::personalAccessTokensExpireIn(new DateInterval('P1Y'));
        $factory = m::mock(ServerFactory::class);
        $factory->shouldReceive('fileKeys')->once()->andReturn([
            new ServerKey(self::$keys[0]['public'], self::$keys[0]['private']),
        ]);
        $this->app->instance(ServerFactory::class, $factory);
        $this->generator([1]);
        $repository = $this->app->make(SigningKeyRepository::class);
        $imported = $repository->importFiles()->sole();

        CarbonImmutable::setTestNow($this->now->addDay());
        $token = Token::forceCreate([
            'id' => 'issued-during-migration', 'client_id' => 'client', 'revoked' => false,
            'expires_at' => CarbonImmutable::now()->addYear(),
        ]);
        $this->assertNull($imported->retain_until);

        CarbonImmutable::setTestNow($this->now->addDays(90));
        $replacement = $repository->rotate(2048);
        CarbonImmutable::setTestNow($replacement->activates_at->addMinutes(16));
        $repository->prune();

        $this->assertNotNull($imported->fresh());
        $this->assertGreaterThanOrEqual($token->expires_at->getTimestamp(), $imported->fresh()->retires_at->getTimestamp());
        CarbonImmutable::setTestNow($replacement->activates_at->addDays(366));
        $repository->prune();
        $this->assertSame($replacement->id, SigningKey::sole()->id);
    }

    #[TestWith(['array'])]
    #[TestWith(['null'])]
    public function testManagementRequiresASharedLockStore(string $driver): void
    {
        config(['cache.default' => 'invalid-key-store', 'cache.stores.invalid-key-store' => ['driver' => $driver]]);
        $this->generator([]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Passport signing key management requires a shared cache store with atomic locks.');

        try {
            $this->app->make(SigningKeyRepository::class)->rotate(2048);
        } finally {
            $this->assertSame(0, SigningKey::count());
        }
    }

    public function testManagementRejectsAnAmbientTransactionBeforeGeneratingOrChangingKeys(): void
    {
        $generator = m::mock(KeyGenerator::class);
        $generator->shouldNotReceive('generate');
        $this->app->instance(KeyGenerator::class, $generator);
        $repository = $this->app->make(SigningKeyRepository::class);
        DB::beginTransaction();

        try {
            foreach ([
                fn () => $repository->create(), fn () => $repository->rotate(),
                fn () => $repository->rotateDueKeys(), fn () => $repository->prune(),
                fn () => $repository->revoke('key'), fn () => $repository->import(self::$keys[0]['private']),
                fn () => $repository->importFiles(),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Key management must reject an open application transaction.');
                } catch (LogicException $exception) {
                    $this->assertSame('Passport signing key management cannot run inside an application transaction.', $exception->getMessage());
                }
            }

            $this->assertSame(0, SigningKey::count());
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Provide distinct keys without regenerating RSA material in each test.
     *
     * @param list<int> $indices
     */
    protected function generator(array $indices): void
    {
        $generator = m::mock(KeyGenerator::class);

        foreach ($indices as $index) {
            $generator->shouldReceive('generate')->with(2048)->once()->ordered()->andReturn(self::$keys[$index]);
        }

        $this->app->instance(KeyGenerator::class, $generator);
    }
}
