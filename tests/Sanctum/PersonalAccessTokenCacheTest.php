<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Sanctum\SanctumServiceProvider;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Sanctum\Fixtures\TestUser;

class PersonalAccessTokenCacheTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SanctumServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'cache.default' => 'array',
            'cache.stores.array' => [
                'driver' => 'array',
                'serialize' => false,
            ],
            'cache.stores.0' => [
                'driver' => 'array',
                'serialize' => false,
            ],
            'sanctum.cache.enabled' => true,
        ]);
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

    public function testStringKeyTokenModelAcceptsNonNumericTokenId(): void
    {
        $this->createStringKeyTokenTable();

        Sanctum::usePersonalAccessTokenModel(StringKeyPersonalAccessToken::class);

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

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertSame(1, $this->countQueriesForTable('personal_access_tokens'));

        DB::flushQueryLog();

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertSame(0, $this->countQueriesForTable('personal_access_tokens'));
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

    public function testLastUsedAtIsWrittenEveryTimeWhenCachingIsDisabled(): void
    {
        $this->app->make('config')->set('sanctum.cache.enabled', false);
        $this->freezeTime();

        $token = $this->createToken();
        $token->updateLastUsedAt();
        $firstLastUsedAt = $token->fresh()->last_used_at;

        $this->travel(1)->second();
        $token->updateLastUsedAt();

        $this->assertTrue($token->fresh()->last_used_at->isAfter($firstLastUsedAt));
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

        $connection = $token->getConnection();
        $connection->setRecordModificationState(false);

        $token->updateLastUsedAt();

        $this->assertFalse($connection->hasModifiedRecords());
        $this->assertSame(
            $now->format('Y-m-d H:i:s'),
            $token->fresh()->last_used_at->format('Y-m-d H:i:s'),
        );

        $cachedToken = $this->cacheRepository()->get("sanctum:{$token->id}");
        $this->assertInstanceOf(PersonalAccessToken::class, $cachedToken);
        $this->assertSame(
            $now->format('Y-m-d H:i:s'),
            $cachedToken->last_used_at->format('Y-m-d H:i:s'),
        );
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

    public function testMissingTokenableCachesNullResult(): void
    {
        $token = $this->createToken();

        TestUser::query()->whereKey($token->tokenable_id)->delete();

        DB::enableQueryLog();

        $this->assertNull(PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id)));
        $this->assertSame(1, $this->countQueriesForTable('users'));
        $this->assertSame(NullSentinel::VALUE, $this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
        $this->assertNull($this->cacheRepository()->get("sanctum:{$token->id}:tokenable"));

        DB::flushQueryLog();

        $this->assertNull(PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id)));
        $this->assertSame(0, $this->countQueriesForTable('users'));
    }

    public function testValidTokenableIsCached(): void
    {
        $token = $this->createToken();

        DB::enableQueryLog();

        $tokenable = PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id));
        $this->assertInstanceOf(TestUser::class, $tokenable);
        $this->assertSame($token->tokenable_id, $tokenable->getKey());
        $this->assertSame(1, $this->countQueriesForTable('users'));

        DB::flushQueryLog();

        $tokenable = PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id));
        $this->assertInstanceOf(TestUser::class, $tokenable);
        $this->assertSame($token->tokenable_id, $tokenable->getKey());
        $this->assertSame(0, $this->countQueriesForTable('users'));
    }

    public function testClearTokenCacheForgetsTokenAndTokenableEntries(): void
    {
        $token = $this->createToken();

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertTrue($token->tokenable->is(PersonalAccessToken::findTokenable($token)));

        PersonalAccessToken::clearTokenCache($token->id);

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
    }

    public function testTokenCacheStorePreservesZeroAndEmptyFallback(): void
    {
        $defaultStore = $this->app->make('cache')->store();
        $zeroStore = $this->app->make('cache')->store('0');

        $this->app->make('config')->set('sanctum.cache.store', '0');
        $defaultStore->put('sanctum:1', 'default', 60);
        $zeroStore->put('sanctum:1', 'zero', 60);

        PersonalAccessToken::clearTokenCache(1);

        $this->assertSame('default', $defaultStore->get('sanctum:1'));
        $this->assertNull($zeroStore->get('sanctum:1'));

        $this->app->make('config')->set('sanctum.cache.store', '');
        $defaultStore->put('sanctum:2', 'default', 60);
        $zeroStore->put('sanctum:2', 'zero', 60);

        PersonalAccessToken::clearTokenCache(2);

        $this->assertNull($defaultStore->get('sanctum:2'));
        $this->assertSame('zero', $zeroStore->get('sanctum:2'));
    }

    public function testUpdatingTokenForgetsTokenAndTokenableCacheEntries(): void
    {
        $token = $this->createToken();

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));

        TestUser::query()->whereKey($token->tokenable_id)->delete();

        $this->assertNull(PersonalAccessToken::findTokenable(PersonalAccessToken::findOrFail($token->id)));
        $this->assertNotNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertSame(NullSentinel::VALUE, $this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));

        $token->forceFill(['name' => 'Updated Token'])->save();

        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}"));
        $this->assertNull($this->cacheRepository()->getRaw("sanctum:{$token->id}:tokenable"));
    }

    public function testDeletingTokenForgetsTokenAndTokenableCacheEntries(): void
    {
        $token = $this->createToken();

        $this->assertTrue($token->is(PersonalAccessToken::findToken($token->id . '|secret')));
        $this->assertTrue($token->tokenable->is(PersonalAccessToken::findTokenable($token)));
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
