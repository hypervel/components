<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Auth\EloquentUserProviderCacheTagsTest;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RequiresAnyTagModeRedis;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Cache;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;

/**
 * End-to-end tests for tag support in the auth user lookup cache.
 *
 * Drives a real Redis store in any-mode (registered as the 'auth_redis'
 * cache store) so the tag index, bulk-flush, and the tagged/plain repo
 * round-trip can be exercised against actual Redis rather than mocks.
 *
 * Skipped if Redis isn't available or doesn't meet the any-mode
 * requirements (phpredis >= 6.3.0, Redis >= 8.0 / Valkey >= 9.0).
 */
#[WithMigration]
class EloquentUserProviderCacheTagsTest extends TestCase
{
    use InteractsWithRedis;
    use RefreshDatabase;
    use RequiresAnyTagModeRedis;

    protected const string DEFAULT_KEY_PREFIX = 'auth_users';

    protected const string STORE_NAME = 'auth_redis';

    protected const string STORE_PREFIX = 'auth_test:';

    protected const string PRIMARY_TAG = 'auth_users';

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('cache.stores.' . self::STORE_NAME, [
            'driver' => 'redis',
            'connection' => 'default',
            'prefix' => self::STORE_PREFIX,
            'tag_mode' => 'any',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipIfAnyTagModeUnsupported();
    }

    protected function afterRefreshingDatabase(): void
    {
        User::forceCreate([
            'name' => 'Primary User',
            'email' => 'primary@example.com',
            'password' => bcrypt('secret'),
        ]);
    }

    // ------------------------------------------------------------------
    // Round-trip correctness
    // ------------------------------------------------------------------

    public function testTaggedWriteIsReadableViaPlainKey(): void
    {
        $user = User::query()->first();
        $provider = $this->makeCachedProviderWithTags();

        $dbQueries = 0;
        $provider->withQuery(function () use (&$dbQueries): void {
            ++$dbQueries;
        });

        $first = $provider->retrieveById($user->getAuthIdentifier());
        $second = $provider->retrieveById($user->getAuthIdentifier());

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(1, $dbQueries, 'Second retrieveById() must hit the cache, not the database');
    }

    public function testTaggedNullSentinelIsReadableViaPlainKey(): void
    {
        $provider = $this->makeCachedProviderWithTags();

        $dbQueries = 0;
        $provider->withQuery(function () use (&$dbQueries): void {
            ++$dbQueries;
        });

        $first = $provider->retrieveById(99999);
        $second = $provider->retrieveById(99999);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $dbQueries, 'Cached null-sentinel read must not re-query the database');
    }

    // ------------------------------------------------------------------
    // Tag index + bulk flush
    // ------------------------------------------------------------------

    public function testTaggedPutPopulatesIndex(): void
    {
        $user = User::query()->first();
        $provider = $this->makeCachedProviderWithTags();

        $provider->retrieveById($user->getAuthIdentifier());

        // Any-mode tag hash fields are the logical cache key (no store prefix).
        $cacheKey = $this->buildKey($user->getAuthIdentifier());
        $tagEntries = $this->redisClient()->hGetAll($this->anyModeTagKey(self::PRIMARY_TAG));

        $this->assertIsArray($tagEntries);
        $this->assertArrayHasKey($cacheKey, $tagEntries);
    }

    public function testBulkFlushClearsAllTaggedEntries(): void
    {
        User::forceCreate(['name' => 'Second', 'email' => 'second@example.com', 'password' => bcrypt('x')]);
        User::forceCreate(['name' => 'Third', 'email' => 'third@example.com', 'password' => bcrypt('x')]);

        $provider = $this->makeCachedProviderWithTags();
        $users = User::query()->get();

        foreach ($users as $user) {
            $provider->retrieveById($user->getAuthIdentifier());
        }

        $cache = Cache::store(self::STORE_NAME);

        // Precondition: all users cached.
        foreach ($users as $user) {
            $this->assertNotNull($cache->get($this->buildKey($user->getAuthIdentifier())));
        }

        $cache->tags([self::PRIMARY_TAG])->flush();

        foreach ($users as $user) {
            $this->assertNull($cache->get($this->buildKey($user->getAuthIdentifier())));
        }
    }

