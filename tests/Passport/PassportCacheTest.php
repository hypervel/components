<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\ModelCacheCoordinator;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Cache\LockTimeoutException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Http\Request;
use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Bridge\AccessTokenRepository;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Events\AccessTokenRevoked;
use Hypervel\Passport\Passport;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\RefreshTokenFamily;
use Hypervel\Passport\Token;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Passport\Fixtures\User;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class PassportCacheTest extends TestCase
{
    /**
     * Configure the cache independently of the OAuth server.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'passport' => require __DIR__ . '/../../src/passport/config/passport.php',
            'passport.cache.enabled' => true,
            'passport.cache.store' => 'passport-file',
            'cache.serializable_classes' => [Token::class, Client::class, CountingPassportClient::class],
            'cache.stores.passport-file' => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/data/passport'),
            ],
        ]);
    }

    /**
     * Create the credential tables used by cache lookups.
     */
    protected function setUpInCoroutine(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('oauth_clients', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->nullableMorphs('owner');
            $table->jsonb('grant_types');
            $table->jsonb('redirect_uris');
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });
        $schema->create('oauth_access_tokens', static function (Blueprint $table): void {
            $table->string('id', 80)->primary();
            $table->uuid('client_id');
            $table->string('name')->nullable();
            $table->jsonb('scopes')->nullable();
            $table->boolean('revoked')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Remove cache data owned by this test.
     */
    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store('passport-file')->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testSharedHitsAndRequestMemosAvoidCredentialQueries(): void
    {
        $token = $this->createToken();
        $clients = $this->app->make(ClientRepository::class);
        $first = Token::findToken($token->id);
        $client = $clients->find($token->client_id);

        DB::enableQueryLog();
        $this->assertSame($first, Token::findToken($token->id));
        $this->assertSame($client, $clients->find($token->client_id));

        [$cached] = parallel([
            static fn (): array => [Token::findToken($token->id), $clients->find($token->client_id)],
        ]);

        $this->assertSame($token->id, $cached[0]->id);
        $this->assertSame($token->client_id, $cached[1]->id);
        $this->assertNotSame($first, $cached[0]);
        $this->assertSame([], DB::getQueryLog());
    }

    public function testMissingTokensAreMemoizedButNotPublished(): void
    {
        DB::enableQueryLog();
        $this->assertNull(Token::findToken('missing'));
        $this->assertNull(Token::findToken('missing'));
        $this->assertCount(1, DB::getQueryLog());
        $this->assertNull($this->app->make('cache')->store('passport-file')->get('passport:tokens:missing'));
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['0'])]
    public function testCacheStoreSelection(?string $store): void
    {
        config([
            'cache.default' => $store === '0' ? 'not-configured' : 'passport-file',
            'cache.stores.0' => config('cache.stores.passport-file'),
            'passport.cache.store' => $store,
        ]);
        $token = $this->createToken();
        $this->assertSame($token->id, Token::findToken($token->id)->id);
        RequestContext::set(Request::create('/next'));
        DB::enableQueryLog();
        $this->assertSame($token->id, Token::findToken($token->id)->id);
        $this->assertSame([], DB::getQueryLog());
    }

    public function testClientCacheHitsDoNotConstructModels(): void
    {
        Passport::useClientModel(CountingPassportClient::class);
        $client = $this->createClient();
        $repository = $this->app->make(ClientRepository::class);

        try {
            $found = $repository->find($client->id);
            $this->assertInstanceOf(CountingPassportClient::class, $found);
            CountingPassportClient::$constructions = 0;

            $this->assertSame($found, $repository->find($client->id));
            RequestContext::set(Request::create('/next'));
            $this->assertSame($client->id, $repository->find($client->id)->id);
            $this->assertSame(0, CountingPassportClient::$constructions);
        } finally {
            CountingPassportClient::$constructions = 0;
        }
    }

    public function testMalformedClientIdsDoNotQueryOrPublishSharedEntries(): void
    {
        $repository = $this->app->make(ClientRepository::class);
        $cache = $this->app->make('cache')->store('passport-file');
        DB::enableQueryLog();

        foreach (['x', '', '1', 1, '550e8400-e29b-41d4-a716-44665544000x'] as $id) {
            $this->assertNull($repository->find($id));
            $this->assertNull($cache->get('passport:clients:' . $id));
        }

        $this->assertSame([], DB::getQueryLog());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAuthenticationReadsNewAndRevokedCredentialsFromTheWriter(bool $cached): void
    {
        config(['passport.cache.enabled' => $cached]);
        $token = $this->createToken();
        $client = Client::findOrFail($token->client_id);
        $repository = $this->app->make(ClientRepository::class);
        $connection = DB::connection();
        $original = $connection->getRawReadPdo();
        $replica = new PDO('sqlite::memory:');
        $replica->exec('create table oauth_access_tokens (id text primary key, revoked integer)');
        $replica->exec('create table oauth_clients (id text primary key, revoked integer, provider text, grant_types text, created_at text)');
        $personal = $repository->createPersonalAccessGrantClient('Personal', 'users');
        $connection->setReadPdo($replica);

        try {
            $this->assertNull($connection->table('oauth_access_tokens')->find($token->id));
            $this->assertFalse(Token::findToken($token->id)->revoked);
            $this->assertNotNull($repository->findActive($client->id));
            $this->assertSame($personal->id, $repository->personalAccessClient('users')->id);
            $this->assertNull(Client::query()->find($personal->id));
            $replica->prepare('insert into oauth_access_tokens values (?, 0)')->execute([$token->id]);
            $replica->prepare('insert into oauth_clients (id, revoked) values (?, 0)')->execute([$client->id]);
            $token->revoke();
            $client->revoke();
            $this->assertSame(0, $connection->table('oauth_access_tokens')->find($token->id)->revoked);
            $this->assertTrue(Token::findToken($token->id)->revoked);
            $this->assertNull($repository->findActive($client->id));
        } finally {
            $connection->setReadPdo($original);
        }
    }

    public function testModelCreationClearsMissingCredentialMemos(): void
    {
        $clients = $this->app->make(ClientRepository::class);
        $clientId = '00000000-0000-4000-8000-000000000001';
        $this->assertNull($clients->find($clientId));
        $this->assertNull(Token::findToken('new-token'));

        $forgotten = [];
        Event::listen(ForgettingKey::class, static function (ForgettingKey $event) use (&$forgotten): void {
            $forgotten[] = $event->key;
        });

        Client::forceCreate([
            'id' => $clientId,
            'name' => 'Client',
            'grant_types' => ['client_credentials'],
            'redirect_uris' => [],
            'revoked' => false,
        ]);
        Token::forceCreate([
            'id' => 'new-token',
            'client_id' => $clientId,
            'revoked' => false,
        ]);

        $this->assertSame($clientId, $clients->find($clientId)?->getKey());
        $this->assertSame('new-token', Token::findToken('new-token')?->getKey());
        $this->assertSame([], $forgotten);
    }

    #[DataProvider('cacheWarmth')]
    public function testTokenAndClientMemosAndSharedCacheDoNotRetainTransactionWrites(bool $warm): void
    {
        $token = $this->createToken();
        $clients = $this->app->make(ClientRepository::class);
        $client = Client::findOrFail($token->client_id);

        if ($warm) {
            Token::findToken($token->id);
            $clients->find($client->id);
        }

        $connection = $token->getConnection();
        $connection->beginTransaction();

        try {
            $token->forceFill(['name' => 'Outer'])->save();
            $client->forceFill(['name' => 'Outer'])->save();
            $this->assertSame('Outer', Token::findToken($token->id)->name);
            $this->assertSame('Outer', $clients->find($client->id)->name);
            $connection->beginTransaction();
            $token->forceFill(['name' => 'Inner'])->save();
            $client->forceFill(['name' => 'Inner'])->save();
            $this->assertSame('Inner', Token::findToken($token->id)->name);
            $this->assertSame('Inner', $clients->find($client->id)->name);
            $connection->rollBack();
            $this->assertSame('Outer', Token::findToken($token->id)->name);
            $this->assertSame('Outer', $clients->find($client->id)->name);
        } finally {
            $connection->rollBack(0);
        }

        $this->assertSame('Token', Token::findToken($token->id)->name);
        $this->assertSame('Client', $clients->find($client->id)->name);
    }

    /**
     * Exercise both existing entries and transaction-local cache misses.
     */
    public static function cacheWarmth(): iterable
    {
        yield 'warm' => [true];
        yield 'cold' => [false];
    }

    public function testModelRevocationsClearRequestMemosAndSharedEntriesAfterCommit(): void
    {
        $token = $this->createToken();
        $clients = $this->app->make(ClientRepository::class);
        $client = $clients->find($token->client_id);
        $this->assertFalse(Token::findToken($token->id)->revoked);
        DB::transaction(function () use ($token, $client): void {
            $token->revoke();
            $client->revoke();
        });

        $this->assertTrue(Token::findToken($token->id)->revoked);
        $this->assertNull($clients->findActive($client->id));
        [$revoked] = parallel([static fn (): bool => Token::findToken($token->id)->revoked]);
        $this->assertTrue($revoked);
    }

    #[TestWith([null])]
    #[TestWith([1002])]
    public function testTokenRelationUpdatesAndDeletesAcrossSeveralBatches(?int $limit): void
    {
        $client = $this->createClient();
        $rows = [];

        for ($index = 0; $index < 1002; ++$index) {
            $rows[] = ['id' => sprintf('token-%04d', $index), 'client_id' => $client->id, 'name' => sprintf('%04d', $index)];
        }

        Token::query()->insert($rows);
        $this->assertFalse(Token::findToken('token-0000')->revoked);
        $this->assertFalse(Token::findToken('token-1001')->revoked);
        $relation = $client->tokens()->where('revoked', false)->orWhere('id', 'token-0000')->orderByDesc('name');

        if ($limit !== null) {
            $relation->limit($limit);
        }

        $writes = 0;
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (str_starts_with($query->sql, 'update "oauth_access_tokens"')) {
                $this->assertLessThanOrEqual(2, ++$writes, 'The key cursor must advance past every selected batch.');
            }
        });
        $this->assertSame(1002, $relation->update(['revoked' => true, 'name' => 'updated']));
        $this->assertTrue(Token::findToken('token-0000')->revoked);
        $this->assertTrue(Token::findToken('token-1001')->revoked);
        $this->assertSame(1002, $client->tokens()->delete());
        $this->assertNull(Token::findToken('token-0000'));
        $this->assertNull(Token::findToken('token-1001'));
        $this->assertSame(0, $client->tokens()->count());
    }

    #[TestWith([null, ['one', 'two', 'four']])]
    #[TestWith([2, ['one', 'four']])]
    public function testOrderedRelationUpdatesPreserveOffsetAndLimit(?int $limit, array $changed): void
    {
        $client = $this->createClient();
        $names = ['one' => 'C', 'two' => 'A', 'three' => 'D', 'four' => 'B'];

        foreach ($names as $id => $name) {
            Token::query()->create(['id' => $id, 'name' => $name, 'client_id' => $client->id]);
            $this->assertFalse(Token::findToken($id)->revoked);
        }

        $relation = $client->tokens()->where('revoked', false)->orWhere('id', 'three')->orderByDesc('name')->offset(1);

        if ($limit !== null) {
            $relation->limit($limit);
        }

        $originalQuery = [$relation->toSql(), $relation->getBindings()];
        $this->assertSame(count($changed), $relation->update(['revoked' => true, 'name' => 'changed']));
        $this->assertSame($originalQuery, [$relation->toSql(), $relation->getBindings()]);

        foreach ($names as $id => $name) {
            $this->assertSame(in_array($id, $changed, true), Token::findToken($id)->revoked);
        }
    }

    public function testPaginatedJoinedRelationUpdatesEachSelectedTokenOnce(): void
    {
        $client = $this->createClient();
        $rows = [];

        for ($index = 0; $index < 1002; ++$index) {
            $rows[] = ['id' => sprintf('token-%04d', $index), 'client_id' => $client->id, 'name' => 'original'];
        }

        Token::query()->insert($rows);
        $this->assertSame('original', Token::findToken('token-0000')->name);

        $relation = $client->tokens()
            ->crossJoin('oauth_access_tokens as copies')
            ->where('oauth_access_tokens.id', 'token-0000')
            ->orderBy('copies.id')
            ->limit(1002);

        $this->assertSame(1, $relation->update(['name' => DB::raw("oauth_access_tokens.name || '-updated'")]));
        $this->assertSame('original-updated', Token::findToken('token-0000')->name);
        $this->assertSame('original', Token::findToken('token-0001')->name);
    }

    public function testBatchInvalidationContinuesAfterAFailureFollowingCommit(): void
    {
        $failure = new LockTimeoutException;
        $cache = $this->app->make('cache')->store('passport-file');
        $coordinator = m::mock(ModelCacheCoordinator::class, [$this->app])->makePartial();
        $coordinator->shouldReceive('invalidate')->once()
            ->with($cache, 'passport:tokens:token')->andThrow($failure);
        $coordinator->shouldReceive('invalidate')->once()
            ->with($cache, 'passport:tokens:token-second')->passthru();
        $this->app->instance(ModelCacheCoordinator::class, $coordinator);

        $first = $this->createToken();
        $second = $first->replicate()->forceFill(['id' => 'token-second']);
        $second->save();

        foreach ([$first, $second] as $token) {
            $this->assertFalse(Token::findToken($token->id)->revoked);
            $this->assertNotNull($cache->get('passport:tokens:' . $token->id));
        }

        try {
            Client::findOrFail($first->client_id)->tokens()->update(['revoked' => true]);
            $this->fail('The invalidation failure must propagate.');
        } catch (LockTimeoutException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue($first->fresh()->revoked);
        $this->assertTrue($second->fresh()->revoked);
        $this->assertNull($cache->get('passport:tokens:' . $second->id));
        $this->assertTrue(Token::findToken($second->id)->revoked);
    }

    public function testOwnedClientRelationUpdatesAndDeletesAcrossSeveralBatches(): void
    {
        $owner = (new User)->forceFill(['id' => 1]);
        $rows = [];

        for ($index = 0; $index < 1002; ++$index) {
            $rows[] = [
                'id' => sprintf('00000000-0000-4000-8000-%012d', $index),
                'owner_type' => $owner->getMorphClass(), 'owner_id' => $owner->id,
                'name' => 'Client', 'grant_types' => '[]', 'redirect_uris' => '[]',
            ];
        }

        Client::query()->insert($rows);
        $repository = $this->app->make(ClientRepository::class);
        $ids = [$rows[0]['id'], $rows[1001]['id']];

        foreach ($ids as $id) {
            $this->assertSame('Client', $repository->find($id)->name);
        }

        $relation = $owner->oauthApps();
        $this->assertSame(1002, $relation->update(['name' => 'Updated']));

        foreach ($ids as $id) {
            $this->assertSame('Updated', $repository->find($id)->name);
        }

        $this->assertSame(1002, $relation->delete());

        foreach ($ids as $id) {
            $this->assertNull($repository->find($id));
        }
    }

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testRelationWritesOnlyChangeTheSelectedKeysTheyInvalidate(bool $clients, bool $delete): void
    {
        if ($clients) {
            $owner = (new User)->forceFill(['id' => 1]);
            $relation = $owner->oauthApps();
            $existing = $relation->save($this->createClient());
        } else {
            $existing = $this->createToken();
            $relation = Client::findOrFail($existing->client_id)->tokens();
        }

        $selected = false;
        $inserted = null;
        DB::listen(function (QueryExecuted $query) use ($existing, &$selected, &$inserted): void {
            if ($selected || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, $existing->getTable())) {
                return;
            }

            $selected = true;
            $inserted = $existing->replicate()->forceFill(['id' => '00000000-0000-4000-8000-000000000001']);
            $inserted->save();
        });

        $this->assertSame(1, $delete ? $relation->delete() : $relation->update(['revoked' => true]));
        $this->assertNotNull($inserted);
        $this->assertFalse($inserted->fresh()->revoked);
        $this->assertSame($delete ? null : true, $existing->fresh()?->revoked);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testLaterBatchFailuresRollBackRelationWritesAndKeepSharedCache(bool $delete): void
    {
        $client = $this->createClient();
        $rows = [];

        for ($index = 0; $index < 1002; ++$index) {
            $rows[] = ['id' => sprintf('token-%04d', $index), 'client_id' => $client->id];
        }

        Token::query()->insert($rows);
        $this->assertFalse(Token::findToken('token-0000')->revoked);
        $cache = $this->app->make('cache')->store('passport-file');
        $before = $cache->get('passport:tokens:token-0000');
        $failure = new RuntimeException('Second batch failed.');
        $writes = 0;
        DB::connection()->beforeExecuting(static function (string $sql) use ($delete, &$writes, $failure): void {
            if (str_starts_with($sql, $delete ? 'delete' : 'update') && ++$writes === 2) {
                throw $failure;
            }
        });

        try {
            $delete ? $client->tokens()->delete() : $client->tokens()->update(['revoked' => true]);
            $this->fail('The second batch must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(2, $writes);
        $this->assertSame(1002, $client->tokens()->where('revoked', false)->count());
        $this->assertEquals($before, $cache->get('passport:tokens:token-0000'));
    }

    public function testUncachedRelationWritesClearRequestMemos(): void
    {
        config(['passport.cache.enabled' => false]);
        $owner = (new User)->forceFill(['id' => 1]);
        $client = $owner->oauthApps()->save($this->createClient());
        $token = Token::forceCreate(['id' => 'owned', 'client_id' => $client->id, 'revoked' => false]);
        $clients = $this->app->make(ClientRepository::class);
        $this->assertFalse(Token::findToken($token->id)->revoked);
        $this->assertFalse($clients->find($client->id)->revoked);
        $client->tokens()->update(['revoked' => true]);
        $owner->oauthApps()->update(['revoked' => true]);
        $this->assertTrue(Token::findToken($token->id)->revoked);
        $this->assertTrue($clients->find($client->id)->revoked);
        $client->tokens()->delete();
        $owner->oauthApps()->delete();
        $this->assertNull(Token::findToken($token->id));
        $this->assertNull($clients->find($client->id));
    }

    public function testFamilyRevocationInvalidatesEveryCachedAccessTokenAfterCommit(): void
    {
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000003_create_oauth_refresh_tokens_table.php')->up();
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000006_create_oauth_refresh_token_families_table.php')->up();
        $family = RefreshTokenFamily::create();
        $first = $this->createToken();
        $second = $first->replicate()->forceFill(['id' => 'second']);
        $second->save();

        foreach ([$first, $second] as $token) {
            RefreshToken::forceCreate([
                'id' => $token->id, 'access_token_id' => $token->id, 'family_id' => $family->id, 'revoked' => false,
            ]);
            $this->assertFalse(Token::findToken($token->id)->revoked);
        }

        $events = [];
        Event::listen(AccessTokenRevoked::class, function (AccessTokenRevoked $event) use (&$events): void {
            $this->assertSame(0, DB::transactionLevel());
            $events[] = $event->tokenId;
        });
        TokenIssuance::revokeRefreshTokenFamily($family->id);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $events);

        foreach ([$first, $second] as $token) {
            $this->assertTrue(Token::findToken($token->id)->revoked);
            [$revoked] = parallel([static fn (): bool => Token::findToken($token->id)->revoked]);
            $this->assertTrue($revoked);
        }
    }

    #[DataProvider('revocationPaths')]
    public function testEveryRevocationPathInvalidatesAndDispatchesOnlyAfterCommit(string $path, bool $commit): void
    {
        $token = $this->createToken();
        Token::findToken($token->id);
        $access = new AccessToken(['oauth_access_token_id' => $token->id]);

        if ($path === 'loaded-wrapper') {
            $this->assertSame('Token', $access->name);
        }

        $events = [];
        Event::listen(AccessTokenRevoked::class, static function (AccessTokenRevoked $event) use (&$events): void {
            $events[] = $event->tokenId;
        });
        $cache = $this->app->make('cache')->store('passport-file');
        $cachedBefore = $cache->get('passport:tokens:' . $token->id);
        $this->assertNotNull($cachedBefore);
        $connection = $token->getConnection();
        $connection->beginTransaction();

        try {
            match ($path) {
                'model' => $token->revoke(),
                'loaded-wrapper', 'query-wrapper' => $access->revoke(),
                'repository' => $this->app->make(AccessTokenRepository::class)->revokeAccessToken($token->id),
            };

            if ($path === 'query-wrapper') {
                $this->assertFalse($access->revoke());
            }

            $this->assertTrue(Token::findToken($token->id)->revoked);
            $this->assertEquals($cachedBefore, $cache->get('passport:tokens:' . $token->id));
            $this->assertSame([], $events);

            if ($commit) {
                $connection->commit();
            }
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        $this->assertSame($commit ? [$token->id] : [], $events);
        $this->assertSame($commit, Token::findToken($token->id)->revoked);
        [$cached] = parallel([static fn (): ?Token => Token::findToken($token->id)]);
        $this->assertSame($commit, $cached->revoked);
    }

    /**
     * Exercise each supported revocation writer with commit and rollback.
     */
    public static function revocationPaths(): iterable
    {
        foreach (['model', 'loaded-wrapper', 'query-wrapper', 'repository'] as $path) {
            yield $path . ' committed' => [$path, true];
            yield $path . ' rolled back' => [$path, false];
        }
    }

    public function testAuthenticationCallbacksRecheckCachedModelsWithoutNewQueries(): void
    {
        $token = $this->createToken();
        $repository = $this->app->make(AccessTokenRepository::class);
        $tokenAllowed = $clientAllowed = true;
        $checkedTokens = [];
        Passport::authenticateAccessTokensUsing(static function (Token $token, bool $valid) use (&$tokenAllowed, &$checkedTokens): bool {
            $checkedTokens[] = [$token->id, $valid];

            return $tokenAllowed;
        });
        Passport::authenticateClientsUsing(static function () use (&$clientAllowed): bool {
            return $clientAllowed;
        });
        $this->assertFalse($repository->isAccessTokenRevoked($token->id));
        DB::enableQueryLog();
        $tokenAllowed = false;
        $this->assertTrue($repository->isAccessTokenRevoked($token->id));
        $tokenAllowed = true;
        $clientAllowed = false;
        $this->assertTrue($repository->isAccessTokenRevoked($token->id));
        $this->assertSame([[$token->id, true], [$token->id, true], [$token->id, true]], $checkedTokens);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        $clientAllowed = true;
        $token->revoke();
        $this->assertTrue($repository->isAccessTokenRevoked($token->id));
        $this->assertSame([$token->id, false], $checkedTokens[3]);
    }

    public function testModelDeletionAndSecretChangesCannotLeaveCachedCredentialsUsable(): void
    {
        $token = $this->createToken();
        Token::findToken($token->id);
        $clients = $this->app->make(ClientRepository::class);
        $client = Client::findOrFail($token->client_id);
        $client->secret = 'old-secret';
        $client->save();
        $this->assertTrue($clients->find($client->id)->secretMatches('old-secret'));
        $clients->regenerateSecret($client);
        $this->assertFalse($clients->find($client->id)->secretMatches('old-secret'));
        $this->assertTrue($clients->find($client->id)->secretMatches($client->plainSecret));
        $client->delete();
        $this->assertNull($clients->find($client->id));
        $this->assertTrue($this->app->make(AccessTokenRepository::class)->isAccessTokenRevoked($token->id));
        $token->delete();
        $this->assertNull(Token::findToken($token->id));
    }

    /**
     * Create a client without initializing an OAuth server.
     */
    protected function createClient(): Client
    {
        return Client::forceCreate([
            'name' => 'Client',
            'grant_types' => ['client_credentials'],
            'redirect_uris' => [],
            'revoked' => false,
        ]);
    }

    /**
     * Create one stored access token.
     */
    protected function createToken(): Token
    {
        return Token::forceCreate([
            'id' => 'token',
            'client_id' => $this->createClient()->id,
            'name' => 'Token',
            'scopes' => ['*'],
            'revoked' => false,
        ]);
    }
}

class CountingPassportClient extends Client
{
    public static int $constructions = 0;

    /**
     * Count model construction without changing client behavior.
     */
    public function __construct(array $attributes = [])
    {
        ++self::$constructions;

        parent::__construct($attributes);
    }
}
