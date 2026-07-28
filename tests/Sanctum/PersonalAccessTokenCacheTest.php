<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\ServiceProvider;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Fixtures\TestUser;
use Mockery as m;

class PersonalAccessTokenCacheTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected bool $tokenCacheEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
    }

    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store('sanctum-file')->flush();
            $this->app->make('cache')->store('0')->flush();
        } finally {
            parent::tearDown();
        }
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SanctumServiceProvider::class,
            PersonalAccessTokenCacheTestServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set([
            'cache.default' => 'sanctum-file',
            'cache.serializable_classes' => false,
            'cache.stores.sanctum-file' => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/data/sanctum'),
            ],
            'cache.stores.0' => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/data/sanctum-zero'),
            ],
            'auth.guards.sanctum' => [
                'driver' => 'sanctum',
                'provider' => 'users',
                'session_guards' => [],
            ],
            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model' => TestUser::class,
            ],
            'sanctum.cache.enabled' => $this->tokenCacheEnabled,
        ]);
    }

    protected function useStringKeyPersonalAccessTokenModel(ApplicationContract $app): void
    {
        $app->make('config')->set(
            'sanctum.testing_personal_access_token_model',
            StringKeyPersonalAccessToken::class,
        );
    }

    protected function useEagerTokenablePersonalAccessTokenModel(ApplicationContract $app): void
    {
        $app->make('config')->set(
            'sanctum.testing_personal_access_token_model',
            EagerTokenablePersonalAccessToken::class,
        );
    }

    protected function disableTokenCache(ApplicationContract $app): void
    {
        $this->tokenCacheEnabled = false;
    }

    protected function useZeroTokenCacheStore(ApplicationContract $app): void
    {
        $app->make('config')->set('sanctum.cache.store', '0');
    }

    protected function useDefaultTokenCacheStoreFromEmptyName(ApplicationContract $app): void
    {
        $app->make('config')->set('sanctum.cache.store', '');
    }

    /**
     * Get the migrations to run for the test.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => [
                __DIR__ . '/../../src/sanctum/database/migrations',
            ],
        ];
    }

    public function testInvalidTokenIdCachesNullResult(): void
    {
        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findToken('999|missing'));
        $this->assertSame(1, $this->countQueriesForTable('personal_access_tokens'));
        $this->assertSame(NullSentinel::VALUE, $this->cacheRepository()->getRaw('sanctum:999'));
        $this->assertNull($this->cacheRepository()->get('sanctum:999'));

        DB::flushQueryLog();

        $this->assertNull(PersonalAccessToken::findToken('999|missing'));
        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
    }

    public function testNonNumericTokenIdForIntegerKeyModelDoesNotQueryOrCache(): void
    {
        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findToken('abc|missing'));

        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
        $this->assertNull($this->cacheRepository()->getRaw('sanctum:abc'));
    }

    public function testEmptyTokenIdDoesNotQueryOrCache(): void
    {
        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findToken('|missing'));

        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
        $this->assertNull($this->cacheRepository()->getRaw('sanctum:'));
    }

    #[DefineEnvironment('useStringKeyPersonalAccessTokenModel')]
    public function testStringKeyTokenModelAcceptsNonNumericTokenId(): void
    {
        $this->createStringKeyTokenTable();

        $token = StringKeyPersonalAccessToken::forceCreate([
            'id' => 'token_01',
            'tokenable_type' => TestUser::class,
            'tokenable_id' => TestUser::create([
                'name' => 'Test User',
                'email' => 'string-key@example.com',
                'password' => password_hash('password', PASSWORD_DEFAULT),
            ])->getKey(),
            'name' => 'Test Token',
            'token' => hash('sha256', 'secret'),
            'abilities' => ['*'],
        ]);

        DB::enableQueryLog();

        $this->assertTrue($token->is(StringKeyPersonalAccessToken::findToken('token_01|secret')));
        $this->assertSame(1, $this->countQueriesForTable('string_key_personal_access_tokens'));

        DB::flushQueryLog();

        $this->assertTrue($token->is(StringKeyPersonalAccessToken::findToken('token_01|secret')));
        $this->assertSame(0, $this->countQueriesForTable('string_key_personal_access_tokens'));
    }

    public function testPlainTokenLookupIsNotSupported(): void
    {
        $this->createToken();

        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findToken('secret'));
        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
    }

    public function testValidTokenIsCached(): void
    {
        $token = $this->createToken();

        DB::enableQueryLog();

        $foundToken = PersonalAccessToken::findToken($token->id . '|secret');

        $this->assertNotNull($foundToken);
        $this->assertTrue($token->is($foundToken));
        $this->assertFalse($foundToken->relationLoaded('tokenable'));
        $this->assertSame(1, $this->countQueriesForTable('personal_access_tokens'));

        DB::flushQueryLog();

        $foundToken = PersonalAccessToken::findToken($token->id . '|secret');

        $this->assertNotNull($foundToken);
        $this->assertTrue($token->is($foundToken));
        $this->assertFalse($foundToken->relationLoaded('tokenable'));
        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
    }

    #[DefineEnvironment('useEagerTokenablePersonalAccessTokenModel')]
    public function testCachedTokenNeverRetainsEagerLoadedTokenable(): void
    {
        $token = $this->createToken();

        DB::enableQueryLog();

        $foundToken = EagerTokenablePersonalAccessToken::findToken($token->id . '|secret');

        $this->assertNotNull($foundToken);
        $this->assertFalse($foundToken->relationLoaded('tokenable'));
        $this->assertSame(1, $this->countQueriesForTable('personal_access_tokens'));
        $this->assertSame(1, $this->countQueriesForTable('users'));

        DB::flushQueryLog();

        $foundToken = EagerTokenablePersonalAccessToken::findToken($token->id . '|secret');

        $this->assertNotNull($foundToken);
        $this->assertFalse($foundToken->relationLoaded('tokenable'));
        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
        $this->assertSame(0, $this->countQueriesForTable('users'));
    }

    public function testFindingValidTokenDoesNotUpdateLastUsedAt(): void
    {
        $token = $this->createToken();

        $foundToken = PersonalAccessToken::findToken($token->id . '|secret');

        $this->assertNotNull($foundToken);
        $this->assertNull($foundToken->last_used_at);
        $this->assertNull($token->fresh()->last_used_at);
    }

    public function testFindingTokenWithBadHashDoesNotUpdateLastUsedAt(): void
    {
        $token = $this->createToken();

        $this->assertNull(PersonalAccessToken::findToken($token->id . '|wrong-secret'));
        $this->assertNull($token->fresh()->last_used_at);
    }

    #[DefineEnvironment('disableTokenCache')]
    public function testLastUsedAtIsWrittenEveryTimeWhenCachingIsDisabled(): void
    {
        $this->freezeTime();

        $token = $this->createToken();
        $cacheManager = $this->app->make(CacheManager::class);
        $cacheDouble = m::mock(CacheManager::class);
        $cacheDouble->shouldNotReceive('store');
        $this->app->instance('cache', $cacheDouble);

        try {
            $token->updateLastUsedAt();
            $firstLastUsedAt = $token->fresh()->last_used_at;

            $this->travel(1)->second();
            $token->updateLastUsedAt();

            $this->assertTrue($token->fresh()->last_used_at->isAfter($firstLastUsedAt));
        } finally {
            $this->app->instance('cache', $cacheManager);
        }
    }

    public function testCachedLastUsedAtUpdateIsSkippedWithinIntervalWithoutTouchingConnection(): void
    {
        $this->freezeTime();

        $token = $this->createToken();
        $lastUsedAt = now()->subSeconds(30);
        $token->forceFill(['last_used_at' => $lastUsedAt])->save();
        $token = $token->fresh();
        $lastUsedAt = $token->last_used_at;

        $connection = $token->getConnection();
        $connection->setRecordModificationState(false);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $token->updateLastUsedAt();

        $this->assertEmpty(DB::getQueryLog());
        $this->assertFalse($connection->hasModifiedRecords());
        $this->assertTrue($token->last_used_at->equalTo($lastUsedAt));
    }

    public function testCachedLastUsedAtUpdateRunsAfterIntervalAndRefreshesCache(): void
    {
        $now = $this->freezeTime();

        $token = $this->createToken();
        $token->forceFill(['last_used_at' => $now->subSeconds(301)])->save();
        $token = $token->fresh();
        $tokenable = PersonalAccessToken::findTokenable($token);

        $this->assertInstanceOf(TestUser::class, $tokenable);
        $token->setRelation('customRelation', $tokenable);

        $connection = $token->getConnection();
        $connection->setRecordModificationState(false);

        $token->updateLastUsedAt();

        $this->assertFalse($connection->hasModifiedRecords());
        $this->assertSame($tokenable, $token->getRelation('tokenable'));
        $this->assertSame(
            $now->format('Y-m-d H:i:s'),
            $token->fresh()->last_used_at->format('Y-m-d H:i:s'),
        );

        $cachedToken = $this->cacheRepository()->get("sanctum:{$token->id}");
        $this->assertInstanceOf(PersonalAccessToken::class, $cachedToken);
        $this->assertFalse($cachedToken->relationLoaded('tokenable'));
        $this->assertTrue($cachedToken->relationLoaded('customRelation'));
        $this->assertInstanceOf(TestUser::class, $cachedToken->getRelation('customRelation'));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
        $this->assertSame(
            $now->format('Y-m-d H:i:s'),
            $cachedToken->last_used_at->format('Y-m-d H:i:s'),
        );
    }

    public function testLastUsedAtAuditWriteForgetsOnlyTokenEntry(): void
    {
        $token = $this->createToken();
        $foundToken = PersonalAccessToken::findToken($token->id . '|secret');

        $this->assertNotNull($foundToken);
        $this->assertInstanceOf(TestUser::class, PersonalAccessToken::findTokenable($foundToken));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));

        $token->forceFill(['last_used_at' => now()])->save();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
    }

    public function testSuccessfulLastUsedAtUpdatePreservesModifiedConnectionState(): void
    {
        $token = $this->createToken();
        $connection = $token->getConnection();
        $connection->setRecordModificationState(true);

        $token->updateLastUsedAt();

        $this->assertTrue($connection->hasModifiedRecords());
    }

    public function testCancelledLastUsedAtUpdateRestoresAttributeWithoutRefreshingCache(): void
    {
        $this->freezeTime();

        $token = $this->createToken();
        $lastUsedAt = now()->subHour();
        $token->forceFill(['last_used_at' => $lastUsedAt])->save();
        $token = $token->fresh();
        $lastUsedAt = $token->last_used_at;

        $this->cacheRepository()->put("sanctum:{$token->id}", $token, 300);

        PersonalAccessToken::updating(function (PersonalAccessToken $token): false {
            $token->getConnection()->recordsHaveBeenModified();

            return false;
        });

        $token->updateLastUsedAt();

        $this->assertTrue($token->last_used_at->equalTo($lastUsedAt));
        $this->assertTrue($token->fresh()->last_used_at->equalTo($lastUsedAt));
        $this->assertTrue($token->getConnection()->hasModifiedRecords());
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
    }

    public function testMissingTokenableIsNotCached(): void
    {
        $token = $this->createToken();
        $tokenableId = $token->tokenable_id;

        TestUser::query()->whereKey($tokenableId)->delete();

        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id)));
        $this->assertSame(1, $this->countQueriesForTable('users'));
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
        $this->assertNull($this->cacheRepository()->get("sanctum:{$token->id}:tokenable"));

        TestUser::forceCreate([
            'id' => $tokenableId,
            'name' => 'Restored User',
            'email' => 'restored@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        DB::flushQueryLog();

        $tokenable = PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id));

        $this->assertInstanceOf(TestUser::class, $tokenable);
        $this->assertSame($tokenableId, $tokenable->getKey());
        $this->assertSame(1, $this->countQueriesForTable('users'));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
    }

    public function testInvalidCachedTokenableIsRefreshed(): void
    {
        $token = $this->createToken();
        $cacheKey = "sanctum:{$token->id}:tokenable";

        $this->assertTrue($this->cacheRepository()->put($cacheKey, 'invalid', 300));

        DB::enableQueryLog();

        $tokenable = PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id));

        $this->assertInstanceOf(TestUser::class, $tokenable);
        $this->assertSame($token->tokenable_id, $tokenable->getKey());
        $this->assertSame(1, $this->countQueriesForTable('users'));
        $this->assertInstanceOf(TestUser::class, $this->cacheRepository()->get($cacheKey));
    }

    public function testValidTokenableIsCached(): void
    {
        $token = $this->createToken();
        $accessToken = PersonalAccessToken::findOrFail($token->id);

        DB::enableQueryLog();

        $tokenable = PersonalAccessToken::findTokenable($accessToken);
        $this->assertInstanceOf(TestUser::class, $tokenable);
        $this->assertSame($token->tokenable_id, $tokenable->getKey());
        $this->assertSame($tokenable, $accessToken->getRelation('tokenable'));
        $this->assertSame(1, $this->countQueriesForTable('users'));

        DB::flushQueryLog();

        $this->assertSame($tokenable, PersonalAccessToken::findTokenable($accessToken));
        $this->assertSame(0, $this->countQueriesForTable('users'));

        $accessToken = PersonalAccessToken::findOrFail($token->id);
        $cachedTokenable = PersonalAccessToken::findTokenable($accessToken);

        $this->assertInstanceOf(TestUser::class, $cachedTokenable);
        $this->assertSame($token->tokenable_id, $cachedTokenable->getKey());
        $this->assertSame($cachedTokenable, $accessToken->getRelation('tokenable'));
        $this->assertSame(0, $this->countQueriesForTable('users'));
    }

    public function testClearTokenCacheForgetsTokenAndTokenableEntries(): void
    {
        $token = $this->createToken();
        $foundToken = PersonalAccessToken::findOrFail($token->id);

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertTrue($token->tokenable->is(PersonalAccessToken::findTokenable($foundToken)));

        PersonalAccessToken::clearTokenCache($token->id);

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
    }

    #[DefineEnvironment('useZeroTokenCacheStore')]
    public function testTokenCacheStorePreservesZeroName(): void
    {
        $defaultStore = $this->app->make('cache')->store();
        $zeroStore = $this->app->make('cache')->store('0');

        $defaultStore->put('sanctum:1', 'default', 60);
        $zeroStore->put('sanctum:1', 'zero', 60);

        PersonalAccessToken::clearTokenCache(1);

        $this->assertSame('default', $defaultStore->get('sanctum:1'));
        $this->assertNull($zeroStore->get('sanctum:1'));
    }

    #[DefineEnvironment('useDefaultTokenCacheStoreFromEmptyName')]
    public function testEmptyTokenCacheStoreNameUsesTheDefaultStore(): void
    {
        $defaultStore = $this->app->make('cache')->store();
        $zeroStore = $this->app->make('cache')->store('0');

        $defaultStore->put('sanctum:2', 'default', 60);
        $zeroStore->put('sanctum:2', 'zero', 60);

        PersonalAccessToken::clearTokenCache(2);

        $this->assertNull($defaultStore->get('sanctum:2'));
        $this->assertSame('zero', $zeroStore->get('sanctum:2'));
    }

    public function testUpdatingTokenForgetsTokenAndPositiveTokenableCacheEntries(): void
    {
        $token = $this->createToken();
        $foundToken = PersonalAccessToken::findOrFail($token->id);

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertTrue($token->tokenable->is(PersonalAccessToken::findTokenable($foundToken)));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));

        $token->forceFill(['name' => 'Updated Token'])->save();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
    }

    public function testDeletingTokenForgetsTokenAndTokenableCacheEntries(): void
    {
        $token = $this->createToken();
        $foundToken = PersonalAccessToken::findOrFail($token->id);

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertTrue($token->tokenable->is(PersonalAccessToken::findTokenable($foundToken)));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));

        $token->delete();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
    }

    /**
     * Create the users table for testing.
     */
    protected function createUsersTable(): void
    {
        $this->app->make('db')->connection()->getSchemaBuilder()->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    /**
     * Create the string-key personal access token table for testing.
     */
    protected function createStringKeyTokenTable(): void
    {
        $this->app->make('db')->connection()->getSchemaBuilder()->create('string_key_personal_access_tokens', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Create a user token.
     */
    protected function createToken(): PersonalAccessToken
    {
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);

        return $user->tokens()->create([
            'name' => 'Test Token',
            'token' => hash('sha256', 'secret'),
            'abilities' => ['*'],
        ]);
    }

    /**
     * Get the cache repository used by Sanctum.
     */
    protected function cacheRepository(): CacheRepository
    {
        $repository = $this->app->make('cache')->store();

        $this->assertInstanceOf(CacheRepository::class, $repository);

        return $repository;
    }

    /**
     * Count logged queries for a table.
     */
    protected function countQueriesForTable(string $table): int
    {
        return count(array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with(strtolower($query['query'] ?? ''), 'select')
                && str_contains($query['query'] ?? '', $table)
        ));
    }
}

class StringKeyPersonalAccessToken extends PersonalAccessToken
{
    protected ?string $table = 'string_key_personal_access_tokens';

    protected string $keyType = 'string';

    public bool $incrementing = false;
}

class EagerTokenablePersonalAccessToken extends PersonalAccessToken
{
    protected array $with = ['tokenable'];
}

class PersonalAccessTokenCacheTestServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the test service provider.
     */
    public function boot(): void
    {
        $model = $this->app->make('config')->get('sanctum.testing_personal_access_token_model');

        if (is_string($model)) {
            Sanctum::usePersonalAccessTokenModel($model);
        }
    }
}