    public function testBulkFlushDoesNotAffectUntaggedEntries(): void
    {
        $user = User::query()->first();
        $provider = $this->makeCachedProviderWithTags();
        $provider->retrieveById($user->getAuthIdentifier());

        $cache = Cache::store(self::STORE_NAME);
        $cache->put('other:value', 'keep-me', 300);

        $this->assertSame('keep-me', $cache->get('other:value'));

        $cache->tags([self::PRIMARY_TAG])->flush();

        $this->assertNull($cache->get($this->buildKey($user->getAuthIdentifier())));
        $this->assertSame('keep-me', $cache->get('other:value'));
    }

    public function testPerUserForgetWorksAlongsideTags(): void
    {
        User::forceCreate(['name' => 'Second', 'email' => 'second@example.com', 'password' => bcrypt('x')]);

        $provider = $this->makeCachedProviderWithTags();
        $users = User::query()->get();
        $first = $users->first();
        $second = $users->get(1);

        $provider->retrieveById($first->getAuthIdentifier());
        $provider->retrieveById($second->getAuthIdentifier());

        $provider->clearUserCache($first->getAuthIdentifier());

        $cache = Cache::store(self::STORE_NAME);

        $this->assertNull($cache->get($this->buildKey($first->getAuthIdentifier())));
        $this->assertNotNull($cache->get($this->buildKey($second->getAuthIdentifier())));
    }

    // ------------------------------------------------------------------
    // Dynamic tag resolver
    // ------------------------------------------------------------------

    public function testDynamicTagsAreAppliedToWrites(): void
    {
        EloquentUserProvider::resolveUserCacheTagsUsing(fn (): array => ['scope:a']);

        $user = User::query()->first();
        $provider = $this->makeCachedProviderWithTags();
        $provider->retrieveById($user->getAuthIdentifier());

        $cacheKey = $this->buildKey($user->getAuthIdentifier());
        $redis = $this->redisClient();

        $primaryEntries = $redis->hGetAll($this->anyModeTagKey(self::PRIMARY_TAG));
        $dynamicEntries = $redis->hGetAll($this->anyModeTagKey('scope:a'));

        $this->assertArrayHasKey($cacheKey, $primaryEntries);
        $this->assertArrayHasKey($cacheKey, $dynamicEntries);
    }

    public function testDynamicTagsLetAppFlushNarrower(): void
    {
        User::forceCreate(['name' => 'Second', 'email' => 'second@example.com', 'password' => bcrypt('x')]);

        $users = User::query()->get();
        $first = $users->first();
        $second = $users->get(1);

        $currentScope = 'scope:a';
        EloquentUserProvider::resolveUserCacheTagsUsing(function () use (&$currentScope): array {
            return [$currentScope];
        });

        $provider = $this->makeCachedProviderWithTags();

        $provider->retrieveById($first->getAuthIdentifier());

        $currentScope = 'scope:b';
        $provider->retrieveById($second->getAuthIdentifier());

        $cache = Cache::store(self::STORE_NAME);

        // Both should be cached before the flush.
        $this->assertNotNull($cache->get($this->buildKey($first->getAuthIdentifier())));
        $this->assertNotNull($cache->get($this->buildKey($second->getAuthIdentifier())));

        $cache->tags(['scope:a'])->flush();

        $this->assertNull($cache->get($this->buildKey($first->getAuthIdentifier())));
        $this->assertNotNull($cache->get($this->buildKey($second->getAuthIdentifier())));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeCachedProviderWithTags(array $tags = [self::PRIMARY_TAG]): EloquentUserProvider
    {
        $provider = new EloquentUserProvider($this->app['hash'], User::class);
        $provider->enableCache(self::STORE_NAME, tags: $tags);

        return $provider;
    }

    /**
     * The logical cache key the auth code operates on (no store prefix).
     */
    protected function buildKey(mixed $identifier): string
    {
        return self::DEFAULT_KEY_PREFIX . ':' . User::class . ':' . $identifier;
    }

    /**
     * Any-mode tag hash key: "{storePrefix}_any:tag:{tagName}:entries".
     */
    protected function anyModeTagKey(string $tagName): string
    {
        return self::STORE_PREFIX . '_any:tag:' . $tagName . ':entries';
    }
}
