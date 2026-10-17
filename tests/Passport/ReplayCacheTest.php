<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\SessionStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Passport\ReplayCache;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

class ReplayCacheTest extends TestCase
{
    /**
     * Configure separate application and replay databases.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'passport' => require __DIR__ . '/../../src/passport/config/passport.php',
            'cache.default' => 'replay',
            'cache.stores.replay' => [
                'driver' => 'database',
                'connection' => 'replay',
                'table' => 'replays',
                'prefix' => '',
            ],
            'database.connections.replay' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
    }

    /**
     * Create the replay table without wrapping claims in a test transaction.
     */
    protected function setUpInCoroutine(): void
    {
        DB::connection('replay')->getSchemaBuilder()->create('replays', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['replay'])]
    public function testClaimsAreConsumedOnceUntilTheirAcceptanceWindowEnds(?string $store): void
    {
        config(['passport.replay.store' => $store]);
        $replays = $this->app->make(ReplayCache::class);
        $expiresAt = time() + 300;

        $this->assertTrue($replays->consume('client:assertion', $expiresAt));
        $this->assertFalse($replays->consume('client:assertion', $expiresAt));
        $this->assertTrue($replays->consume('other-client:assertion', $expiresAt));
        $this->assertFalse($replays->consume('expired', time() - 1));
        $this->assertSame(2, DB::connection('replay')->table('replays')->count());
        $this->assertGreaterThanOrEqual($expiresAt, DB::connection('replay')->table('replays')->min('expiration'));
    }

    public function testAnApplicationTransactionCannotRollBackAClaimOnTheDedicatedConnection(): void
    {
        $replays = $this->app->make(ReplayCache::class);
        DB::beginTransaction();

        try {
            $this->assertTrue($replays->consume('independent', time() + 300));
        } finally {
            DB::rollBack();
        }

        $this->assertFalse($replays->consume('independent', time() + 300));
    }

    public function testAnActiveCacheTransactionIsRejectedBeforeAClaimCanBeAccepted(): void
    {
        $connection = DB::connection('replay');
        $connection->beginTransaction();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('active transaction');
            $this->app->make(ReplayCache::class)->consume('rollback', time() + 300);
        } finally {
            $this->assertSame(0, $connection->table('replays')->count());
            $connection->rollBack();
        }
    }

    #[DataProvider('unsupportedStores')]
    public function testLocalAndSwitchingStoresCannotBeUsedForReplayProtection(string $store): void
    {
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('store')->with(null)->andReturn(new Repository(m::mock($store)));
        $replays = new ReplayCache($factory, $this->app->make(ConfigRepository::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('direct Redis or database');
        $replays->consume('assertion', time() + 300);
    }

    /**
     * List stores which cannot preserve a shared replay history.
     */
    public static function unsupportedStores(): array
    {
        return array_map(static fn (string $store): array => [$store], [
            ArrayStore::class,
            WorkerArrayStore::class,
            FileStore::class,
            NullStore::class,
            SessionStore::class,
            StackStore::class,
            FailoverStore::class,
        ]);
    }

    public function testBackendFailureDoesNotAcceptTheProof(): void
    {
        $failure = new RuntimeException('Replay store unavailable');
        $store = m::mock(RedisStore::class);
        $store->shouldReceive('add')->once()->andThrow($failure);
        $factory = m::mock(Factory::class);
        $factory->shouldReceive('store')->with(null)->andReturn(new Repository($store));

        $this->expectExceptionObject($failure);
        (new ReplayCache($factory, $this->app->make(ConfigRepository::class)))->consume('assertion', time() + 300);
    }
}
