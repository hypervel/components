<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session\Database;

use Closure;
use Hypervel\Auth\AuthManager;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Http\Request;
use Hypervel\Session\DatabaseSessionHandler;
use Hypervel\Session\Store;
use Hypervel\Session\UserSessionIdentity;
use Hypervel\Session\UserSessions;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

abstract class DatabaseSessionHandlerTestCase extends DatabaseTestCase
{
    protected const string CURRENT_SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected const string OTHER_SESSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected const string THIRD_SESSION_ID = 'cccccccccccccccccccccccccccccccccccccccc';

    public function testBasicReadWriteFunctionality(): void
    {
        RequestContext::set(Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'Test/1.0',
        ]));

        $resolver = $this->app->make('db');
        $connection = $this->app->make('db')->connection();
        $handler = new DatabaseSessionHandler($resolver, null, 'sessions', 1);
        $handler->setContainer($this->app);

        // read non-existing session id:
        $this->assertSame('', $handler->read('invalid_session_id'));

        // open and close:
        $this->assertTrue($handler->open('', ''));
        $this->assertTrue($handler->close());

        // write and read:
        $this->assertTrue($handler->write('valid_session_id_2425', json_encode(['foo' => 'bar'])));
        $this->assertSame(['foo' => 'bar'], json_decode($handler->read('valid_session_id_2425'), true));
        $this->assertSame(1, $connection->table('sessions')->count());

        $session = $connection->table('sessions')->first();
        $this->assertNotNull($session->user_agent);
        $this->assertNotNull($session->ip_address);

        // re-write and read:
        $this->assertTrue($handler->write('valid_session_id_2425', json_encode(['over' => 'ride'])));
        $this->assertSame(['over' => 'ride'], json_decode($handler->read('valid_session_id_2425'), true));
        $this->assertSame(1, $connection->table('sessions')->count());

        // handler object writes only one session id:
        $this->assertTrue($handler->write('other_id', 'data'));
        $this->assertSame(1, $connection->table('sessions')->count());

        $handler->setExists(false);
        $this->assertTrue($handler->write('other_id', 'data'));
        $this->assertSame(2, $connection->table('sessions')->count());

        // read expired:
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
        $this->assertSame('', $handler->read('valid_session_id_2425'));

        // rewriting an expired session-id, makes it live:
        $this->assertTrue($handler->write('valid_session_id_2425', json_encode(['come' => 'alive'])));
        $this->assertSame(['come' => 'alive'], json_decode($handler->read('valid_session_id_2425'), true));
    }

    public function testGarbageCollector(): void
    {
        $resolver = $this->app->make('db');
        $connection = $this->app->make('db')->connection();

        $handler = new DatabaseSessionHandler($resolver, null, 'sessions', 1, $this->app);
        CarbonImmutable::setTestNow(CarbonImmutable::now());
        $handler->write('simple_id_1', 'abcd');
        $this->assertSame(0, $handler->gc(1));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

        $handler = new DatabaseSessionHandler($resolver, null, 'sessions', 1, $this->app);
        $handler->write('simple_id_2', 'abcd');
        $this->assertSame(1, $handler->gc(2));
        $this->assertSame(1, $connection->table('sessions')->count());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

        $this->assertSame(1, $handler->gc(1));
        $this->assertSame(0, $connection->table('sessions')->count());
    }

    public function testDestroy(): void
    {
        $resolver = $this->app->make('db');
        $connection = $this->app->make('db')->connection();
        $handler1 = new DatabaseSessionHandler($resolver, null, 'sessions', 1, $this->app);
        $handler2 = clone $handler1;

        $handler1->write('id_1', 'some data');
        $handler2->write('id_2', 'some data');

        // destroy invalid session-id:
        $this->assertTrue($handler1->destroy('invalid_session_id'));
        // nothing deleted:
        $this->assertSame(2, $connection->table('sessions')->count());

        // destroy valid session-id:
        $this->assertTrue($handler2->destroy('id_1'));
        // only one row is deleted:
        $this->assertSame(1, $connection->table('sessions')->where('id', 'id_2')->count());
    }

    public function testItCanWorkWithoutContainer(): void
    {
        $resolver = $this->app->make('db');
        $connection = $this->app->make('db')->connection();
        $handler = new DatabaseSessionHandler($resolver, null, 'sessions', 1);

        // write and read:
        $this->assertTrue($handler->write('session_id', 'some data'));
        $this->assertSame('some data', $handler->read('session_id'));
        $this->assertSame(1, $connection->table('sessions')->count());

        $session = $connection->table('sessions')->first();
        $this->assertNull($session->user_agent);
        $this->assertNull($session->ip_address);
        $this->assertNull($session->auth_provider);
        $this->assertNull($session->user_id);
    }

    public function testKnownMissingWriteDoesNotRepeatTheExistenceQuery(): void
    {
        $resolver = $this->app->make('db');
        $handler = new TrackingDatabaseSessionHandler($resolver, null, 'sessions', 120);

        $this->assertSame('', $handler->read(self::CURRENT_SESSION_ID));

        $queries = $this->recordQueries(
            fn () => $this->assertTrue($handler->write(self::CURRENT_SESSION_ID, 'new data')),
        );

        $this->assertCount(1, $queries);
        $this->assertStringContainsString('insert', strtolower($queries[0]->sql));
        $this->assertSame(1, $handler->readCount);
        $this->assertSame(1, $handler->insertCount);
        $this->assertSame(0, $handler->updateCount);
    }

    public function testConcurrentInsertFallbackStoresTheCurrentPayloadAndClearsItsOwner(): void
    {
        $resolver = $this->app->make('db');
        $connection = $resolver->connection();
        $handler = new TrackingDatabaseSessionHandler($resolver, null, 'sessions', 120);

        $this->assertSame('', $handler->read(self::CURRENT_SESSION_ID));

        $handler->beforeInsert = function () use ($connection): void {
            $connection->table('sessions')->insert([
                'id' => self::CURRENT_SESSION_ID,
                'auth_provider' => 'concurrent-provider',
                'user_id' => 'concurrent-owner',
                'payload' => base64_encode('concurrent payload'),
                'last_activity' => CarbonImmutable::now()->getTimestamp(),
            ]);
        };

        $this->assertTrue($handler->write(self::CURRENT_SESSION_ID, 'current payload'));

        $session = $connection->table('sessions')->where('id', self::CURRENT_SESSION_ID)->first();

        $this->assertNull($session->auth_provider);
        $this->assertNull($session->user_id);
        $this->assertSame('current payload', base64_decode($session->payload));
        $this->assertSame(1, $handler->readCount);
        $this->assertSame(1, $handler->insertCount);
        $this->assertSame(1, $handler->updateCount);
        $this->assertArrayNotHasKey('id', $handler->updatePayloads[0]);
    }

    public function testNonUniqueInsertFailurePropagates(): void
    {
        Schema::create('invalid_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id')->nullable();
            $table->string('auth_provider')->nullable();
            $table->string('required_value');
            $table->longText('payload');
            $table->integer('last_activity');
        });

        try {
            $handler = new TrackingDatabaseSessionHandler(
                $this->app->make('db'),
                null,
                'invalid_sessions',
                120,
            );
            $handler->setExists(false);

            try {
                $handler->write(self::CURRENT_SESSION_ID, 'data');

                $this->fail('Expected the invalid insert to fail.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('required_value', $exception->getMessage());
            }

            $this->assertSame(1, $handler->insertCount);
            $this->assertSame(0, $handler->updateCount);
            $this->assertSame(0, DB::table('invalid_sessions')->count());
        } finally {
            Schema::dropIfExists('invalid_sessions');
        }
    }

    public function testResolvedIdentityIsStoredAsAString(): void
    {
        $this->useGuardIdentity(42);

        $handler = $this->handler();

        $this->assertTrue($handler->supportsUserSessionManagement());
        $this->assertTrue($handler->write(self::CURRENT_SESSION_ID, 'authenticated payload'));
        $this->assertSame(
            'users',
            DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->value('auth_provider'),
        );
        $this->assertSame(
            '42',
            DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->value('user_id'),
        );
    }

    public function testUnresolvedLiveWritePreservesItsLastProvenOwner(): void
    {
        $this->insertSession(
            self::CURRENT_SESSION_ID,
            'user-1',
            'authenticated payload',
            CarbonImmutable::now()->getTimestamp(),
        );
        $handler = $this->handler(withContainer: false);

        $this->assertSame('authenticated payload', $handler->read(self::CURRENT_SESSION_ID));
        $this->assertTrue($handler->write(self::CURRENT_SESSION_ID, 'updated payload'));

        $this->assertSame(
            'users',
            DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->value('auth_provider'),
        );
        $this->assertSame(
            'user-1',
            DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->value('user_id'),
        );
    }

    public function testUnresolvedExpiredWriteClearsItsOldOwnerAtTheExactBoundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(10_000));
        $this->insertSession(
            self::CURRENT_SESSION_ID,
            'user-1',
            'authenticated payload',
            10_000 - 120 * 60,
        );
        $handler = $this->handler(withContainer: false);

        $this->assertSame('', $handler->read(self::CURRENT_SESSION_ID));
        $this->assertTrue($handler->write(self::CURRENT_SESSION_ID, 'replacement payload'));

        $session = DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->first();

        $this->assertNull($session->auth_provider);
        $this->assertNull($session->user_id);
        $this->assertSame('replacement payload', base64_decode($session->payload));
    }

    public function testUnownedWriteClearsItsOwnerWithoutResolvingTheGuard(): void
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->never();
        $this->useGuard($guard);

        $this->insertSession(
            self::CURRENT_SESSION_ID,
            'user-1',
            'authenticated payload',
            CarbonImmutable::now()->getTimestamp(),
        );
        $handler = $this->handler();
        $handler->read(self::CURRENT_SESSION_ID);

        UserSessionIdentity::suppress(self::CURRENT_SESSION_ID);

        $this->assertTrue($handler->write(self::CURRENT_SESSION_ID, 'replacement payload'));
        $this->assertNull(
            DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->value('auth_provider'),
        );
        $this->assertNull(
            DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->value('user_id'),
        );
    }

    public function testProviderlessAuthenticatedGuardClearsItsLastProvenOwner(): void
    {
        $this->useGuardIdentity('custom-user', null);
        $this->insertSession(
            self::CURRENT_SESSION_ID,
            'user-1',
            'authenticated payload',
            CarbonImmutable::now()->getTimestamp(),
        );
        $handler = $this->handler();

        $this->assertSame('authenticated payload', $handler->read(self::CURRENT_SESSION_ID));
        $this->assertTrue($handler->write(self::CURRENT_SESSION_ID, 'providerless payload'));

        $session = DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->first();

        $this->assertNull($session->auth_provider);
        $this->assertNull($session->user_id);
        $this->assertSame('providerless payload', base64_decode($session->payload));
    }

    public function testStoreInvalidationCannotPersistAnOwnedReplacement(): void
    {
        $this->useGuardIdentity('user-1');

        $handler = $this->handler();
        $store = new Store('name', $handler, self::CURRENT_SESSION_ID);
        $store->start();
        $store->put('login_web', 'user-1');
        $store->save();

        $this->assertSame(
            'user-1',
            DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->value('user_id'),
        );

        $this->assertTrue($store->invalidate());
        $replacementSessionId = $store->getId();
        $store->save();
        $store->save();

        $this->assertFalse(DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->exists());
        $this->assertNull(DB::table('sessions')->where('id', $replacementSessionId)->value('auth_provider'));
        $this->assertNull(DB::table('sessions')->where('id', $replacementSessionId)->value('user_id'));
        $payload = unserialize(base64_decode(
            DB::table('sessions')->where('id', $replacementSessionId)->value('payload'),
        ));

        $this->assertArrayNotHasKey('login_web', $payload);
    }

    public function testFailedReplacementWriteRetryRemainsUnowned(): void
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->never();
        $this->useGuard($guard);

        $this->insertSession(
            self::CURRENT_SESSION_ID,
            'user-1',
            serialize(['login_web' => 'user-1']),
            CarbonImmutable::now()->getTimestamp(),
        );
        $handler = new FailingOnceDatabaseSessionHandler(
            $this->app->make('db'),
            null,
            'sessions',
            120,
            $this->app,
        );
        $store = new Store('name', $handler, self::CURRENT_SESSION_ID);
        $store->start();
        $store->invalidate();
        $replacementSessionId = $store->getId();
        $handler->failNextInsert = true;

        try {
            $store->save();

            $this->fail('Expected the first replacement write to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to persist replacement session.', $exception->getMessage());
        }

        $this->assertFalse(DB::table('sessions')->where('id', $replacementSessionId)->exists());
        $this->assertTrue(UserSessionIdentity::resolve($this->app, $replacementSessionId)->isUnowned());

        $store->save();

        $session = DB::table('sessions')->where('id', $replacementSessionId)->first();

        $this->assertNull($session->auth_provider);
        $this->assertNull($session->user_id);
    }

    public function testManagedSessionsSupportIntegerNumericUuidAndUlidUserIdentifiers(): void
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $ulid = '01J5X7M8N9P0Q1R2S3T4V5W6X7';

        $this->insertSession(self::CURRENT_SESSION_ID, '42', 'first', $now);
        $this->insertSession(self::OTHER_SESSION_ID, $uuid, 'second', $now);
        $this->insertSession(self::THIRD_SESSION_ID, $ulid, 'third', $now);

        $handler = $this->handler();

        $this->assertSame([self::CURRENT_SESSION_ID], $handler->userSessions('users', 42)->pluck('id')->all());
        $this->assertSame([self::CURRENT_SESSION_ID], $handler->userSessions('users', '42')->pluck('id')->all());
        $this->assertSame([self::OTHER_SESSION_ID], $handler->userSessions('users', $uuid)->pluck('id')->all());
        $this->assertSame([self::THIRD_SESSION_ID], $handler->userSessions('users', $ulid)->pluck('id')->all());
    }

    public function testManagementIsolatesEqualUserIdentifiersAcrossProviders(): void
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $this->insertSession(self::CURRENT_SESSION_ID, '1', 'web first', $now, authProvider: 'users');
        $this->insertSession(self::OTHER_SESSION_ID, '1', 'admin', $now, authProvider: 'admins');
        $this->insertSession(self::THIRD_SESSION_ID, '1', 'web second', $now, authProvider: 'users');

        $handler = $this->handler();

        $this->assertSame(
            [self::CURRENT_SESSION_ID, self::THIRD_SESSION_ID],
            $handler->userSessions('users', '1')->pluck('id')->all(),
        );
        $this->assertSame(
            [self::OTHER_SESSION_ID],
            $handler->userSessions('admins', '1')->pluck('id')->all(),
        );
        $this->assertFalse($handler->destroyUserSession('admins', '1', self::CURRENT_SESSION_ID));
        $this->assertTrue($handler->destroyUserSession('users', '1', self::CURRENT_SESSION_ID));
        $this->assertSame(1, $handler->destroyUserSessions('users', '1'));
        $this->assertTrue(DB::table('sessions')->where('id', self::OTHER_SESSION_ID)->exists());
    }

    public function testListingReturnsActiveSessionsNewestFirstInOneQuery(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(10_000));
        $expiredSessionId = str_repeat('d', 40);
        $otherUserSessionId = str_repeat('e', 40);

        $this->insertSession(
            self::OTHER_SESSION_ID,
            'user-1',
            'second',
            9_990,
            '203.0.113.2',
            'Browser/2.0',
        );
        $this->insertSession(
            self::CURRENT_SESSION_ID,
            'user-1',
            'first',
            9_990,
            '203.0.113.1',
            'Browser/1.0',
        );
        $this->insertSession(self::THIRD_SESSION_ID, 'user-1', 'third', 9_980);
        $this->insertSession($expiredSessionId, 'user-1', 'expired', 2_800);
        $this->insertSession($otherUserSessionId, 'user-2', 'other', 9_995);

        $handler = $this->handler();
        $sessions = null;
        $queries = $this->recordQueries(function () use ($handler, &$sessions): void {
            $sessions = $handler->userSessions('users', 'user-1');
        });

        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('payload', strtolower($queries[0]->sql));
        $this->assertSame(
            [self::CURRENT_SESSION_ID, self::OTHER_SESSION_ID, self::THIRD_SESSION_ID],
            $sessions->pluck('id')->all(),
        );

        $first = $sessions->first();
        $this->assertSame('203.0.113.1', $first->ipAddress);
        $this->assertSame('Browser/1.0', $first->userAgent);
        $this->assertSame(9_990, $first->lastActivity->getTimestamp());
        $this->assertSame(17_190, $first->expiresAt->getTimestamp());
    }

    public function testOwnershipAwareSingleDeletionUsesOneQueryAndRequiresAnActiveMatch(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(10_000));
        $expiredSessionId = str_repeat('d', 40);

        $this->insertSession(self::CURRENT_SESSION_ID, 'user-1', 'active', 9_999);
        $this->insertSession($expiredSessionId, 'user-1', 'expired', 2_800);
        $handler = $this->handler();

        $queries = $this->recordQueries(function () use ($handler): void {
            $this->assertFalse($handler->destroyUserSession('users', 'user-2', self::CURRENT_SESSION_ID));
        });
        $this->assertCount(1, $queries);
        $this->assertTrue(DB::table('sessions')->where('id', self::CURRENT_SESSION_ID)->exists());

        $queries = $this->recordQueries(function () use ($handler): void {
            $this->assertTrue($handler->destroyUserSession('users', 'user-1', self::CURRENT_SESSION_ID));
        });
        $this->assertCount(1, $queries);

        $queries = $this->recordQueries(function () use ($handler, $expiredSessionId): void {
            $this->assertFalse($handler->destroyUserSession('users', 'user-1', $expiredSessionId));
        });
        $this->assertCount(1, $queries);
        $this->assertTrue(DB::table('sessions')->where('id', $expiredSessionId)->exists());
    }

    public function testBulkDeletionUsesOneQueryAndPreservesValidatedExceptions(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(10_000));
        $expiredSessionId = str_repeat('d', 40);
        $otherUserSessionId = str_repeat('e', 40);

        $this->insertSession(self::CURRENT_SESSION_ID, 'user-1', 'first', 9_999);
        $this->insertSession(self::OTHER_SESSION_ID, 'user-1', 'second', 9_998);
        $this->insertSession(self::THIRD_SESSION_ID, 'user-1', 'third', 9_997);
        $this->insertSession($expiredSessionId, 'user-1', 'expired', 2_800);
        $this->insertSession($otherUserSessionId, 'user-2', 'other', 9_999);
        $handler = $this->handler();

        $destroyed = null;
        $queries = $this->recordQueries(function () use ($handler, &$destroyed): void {
            $destroyed = $handler->destroyUserSessions(
                'users',
                'user-1',
                [self::OTHER_SESSION_ID, self::OTHER_SESSION_ID],
            );
        });

        $this->assertCount(1, $queries);
        $this->assertSame(2, $destroyed);
        $this->assertSame(
            [self::OTHER_SESSION_ID, $expiredSessionId, $otherUserSessionId],
            DB::table('sessions')->orderBy('id')->pluck('id')->all(),
        );
    }

    public function testRepositoryBulkDeletionUsesOneOrTwoStatementsBasedOnCurrentSessionState(): void
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $handler = $this->handler();

        $this->insertSession(self::CURRENT_SESSION_ID, 'user-1', serialize([]), $now);
        $this->insertSession(self::OTHER_SESSION_ID, 'user-1', serialize([]), $now);
        $this->insertSession(self::THIRD_SESSION_ID, 'user-1', serialize([]), $now);

        $unstartedStore = new Store('name', $handler, self::CURRENT_SESSION_ID);
        $unstartedSessions = new UserSessions('users', 'user-1', $handler, $unstartedStore);
        $destroyed = null;
        $queries = $this->recordQueries(function () use ($unstartedSessions, &$destroyed): void {
            $destroyed = $unstartedSessions->invalidateAll();
        });

        $this->assertSame(3, $destroyed);
        $this->assertCount(1, $queries);
        $this->assertSame(self::CURRENT_SESSION_ID, $unstartedStore->getId());

        $this->insertSession(self::CURRENT_SESSION_ID, 'user-1', serialize([]), $now);
        $this->insertSession(self::OTHER_SESSION_ID, 'user-1', serialize([]), $now);
        $this->insertSession(self::THIRD_SESSION_ID, 'user-1', serialize([]), $now);

        $startedHandler = $this->handler();
        $startedStore = new Store('name', $startedHandler, self::CURRENT_SESSION_ID);
        $startedStore->start();
        $startedSessions = new UserSessions('users', 'user-1', $startedHandler, $startedStore);
        $destroyed = null;
        $queries = $this->recordQueries(function () use ($startedSessions, &$destroyed): void {
            $destroyed = $startedSessions->invalidateAll();
        });

        $this->assertSame(3, $destroyed);
        $this->assertCount(2, $queries);
        $this->assertNotSame(self::CURRENT_SESSION_ID, $startedStore->getId());
    }

    public function testInvalidManagementIdentifiersFailBeforeQuerying(): void
    {
        $handler = $this->handler();
        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            ++$queryCount;
        });

        foreach ([
            fn () => $handler->userSessions('', 'user-1'),
            fn () => $handler->userSessions('users', ''),
            fn () => $handler->destroyUserSession('users', 'user-1', 'invalid'),
            fn () => $handler->destroyUserSessions('users', 'user-1', ['invalid']),
        ] as $operation) {
            try {
                $operation();

                $this->fail('Expected an invalid management identifier to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $queryCount);
    }

    public function testSessionSchemaUsesStringUserIdentifiersAndProviderOwnership(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('sessions', 'user_id'));
        $this->assertSame('varchar', Schema::getColumnType('sessions', 'auth_provider'));
    }

    public function testUserIndexUsesAvailableSparseIndexSupport(): void
    {
        $index = array_find(
            Schema::getIndexes('sessions'),
            static fn (array $index): bool => $index['name'] === 'sessions_user_id_index',
        );

        $this->assertNotNull($index);
        $this->assertSame(['user_id'], $index['columns']);
        $this->assertSame(
            in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true),
            $index['partial'],
        );
    }

    public function testDirectWriteUpdatesAnExistingSessionWithoutAttemptingDuplicateInsert(): void
    {
        $resolver = $this->app->make('db');
        $connection = $resolver->connection();
        $connection->table('sessions')->insert([
            'id' => 'existing-session',
            'payload' => base64_encode('old data'),
            'last_activity' => time(),
        ]);
        $handler = new TrackingDatabaseSessionHandler($resolver, null, 'sessions', 120);

        $queries = $this->recordQueries(
            fn () => $this->assertTrue($handler->write('existing-session', 'new data')),
        );

        $this->assertCount(2, $queries);
        $this->assertStringContainsString('select', strtolower($queries[0]->sql));
        $this->assertStringContainsString('update', strtolower($queries[1]->sql));
        $this->assertSame(1, $handler->readCount);
        $this->assertSame(0, $handler->insertCount);
        $this->assertSame(1, $handler->updateCount);
        $this->assertSame('new data', $handler->read('existing-session'));
    }

    public function testConstructionClearsStaleObjectSpecificExistenceState(): void
    {
        $resolver = $this->app->make('db');
        $handler = new class($resolver) extends TrackingDatabaseSessionHandler {
            public function __construct(ConnectionResolverInterface $resolver)
            {
                CoroutineContext::set(
                    self::DATABASE_EXISTS_CONTEXT_KEY_PREFIX . spl_object_id($this),
                    true
                );
                CoroutineContext::set(
                    self::DATABASE_EXPIRED_CONTEXT_KEY_PREFIX . spl_object_id($this),
                    true
                );

                parent::__construct($resolver, null, 'sessions', 120);
            }
        };

        $this->assertFalse($handler->getExists());
        $this->assertFalse($handler->existenceIsKnown());
        $this->assertFalse($handler->expiredForTesting());
        $this->assertTrue($handler->write('new-session', 'new data'));
        $this->assertSame(1, $handler->readCount);
        $this->assertSame(1, $handler->insertCount);
        $this->assertSame(0, $handler->updateCount);
        $this->assertSame('new data', $handler->read('new-session'));
    }

    public function testCloningClearsStaleObjectSpecificExistenceStateWithoutChangingSource(): void
    {
        $resolver = $this->app->make('db');
        $source = new class($resolver) extends TrackingDatabaseSessionHandler {
            public function __construct(ConnectionResolverInterface $resolver)
            {
                parent::__construct($resolver, null, 'sessions', 120);
            }

            public function __clone(): void
            {
                CoroutineContext::set(
                    self::DATABASE_EXISTS_CONTEXT_KEY_PREFIX . spl_object_id($this),
                    true
                );
                CoroutineContext::set(
                    self::DATABASE_EXPIRED_CONTEXT_KEY_PREFIX . spl_object_id($this),
                    true
                );

                parent::__clone();
            }
        };
        $source->setExists(true);
        $source->setExpiredForTesting(true);

        $clone = clone $source;

        $this->assertTrue($source->getExists());
        $this->assertTrue($source->expiredForTesting());
        $this->assertFalse($clone->getExists());
        $this->assertFalse($clone->existenceIsKnown());
        $this->assertFalse($clone->expiredForTesting());
        $this->assertTrue($clone->write('cloned-session', 'cloned data'));
        $this->assertSame(1, $clone->readCount);
        $this->assertSame(1, $clone->insertCount);
        $this->assertSame(0, $clone->updateCount);
        $this->assertSame('cloned data', $clone->read('cloned-session'));
        $this->assertTrue($source->getExists());
        $this->assertTrue($source->expiredForTesting());
    }

    protected function handler(bool $withContainer = true): DatabaseSessionHandler
    {
        return new DatabaseSessionHandler(
            $this->app->make('db'),
            null,
            'sessions',
            120,
            $withContainer ? $this->app : null,
        );
    }

    protected function insertSession(
        string $sessionId,
        ?string $userId,
        string $payload,
        int $lastActivity,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $authProvider = 'users',
    ): void {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'auth_provider' => $authProvider,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => base64_encode($payload),
            'last_activity' => $lastActivity,
        ]);
    }

    protected function useGuardIdentity(int|string|null $userId, ?string $authProvider = 'users'): void
    {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->andReturn($userId);

        $this->useGuard($guard, $authProvider);
    }

    protected function useGuard(Guard $guard, ?string $authProvider = 'users'): void
    {
        $guardConfig = ['driver' => 'custom'];

        if ($authProvider !== null) {
            $guardConfig['provider'] = $authProvider;
        }

        $config = $this->app->make('config');
        $config->set('auth.defaults.guard', 'web');
        $config->set('auth.guards.web', $guardConfig);

        $auth = new AuthManager($this->app);
        $auth->extend('custom', static fn () => $guard);
        $this->app->instance('auth', $auth);
    }

    /** @return list<QueryExecuted> */
    protected function recordQueries(Closure $callback): array
    {
        $queries = [];
        $recording = true;

        DB::listen(static function (QueryExecuted $event) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $event;
            }
        });

        try {
            $callback();
        } finally {
            $recording = false;
        }

        return $queries;
    }
}

