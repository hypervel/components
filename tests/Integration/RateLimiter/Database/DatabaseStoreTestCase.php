<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\RateLimiter\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\DatabaseStore;
use Hypervel\RateLimiter\KeyResolver;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\RateLimiter\Fixtures\RateLimiterStoreContract;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use UnexpectedValueException;

use function Hypervel\Coroutine\parallel;

abstract class DatabaseStoreTestCase extends DatabaseTestCase
{
    use RateLimiterStoreContract;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $connection = $config->string('database.default');

        $config->set("database.connections.{$connection}.pool.testing_enabled", true);
        $config->set("database.connections.{$connection}.pool.max_connections", 10);
        $config->set("database.connections.{$connection}.pool.heartbeat", -1);
    }

    public function testFixedWindowOperationsUseNumericDatabaseState(): void
    {
        $store = $this->store();
        $key = str_repeat('a', 32);
        $policy = Limit::perMinute(2);

        $first = $store->consume($key, $policy);
        $second = $store->consume($key, $policy);
        $denied = $store->consume($key, $policy);
        $row = DB::table('rate_limits')->where('key', $key)->first();

        $this->assertTrue($first->allowed());
        $this->assertSame(1, $first->remaining());
        $this->assertTrue($second->allowed());
        $this->assertSame(0, $second->remaining());
        $this->assertTrue($denied->denied());
        $this->assertSame(0, $denied->remaining());
        $this->assertSame(2, (int) $row->value);
        $this->assertSame((int) $row->available_at, (int) $row->expires_at);
    }

    public function testInspectingMissingStateDoesNotCreateARow(): void
    {
        $store = $this->store();
        $key = str_repeat('b', 32);

        $result = $store->inspect($key, Limit::perMinute(10));

        $this->assertTrue($result->allowed());
        $this->assertSame(10, $result->remaining());
        $this->assertSame(0, $result->resetAfter());
        $this->assertFalse(DB::table('rate_limits')->where('key', $key)->exists());
    }

    public function testLeakyBucketAndBackoffUseTheSharedCalculator(): void
    {
        $store = $this->store();
        $bucketKey = str_repeat('c', 32);
        $bucket = LeakyBucket::perMinute(60)->burst(1);

        $this->assertTrue($store->consume($bucketKey, $bucket)->allowed());
        $this->assertTrue($store->consume($bucketKey, $bucket)->denied());

        $backoffKey = str_repeat('d', 32);
        $backoff = Backoff::exponential(
            after: 2,
            initialDelay: 1,
            maxDelay: 4,
            resetAfter: 10,
        );

        $this->assertTrue($store->recordFailure($backoffKey, $backoff)->allowed());
        $this->assertTrue($store->recordFailure($backoffKey, $backoff)->denied());
        $this->assertTrue($store->inspect($backoffKey, $backoff)->denied());
    }

    public function testClearDeletesOnlyTheRequestedState(): void
    {
        $store = $this->store();
        $firstKey = str_repeat('e', 32);
        $secondKey = str_repeat('f', 32);
        $policy = Limit::perMinute(1);
        $store->consume($firstKey, $policy);
        $store->consume($secondKey, $policy);

        $this->assertTrue($store->clear($firstKey));
        $this->assertFalse($store->clear($firstKey));
        $this->assertFalse(DB::table('rate_limits')->where('key', $firstKey)->exists());
        $this->assertTrue(DB::table('rate_limits')->where('key', $secondKey)->exists());
    }

    public function testConfiguredTableUsesTheConnectionPrefix(): void
    {
        DB::setTablePrefix('limiter_');

        try {
            Schema::create('custom_rate_limits', function (Blueprint $table): void {
                $table->char('key', 32)->primary();
                $table->unsignedBigInteger('value')->default(0);
                $table->unsignedBigInteger('available_at')->default(0);
                $table->unsignedBigInteger('expires_at')->index();
            });

            $store = new DatabaseStore(
                $this->app->make(ConnectionResolverInterface::class),
                null,
                'custom_rate_limits',
            );
            $key = str_repeat('p', 32);

            $this->assertTrue($store->consume($key, Limit::perMinute(1))->allowed());
            $this->assertSame(1, (int) DB::table('custom_rate_limits')->where('key', $key)->value('value'));
        } finally {
            Schema::dropIfExists('custom_rate_limits');
            DB::setTablePrefix('');
        }
    }

    public function testPrunesExpiredStateInBoundedBatches(): void
    {
        $store = $this->store();

        foreach (range(1, 5) as $index) {
            DB::table('rate_limits')->insert([
                'key' => str_pad("expired{$index}", 32, 'x'),
                'value' => 1,
                'available_at' => 1,
                'expires_at' => 1,
            ]);
        }

        DB::table('rate_limits')->insert([
            'key' => str_repeat('l', 32),
            'value' => 1,
            'available_at' => AdmissionPolicy::MAX_INTEGER,
            'expires_at' => AdmissionPolicy::MAX_INTEGER,
        ]);

        $this->assertSame(5, $store->pruneExpired(2));
        $this->assertSame([str_repeat('l', 32)], DB::table('rate_limits')->pluck('key')->all());
    }

    public function testPruningDoesNotDeleteStateRenewedAfterSelection(): void
    {
        $key = str_repeat('r', 32);
        DB::table('rate_limits')->insert([
            'key' => $key,
            'value' => 1,
            'available_at' => 1,
            'expires_at' => 1,
        ]);
        $renewed = false;

        DB::listen(function (QueryExecuted $event) use ($key, &$renewed): void {
            $sql = strtolower($event->sql);

            if ($renewed || ! str_contains($sql, 'rate_limits') || ! str_contains($sql, 'select')) {
                return;
            }

            $renewed = true;

            DB::table('rate_limits')->where('key', $key)->update([
                'available_at' => AdmissionPolicy::MAX_INTEGER,
                'expires_at' => AdmissionPolicy::MAX_INTEGER,
            ]);
        });

        $this->assertSame(0, $this->store()->pruneExpired(1));
        $this->assertTrue($renewed);
        $this->assertSame(
            AdmissionPolicy::MAX_INTEGER,
            (int) DB::table('rate_limits')->where('key', $key)->value('expires_at'),
        );
    }

    public function testCorruptStateFailsClosed(): void
    {
        $key = str_repeat('g', 32);
        DB::table('rate_limits')->insert([
            'key' => $key,
            'value' => 1,
            'available_at' => AdmissionPolicy::MAX_INTEGER,
            'expires_at' => AdmissionPolicy::MAX_INTEGER - 1,
        ]);

        $this->expectException(UnexpectedValueException::class);

        $this->store()->consume($key, Limit::perMinute(10));
    }

    public function testConcurrentFirstUseAdmitsExactlyTheConfiguredCapacity(): void
    {
        $store = $this->store();
        $key = str_repeat('h', 32);
        $policy = Limit::perMinute(5);
        $operations = [];

        for ($index = 0; $index < 10; ++$index) {
            $operations[] = static fn (): bool => $store->consume($key, $policy)->allowed();
        }

        $results = parallel($operations);

        $this->assertSame(5, count(array_filter($results)));
        $this->assertSame(5, (int) DB::table('rate_limits')->where('key', $key)->value('value'));
    }

    public function testConcurrentExistingStateDoesNotLoseUpdates(): void
    {
        $store = $this->store();
        $key = str_repeat('i', 32);
        $policy = Limit::perMinute(5);
        $store->consume($key, $policy);
        $operations = [];

        for ($index = 0; $index < 8; ++$index) {
            $operations[] = static fn (): bool => $store->consume($key, $policy)->allowed();
        }

        $results = parallel($operations);

        $this->assertSame(4, count(array_filter($results)));
        $this->assertSame(5, (int) DB::table('rate_limits')->where('key', $key)->value('value'));
    }

    #[DataProvider('invalidPruneChunkSizes')]
    public function testRejectsInvalidPruneChunkSizes(int $chunkSize): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->pruneExpired($chunkSize);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidPruneChunkSizes(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above maximum' => [10_001],
        ];
    }

    /**
     * Create a database rate limiter store for the configured integration connection.
     */
    protected function store(): DatabaseStore
    {
        return new DatabaseStore(
            $this->app->make(ConnectionResolverInterface::class),
            null,
            'rate_limits',
        );
    }

    protected function rateLimiterStoreContract(): Limiter
    {
        return new Limiter(
            $this->store(),
            new KeyResolver('database-contract', static fn (): ?string => null),
        );
    }
}
