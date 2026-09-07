<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Events\QueryExecuted;
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
use RuntimeException;
use UnitEnum;

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
            'database.connections.sanctum_secondary' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
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

    protected function useNamespacedPersonalAccessTokenModel(ApplicationContract $app): void
    {
        $app->make('config')->set(
            'sanctum.testing_personal_access_token_model',
            NamespacedPersonalAccessToken::class,
        );
    }

    protected function useSoftDeletingPersonalAccessTokenModel(ApplicationContract $app): void
    {
        $app->make('config')->set(
            'sanctum.testing_personal_access_token_model',
            SoftDeletingPersonalAccessToken::class,
        );
    }

    protected function useTimestampDisabledPersonalAccessTokenModel(ApplicationContract $app): void
    {
        $app->make('config')->set(
            'sanctum.testing_personal_access_token_model',
            TimestampDisabledPersonalAccessToken::class,
        );
    }

    protected function useCustomTimestampPersonalAccessTokenModel(ApplicationContract $app): void
    {
        $app->make('config')->set(
            'sanctum.testing_personal_access_token_model',
            CustomTimestampPersonalAccessToken::class,
        );
    }

    protected function useSecondaryConnectionPersonalAccessTokenModel(ApplicationContract $app): void
    {
        $app->make('config')->set(
            'sanctum.testing_personal_access_token_model',
            SecondaryConnectionPersonalAccessToken::class,
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

    protected function usePartialTokenCacheConfig(ApplicationContract $app): void
    {
        $app->make('config')->set('sanctum.cache', ['enabled' => true]);
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
        $this->assertNull($this->modelCacheValue('sanctum:999'));

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

    public function testEmptyPlainTokenDoesNotQueryOrCache(): void
    {
        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findToken('1|'));

        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
        $this->assertNull($this->cacheRepository()->getRaw('sanctum:1'));
    }

    public function testOverflowingIntegerTokenIdDoesNotQueryOrCache(): void
    {
        $id = PHP_INT_MAX . '0';
        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findToken("{$id}|missing"));

        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$id}"));
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

    #[DefineEnvironment('useStringKeyPersonalAccessTokenModel')]
    public function testStringKeyTokenRelationDeleteInvalidatesTheSelectedToken(): void
    {
        $this->createStringKeyTokenTable();
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'string-relation@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        $token = $user->tokens()->forceCreate([
            'id' => 'token_01',
            'name' => 'Test Token',
            'token' => hash('sha256', 'secret'),
            'abilities' => ['*'],
        ]);
        $foundToken = StringKeyPersonalAccessToken::findToken('token_01|secret');
        $this->assertInstanceOf(StringKeyPersonalAccessToken::class, $foundToken);
        $this->assertInstanceOf(TestUser::class, StringKeyPersonalAccessToken::findTokenable($foundToken));

        $this->assertSame(1, $user->tokens()->delete());

        $this->assertDatabaseMissing('string_key_personal_access_tokens', ['id' => $token->id]);
        $this->assertNull($this->cacheRepository()->getRaw('sanctum:token_01'));
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

    public function testCreatingTokenClearsAPreexistingNegativeEntry(): void
    {
        $this->assertNull(PersonalAccessToken::findToken('1|missing'));

        $this->assertNull($this->modelCacheValue('sanctum:1'));

        $token = $this->createToken();

        $this->assertSame(1, $token->id);
        $this->assertNull($this->cacheRepository()->getRaw('sanctum:1'));
        $this->assertTrue($token->is(PersonalAccessToken::findToken('1|secret')));
    }

    public function testCreatingTokenInvalidatesNegativeCacheOnlyAfterOuterCommit(): void
    {
        $this->assertNull(PersonalAccessToken::findToken('1|missing'));

        DB::transaction(function (): void {
            DB::transaction(function (): void {
                $this->createToken();

                $this->assertNull($this->modelCacheValue('sanctum:1'));
            });

            $this->assertNull($this->modelCacheValue('sanctum:1'));
        });

        $this->assertNull($this->cacheRepository()->getRaw('sanctum:1'));
    }

    public function testRolledBackTokenCreationKeepsTheCommittedNegativeCacheEntry(): void
    {
        $this->assertNull(PersonalAccessToken::findToken('1|missing'));

        try {
            DB::transaction(function (): never {
                $this->createToken();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Ignore the expected rollback exception.
        }

        $this->assertNull($this->modelCacheValue('sanctum:1'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => 1]);
    }

    public function testCancelledTokenCreationDoesNotInvalidateNegativeCache(): void
    {
        $this->assertNull(PersonalAccessToken::findToken('1|missing'));
        PersonalAccessToken::creating(static fn (): false => false);

        $this->createToken();

        $this->assertNull($this->modelCacheValue('sanctum:1'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => 1]);
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

    public function testCachedLastUsedAtUpdateRunsAfterIntervalAndInvalidatesTokenCache(): void
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

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));

        $cachedToken = PersonalAccessToken::findToken("{$token->id}|secret");
        $this->assertInstanceOf(PersonalAccessToken::class, $cachedToken);
        $this->assertFalse($cachedToken->relationLoaded('tokenable'));
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
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));

        $token->forceFill(['last_used_at' => now()])->save();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    #[DefineEnvironment('useTimestampDisabledPersonalAccessTokenModel')]
    public function testLastUsedAtAuditWriteWithoutModelTimestampsForgetsOnlyTokenEntry(): void
    {
        $token = $this->createToken();
        $this->assertInstanceOf(TimestampDisabledPersonalAccessToken::class, $token);
        $foundToken = TimestampDisabledPersonalAccessToken::findToken($token->id . '|secret');
        $this->assertInstanceOf(TimestampDisabledPersonalAccessToken::class, $foundToken);
        $this->assertInstanceOf(
            TestUser::class,
            TimestampDisabledPersonalAccessToken::findTokenable($foundToken),
        );

        $token->forceFill(['last_used_at' => now()])->save();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    #[DefineEnvironment('useCustomTimestampPersonalAccessTokenModel')]
    public function testLastUsedAtAuditWriteWithCustomUpdatedAtColumnForgetsOnlyTokenEntry(): void
    {
        $this->app->make('db')->connection()->getSchemaBuilder()->table(
            'personal_access_tokens',
            static fn (Blueprint $table) => $table->timestamp('modified_at')->nullable(),
        );
        $token = $this->createToken();
        $this->assertInstanceOf(CustomTimestampPersonalAccessToken::class, $token);
        $foundToken = CustomTimestampPersonalAccessToken::findToken($token->id . '|secret');
        $this->assertInstanceOf(CustomTimestampPersonalAccessToken::class, $foundToken);
        $this->assertInstanceOf(TestUser::class, CustomTimestampPersonalAccessToken::findTokenable($foundToken));

        $token->forceFill(['last_used_at' => now()])->save();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testLastUsedAtCacheInvalidationWaitsForCommit(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        DB::transaction(function () use ($token): void {
            $token->updateLastUsedAt();

            $cachedToken = $this->modelCacheValue("sanctum:{$token->id}");
            $this->assertInstanceOf(PersonalAccessToken::class, $cachedToken);
            $this->assertNull($cachedToken->last_used_at);
        });

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testRolledBackLastUsedAtUpdateKeepsTheCommittedCacheEntry(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        try {
            DB::transaction(function () use ($token): never {
                $token->updateLastUsedAt();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Ignore the expected rollback exception.
        }

        $cachedToken = $this->modelCacheValue("sanctum:{$token->id}");
        $this->assertInstanceOf(PersonalAccessToken::class, $cachedToken);
        $this->assertNull($cachedToken->last_used_at);
        $this->assertNull($token->fresh()->last_used_at);
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
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
        $this->assertInstanceOf(
            PersonalAccessToken::class,
            $this->cacheRepository()->getRaw("sanctum:{$token->id}"),
        );
    }

    public function testMissingTokenableIsNotCached(): void
    {
        $token = $this->createToken();
        $tokenableId = $token->tokenable_id;

        TestUser::query()->whereKey($tokenableId)->delete();

        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id)));
        $this->assertSame(1, $this->countQueriesForTable('users'));
        $this->assertNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));

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
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testInvalidCachedTokenableIsRefreshed(): void
    {
        $token = $this->createToken();
        $cacheKey = $this->tokenableCacheKey($token);

        $this->assertTrue($this->cacheRepository()->put($cacheKey, 'invalid', 300));

        DB::enableQueryLog();

        $tokenable = PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id));

        $this->assertInstanceOf(TestUser::class, $tokenable);
        $this->assertSame($token->tokenable_id, $tokenable->getKey());
        $this->assertSame(1, $this->countQueriesForTable('users'));
        $this->assertInstanceOf(TestUser::class, $this->modelCacheValue($cacheKey));
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

    public function testTokensForTheSameOwnerShareOneTokenableCacheEntry(): void
    {
        $first = $this->createToken();
        $second = $first->tokenable->tokens()->create([
            'name' => 'Second Token',
            'token' => hash('sha256', 'second-secret'),
            'abilities' => ['*'],
        ]);

        DB::enableQueryLog();

        $this->assertInstanceOf(TestUser::class, PersonalAccessToken::findTokenable($first->fresh()));
        $this->assertSame(1, $this->countQueriesForTable('users'));

        DB::flushQueryLog();

        $this->assertInstanceOf(TestUser::class, PersonalAccessToken::findTokenable($second->fresh()));
        $this->assertSame(0, $this->countQueriesForTable('users'));
        $this->assertSame($this->tokenableCacheKey($first), $this->tokenableCacheKey($second));
    }

    public function testClearTokenableCacheForgetsTheSharedOwnerEntry(): void
    {
        $token = $this->createToken();
        $accessToken = $token->fresh();

        $this->assertInstanceOf(TestUser::class, PersonalAccessToken::findTokenable($accessToken));

        PersonalAccessToken::clearTokenableCache($token->tokenable);

        $this->assertNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertInstanceOf(TestUser::class, PersonalAccessToken::findTokenable($token->fresh()));
        $this->assertSame(1, $this->countQueriesForTable('users'));
    }

    public function testOwnerSaveInvalidatesTokenableCacheOnlyAfterCommit(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);
        $user = $token->tokenable;

        DB::transaction(function () use ($token, $user): void {
            $user->forceFill(['name' => 'Updated User'])->save();

            $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
        });

        $this->assertNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
    }

    public function testRolledBackOwnerSaveKeepsCommittedTokenableCache(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);
        $user = $token->tokenable;

        try {
            DB::transaction(function () use ($user): never {
                $user->forceFill(['name' => 'Rolled Back'])->save();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Ignore the expected rollback exception.
        }

        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testOwnerDeleteInvalidatesOnlyTheTokenableEntry(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        $token->tokenable->delete();

        $this->assertNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
    }

    public function testClearTokenCacheForgetsOnlyTheTokenEntry(): void
    {
        $token = $this->createToken();
        $foundToken = PersonalAccessToken::findOrFail($token->id);

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertTrue($token->tokenable->is(PersonalAccessToken::findTokenable($foundToken)));

        PersonalAccessToken::clearTokenCache($token->id);

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
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

    #[DefineEnvironment('usePartialTokenCacheConfig')]
    public function testPartialTokenCacheConfigUsesPackageDefaults(): void
    {
        $token = $this->createToken();

        $foundToken = PersonalAccessToken::findToken("{$token->id}|secret");

        $this->assertInstanceOf(PersonalAccessToken::class, $foundToken);
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
    }

    public function testUpdatingTokenForgetsOnlyTheTokenEntry(): void
    {
        $token = $this->createToken();
        $foundToken = PersonalAccessToken::findOrFail($token->id);

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertTrue($token->tokenable->is(PersonalAccessToken::findTokenable($foundToken)));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));

        $token->forceFill(['name' => 'Updated Token'])->save();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testUpdatingTokenInvalidatesCacheOnlyAfterCommit(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        DB::transaction(function () use ($token): void {
            $token->forceFill(['name' => 'Updated Token'])->save();

            $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
            $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
        });

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testNestedUpdateInvalidationWaitsForTheOuterCommit(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        DB::transaction(function () use ($token): void {
            DB::transaction(function () use ($token): void {
                $token->forceFill(['name' => 'Updated Token'])->save();
            });

            $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        });

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
    }

    public function testRolledBackUpdateDoesNotInvalidateCommittedCache(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        try {
            DB::transaction(function () use ($token): never {
                $token->forceFill(['name' => 'Rolled Back'])->save();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Ignore the expected rollback exception.
        }

        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testInvalidationRunsImmediatelyWithoutManagerOrTransaction(): void
    {
        $token = (new EventPersonalAccessToken)->setConnection('sanctum_secondary');
        $token->setRawAttributes(['id' => 999], true);
        $this->cacheRepository()->put('sanctum:999', $token, 300);
        $connection = DB::connection('sanctum_secondary');
        $manager = $connection->getTransactionManager();
        $connection->unsetTransactionManager();

        try {
            $token->fireUpdatedEvent();
        } finally {
            $connection->setTransactionManager($manager);
        }

        $this->assertNull($this->cacheRepository()->getRaw('sanctum:999'));
    }

    public function testInvalidationFailsClosedWithoutManagerDuringTransaction(): void
    {
        $token = (new EventPersonalAccessToken)->setConnection('sanctum_secondary');
        $token->setRawAttributes(['id' => 999], true);
        $this->cacheRepository()->put('sanctum:999', $token, 300);
        $connection = DB::connection('sanctum_secondary');
        $manager = $connection->getTransactionManager();
        $connection->unsetTransactionManager();
        $connection->beginTransaction();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Transactions Manager has not been set.');

            $token->fireUpdatedEvent();
        } finally {
            $connection->rollBack();
            $connection->setTransactionManager($manager);
        }
    }

    public function testDeletingTokenForgetsOnlyTheTokenEntry(): void
    {
        $token = $this->createToken();
        $foundToken = PersonalAccessToken::findOrFail($token->id);

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertTrue($token->tokenable->is(PersonalAccessToken::findTokenable($foundToken)));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));

        $token->delete();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testDeletingTokenInvalidatesCacheOnlyAfterCommit(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        DB::transaction(function () use ($token): void {
            $token->delete();

            $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
            $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
        });

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testNestedDeleteInvalidationWaitsForTheOuterCommit(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        DB::transaction(function () use ($token): void {
            DB::transaction(function () use ($token): void {
                $token->delete();

                $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
            });

            $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        });

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testRolledBackTokenDeleteKeepsTheCommittedCacheEntries(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        try {
            DB::transaction(function () use ($token): never {
                $token->delete();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Ignore the expected rollback exception.
        }

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    #[DefineEnvironment('useNamespacedPersonalAccessTokenModel')]
    public function testCustomTokenModelCacheNamespaceIsUsedForEveryCachePath(): void
    {
        $this->createNamespacedTokenTable();
        $this->assertNull(NamespacedPersonalAccessToken::findToken('1|missing'));

        $token = $this->createToken();
        $this->assertInstanceOf(NamespacedPersonalAccessToken::class, $token);
        $this->assertSame(1, $token->getKey());
        $this->assertFalse($token->offsetExists('id'));
        $this->assertNull($this->cacheRepository()->getRaw('custom-sanctum:1'));

        $foundToken = NamespacedPersonalAccessToken::findToken('1|secret');
        $this->assertInstanceOf(NamespacedPersonalAccessToken::class, $foundToken);
        $this->assertInstanceOf(TestUser::class, NamespacedPersonalAccessToken::findTokenable($foundToken));
        $this->assertNotNull($this->cacheRepository()->getRaw('custom-sanctum:1'));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token, 'custom-sanctum')));
        $this->assertNull($this->cacheRepository()->getRaw('sanctum:1'));

        $foundToken->updateLastUsedAt();

        $this->assertNull($this->cacheRepository()->getRaw('custom-sanctum:1'));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token, 'custom-sanctum')));

        $this->assertInstanceOf(
            NamespacedPersonalAccessToken::class,
            NamespacedPersonalAccessToken::findToken('1|secret'),
        );

        $token->forceFill(['name' => 'Updated'])->save();

        $this->assertNull($this->cacheRepository()->getRaw('custom-sanctum:1'));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token, 'custom-sanctum')));

        $foundToken = NamespacedPersonalAccessToken::findToken('1|secret');
        $this->assertInstanceOf(NamespacedPersonalAccessToken::class, $foundToken);
        $this->assertSame(1, $token->tokenable->tokens()->delete());
        $this->assertNull($this->cacheRepository()->getRaw('custom-sanctum:1'));
    }

    #[DefineEnvironment('useSoftDeletingPersonalAccessTokenModel')]
    public function testSoftDeleteRestoreAndForceDeleteSettleCacheCorrectly(): void
    {
        $this->app->make('db')->connection()->getSchemaBuilder()->table(
            'personal_access_tokens',
            static fn (Blueprint $table) => $table->softDeletes(),
        );
        $token = $this->createToken();
        $this->assertInstanceOf(SoftDeletingPersonalAccessToken::class, $token);
        $foundToken = SoftDeletingPersonalAccessToken::findToken($token->id . '|secret');
        $this->assertInstanceOf(SoftDeletingPersonalAccessToken::class, $foundToken);
        $this->assertInstanceOf(TestUser::class, SoftDeletingPersonalAccessToken::findTokenable($foundToken));

        $token->delete();

        $this->assertNotNull(
            SoftDeletingPersonalAccessToken::withTrashed()->findOrFail($token->id)->deleted_at,
        );
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));

        $this->assertNull(SoftDeletingPersonalAccessToken::findToken($token->id . '|secret'));
        $token->restore();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertInstanceOf(
            SoftDeletingPersonalAccessToken::class,
            SoftDeletingPersonalAccessToken::findToken($token->id . '|secret'),
        );

        $token->forceDelete();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    #[DefineEnvironment('useSoftDeletingPersonalAccessTokenModel')]
    public function testSoftDeletingTokenRelationUsesTheModelDeleteScopeAndInvalidatesCache(): void
    {
        $this->app->make('db')->connection()->getSchemaBuilder()->table(
            'personal_access_tokens',
            static fn (Blueprint $table) => $table->softDeletes(),
        );
        $token = $this->createToken();
        $foundToken = SoftDeletingPersonalAccessToken::findToken($token->id . '|secret');
        $this->assertInstanceOf(SoftDeletingPersonalAccessToken::class, $foundToken);
        $user = $token->tokenable;

        $this->assertSame(1, $user->tokens()->delete());

        $this->assertNotNull(
            SoftDeletingPersonalAccessToken::withTrashed()->findOrFail($token->id)->deleted_at,
        );
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
    }

    public function testDeletingTokenRelationInvalidatesEverySelectedToken(): void
    {
        $first = $this->createToken();
        $user = $first->tokenable;
        $second = $user->tokens()->create([
            'name' => 'Second Token',
            'token' => hash('sha256', 'second-secret'),
            'abilities' => ['*'],
        ]);
        $this->warmTokenCache($first);
        $secondFound = PersonalAccessToken::findToken($second->id . '|second-secret');
        $this->assertInstanceOf(PersonalAccessToken::class, $secondFound);
        $this->assertInstanceOf(TestUser::class, PersonalAccessToken::findTokenable($secondFound));

        $this->assertSame(2, $user->tokens()->delete());

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $second->id]);

        foreach ([$first->id, $second->id] as $id) {
            $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$id}"));
        }

        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($first)));
    }

    public function testTokenRelationDeletesOnlyTheIdsSelectedBeforeTheDelete(): void
    {
        $token = $this->createToken();
        $user = $token->tokenable;
        $insertedToken = null;
        $selected = false;

        DB::listen(function (QueryExecuted $query) use (&$insertedToken, &$selected, $user): void {
            if ($selected
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'personal_access_tokens')) {
                return;
            }

            $selected = true;
            $insertedToken = $user->tokens()->create([
                'name' => 'Inserted Token',
                'token' => hash('sha256', 'inserted-secret'),
                'abilities' => ['*'],
            ]);
        });

        $this->assertSame(1, $user->tokens()->delete());
        $this->assertInstanceOf(PersonalAccessToken::class, $insertedToken);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $insertedToken->id]);
    }

    public function testTokenRelationDeleteDoesNotRetainItsInternalIdConstraint(): void
    {
        $token = $this->createToken();
        $user = $token->tokenable;
        $relation = $user->tokens();

        $this->assertSame(1, $relation->delete());

        $user->tokens()->create([
            'name' => 'Replacement Token',
            'token' => hash('sha256', 'replacement-secret'),
            'abilities' => ['*'],
        ]);

        $this->assertSame(1, $relation->count());
    }

    public function testConstrainedTokenRelationDeletesAndInvalidatesOnlyTheSelectedSet(): void
    {
        $first = $this->createToken();
        $user = $first->tokenable;
        $second = $user->tokens()->create([
            'name' => 'Second Token',
            'token' => hash('sha256', 'second-secret'),
            'abilities' => ['*'],
        ]);
        $this->warmTokenCache($first);
        $this->assertInstanceOf(
            PersonalAccessToken::class,
            PersonalAccessToken::findToken($second->id . '|second-secret'),
        );

        $this->assertSame(1, $user->tokens()->whereKey($first->id)->delete());

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $second->id]);
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$first->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$second->id}"));
    }

    public function testZeroMatchTokenRelationDeleteDoesNotInvalidateExistingTokens(): void
    {
        $token = $this->createToken();
        $this->warmTokenCache($token);

        $this->assertSame(0, $token->tokenable->tokens()->whereKey(-1)->delete());

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    public function testTokenRelationInvalidationWaitsForCommit(): void
    {
        $first = $this->createToken();
        $user = $first->tokenable;
        $second = $user->tokens()->create([
            'name' => 'Second Token',
            'token' => hash('sha256', 'second-secret'),
            'abilities' => ['*'],
        ]);
        $this->warmTokenCache($first);
        $secondFound = PersonalAccessToken::findToken($second->id . '|second-secret');
        $this->assertInstanceOf(PersonalAccessToken::class, $secondFound);

        DB::transaction(function () use ($first, $second, $user): void {
            $this->assertSame(2, $user->tokens()->delete());
            $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$first->id}"));
            $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$second->id}"));
        });

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$first->id}"));
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$second->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($first)));
    }

    public function testTokenRelationUsesTheConfiguredTokenModelConnectionForCreationAndAuthentication(): void
    {
        $this->createSecondaryConnectionTokenTable();
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'owner-connection-create@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ])->setConnection('sanctum_secondary');

        $token = $user->tokens()->create([
            'name' => 'Test Token',
            'token' => hash('sha256', 'secret'),
            'abilities' => ['*'],
        ]);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->assertSame(
            0,
            DB::connection('sanctum_secondary')->table('personal_access_tokens')->count(),
        );
        $this->assertInstanceOf(
            PersonalAccessToken::class,
            PersonalAccessToken::findToken($token->id . '|secret'),
        );
    }

    public function testTokenRelationDeletionIgnoresAnUnrelatedOwnerConnectionTransaction(): void
    {
        $this->createSecondaryConnectionTokenTable();
        $token = $this->createToken();
        $user = $token->tokenable->setConnection('sanctum_secondary');
        $this->warmTokenCache($token);
        $connection = DB::connection('sanctum_secondary');
        $connection->beginTransaction();

        try {
            $this->assertSame(1, $user->tokens()->delete());
            $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
            $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        } finally {
            $connection->rollBack();
        }
    }

    #[DefineEnvironment('useSecondaryConnectionPersonalAccessTokenModel')]
    public function testTokenRelationInvalidationFollowsTheTokenModelConnection(): void
    {
        $this->createSecondaryConnectionTokenTable();
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'secondary-token@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        $token = $user->tokens()->create([
            'name' => 'Test Token',
            'token' => hash('sha256', 'secret'),
            'abilities' => ['*'],
        ]);
        $this->assertInstanceOf(SecondaryConnectionPersonalAccessToken::class, $token);
        $foundToken = SecondaryConnectionPersonalAccessToken::findToken($token->id . '|secret');
        $this->assertInstanceOf(SecondaryConnectionPersonalAccessToken::class, $foundToken);
        $connection = DB::connection('sanctum_secondary');
        $connection->beginTransaction();

        try {
            $this->assertSame(1, $user->tokens()->delete());
            $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));

            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
    }

    #[DefineEnvironment('useSecondaryConnectionPersonalAccessTokenModel')]
    public function testTokenRelationInvalidationFailsClosedWithoutManagerDuringTransaction(): void
    {
        $this->createSecondaryConnectionTokenTable();
        $user = TestUser::create([
            'name' => 'Test User',
            'email' => 'managerless-relation@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ]);
        $token = $user->tokens()->create([
            'name' => 'Test Token',
            'token' => hash('sha256', 'secret'),
            'abilities' => ['*'],
        ]);
        $this->assertInstanceOf(SecondaryConnectionPersonalAccessToken::class, $token);
        $foundToken = SecondaryConnectionPersonalAccessToken::findToken($token->id . '|secret');
        $this->assertInstanceOf(SecondaryConnectionPersonalAccessToken::class, $foundToken);
        $connection = DB::connection('sanctum_secondary');
        $manager = $connection->getTransactionManager();
        $connection->unsetTransactionManager();
        $connection->beginTransaction();

        try {
            try {
                $user->tokens()->delete();

                $this->fail('Expected fail-closed settlement.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Transactions Manager has not been set.', $exception->getMessage());
            }
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            $connection->setTransactionManager($manager);
        }

        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
    }

    public function testRolledBackTokenRelationDeleteKeepsCommittedCache(): void
    {
        $token = $this->createToken();
        $user = $token->tokenable;
        $this->warmTokenCache($token);

        try {
            DB::transaction(function () use ($user): never {
                $user->tokens()->delete();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Ignore the expected rollback exception.
        }

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNotNull($this->cacheRepository()->getRaw($this->tokenableCacheKey($token)));
    }

    #[DefineEnvironment('disableTokenCache')]
    public function testCacheDisabledTokenRelationDeleteUsesOneQuery(): void
    {
        $token = $this->createToken();
        $user = $token->tokenable;
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame(1, $user->tokens()->delete());

        $this->assertSame(1, $this->countAllQueriesForTable('personal_access_tokens'));
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
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Create the custom-primary-key personal access token table for testing.
     */
    protected function createNamespacedTokenTable(): void
    {
        $this->app->make('db')->connection()->getSchemaBuilder()->create('namespaced_personal_access_tokens', function (Blueprint $table): void {
            $table->id('token_id');
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Create the personal access token table on the secondary connection.
     */
    protected function createSecondaryConnectionTokenTable(): void
    {
        $this->app->make('db')->connection('sanctum_secondary')->getSchemaBuilder()->create(
            'personal_access_tokens',
            static function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->dateTime('expires_at')->nullable()->index();
                $table->timestamps();
            },
        );
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
     * Warm the token and tokenable cache entries.
     */
    protected function warmTokenCache(PersonalAccessToken $token): void
    {
        $foundToken = PersonalAccessToken::findToken($token->id . '|secret');

        $this->assertInstanceOf(PersonalAccessToken::class, $foundToken);
        $this->assertInstanceOf(TestUser::class, PersonalAccessToken::findTokenable($foundToken));
    }

    /**
     * Get a value from its model-cache presence envelope.
     */
    protected function modelCacheValue(string $key): mixed
    {
        $envelope = $this->cacheRepository()->getRaw($key);

        $this->assertIsArray($envelope);
        $this->assertSame('present', $envelope['__hypervel_model_cache'] ?? null);
        $this->assertArrayHasKey('value', $envelope);

        return $envelope['value'];
    }

    /**
     * Build the tokenable identity cache key used by the test model.
     */
    protected function tokenableCacheKey(PersonalAccessToken $token, string $prefix = 'sanctum'): string
    {
        $morphType = (string) $token->getAttribute('tokenable_type');
        $id = (string) $token->getAttribute('tokenable_id');
        $identity = strlen($morphType) . ":{$morphType}|" . strlen($id) . ":{$id}";

        return "{$prefix}:tokenable:" . hash('xxh128', $identity);
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

    /**
     * Count all logged queries for a table.
     */
    protected function countAllQueriesForTable(string $table): int
    {
        return count(array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains($query['query'] ?? '', $table),
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

class NamespacedPersonalAccessToken extends PersonalAccessToken
{
    protected ?string $table = 'namespaced_personal_access_tokens';

    protected string $primaryKey = 'token_id';

    /**
     * Get cache key for token and tokenable.
     */
    protected static function getCacheKey(int|string $tokenId): string
    {
        return "custom-sanctum:{$tokenId}";
    }
}

class SoftDeletingPersonalAccessToken extends PersonalAccessToken
{
    use SoftDeletes;
}

class TimestampDisabledPersonalAccessToken extends PersonalAccessToken
{
    public bool $timestamps = false;
}

class CustomTimestampPersonalAccessToken extends PersonalAccessToken
{
    public const ?string UPDATED_AT = 'modified_at';
}

class SecondaryConnectionPersonalAccessToken extends PersonalAccessToken
{
    protected UnitEnum|string|null $connection = 'sanctum_secondary';
}

class EventPersonalAccessToken extends PersonalAccessToken
{
    /**
     * Fire the updated event for transaction-settlement tests.
     */
    public function fireUpdatedEvent(): void
    {
        $this->fireModelEvent('updated', false);
    }
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