class TrackingDatabaseSessionHandler extends DatabaseSessionHandler
{
    public int $readCount = 0;

    public int $insertCount = 0;

    public int $updateCount = 0;

    public ?Closure $beforeInsert = null;

    /** @var list<array<string, mixed>> */
    public array $updatePayloads = [];

    public function read(string $sessionId): false|string
    {
        ++$this->readCount;

        return parent::read($sessionId);
    }

    protected function performInsert(string $sessionId, array $payload): bool
    {
        if ($this->beforeInsert !== null) {
            $beforeInsert = $this->beforeInsert;
            $this->beforeInsert = null;
            $beforeInsert();
        }

        ++$this->insertCount;

        return parent::performInsert($sessionId, $payload);
    }

    protected function performUpdate(string $sessionId, array $payload): int
    {
        ++$this->updateCount;
        $this->updatePayloads[] = $payload;

        return parent::performUpdate($sessionId, $payload);
    }

    public function existenceIsKnown(): bool
    {
        return $this->existenceState() !== null;
    }

    public function expiredForTesting(): bool
    {
        return $this->getExpired();
    }

    public function setExpiredForTesting(bool $expired): void
    {
        $this->setExpired($expired);
    }
}

class FailingOnceDatabaseSessionHandler extends DatabaseSessionHandler
{
    public bool $failNextInsert = false;

    protected function performInsert(string $sessionId, array $payload): bool
    {
        if ($this->failNextInsert) {
            $this->failNextInsert = false;

            throw new RuntimeException('Unable to persist replacement session.');
        }

        return parent::performInsert($sessionId, $payload);
    }
}
