<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session\Redis;

use Hypervel\Auth\AuthManager;
use Hypervel\Config\Repository;
use Hypervel\Container\Container as ConcreteContainer;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RequiresHashFieldExpiration;
use Hypervel\Http\Request;
use Hypervel\Redis\Exceptions\LuaScriptException;
use Hypervel\Redis\RedisConnection;
use Hypervel\Session\RedisSessionHandler;
use Hypervel\Session\UserSessionIdentity;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis as PhpRedis;

class RedisSessionHandlerTest extends TestCase
{
    use InteractsWithRedis;
    use RequiresHashFieldExpiration;

    private const string SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string OTHER_SESSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string THIRD_SESSION_ID = 'cccccccccccccccccccccccccccccccccccccccc';

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('database.redis.default.options.prefix', 'session-test:');
    }

    #[DataProvider('plainPayloadProvider')]
    public function testPlainSessionsRoundTripAsRawStrings(string $payload): void
    {
        $handler = $this->handler();
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $this->assertTrue($handler->write(self::SESSION_ID, $payload));
            $this->assertSame($payload, $handler->read(self::SESSION_ID));
            $this->assertSame($payload, $redis->get($this->physicalPayloadKey(self::SESSION_ID)));
            $this->assertTrue($handler->destroy(self::SESSION_ID));
            $this->assertSame('', $handler->read(self::SESSION_ID));
        } finally {
            $redis->close();
        }
    }

    public static function plainPayloadProvider(): array
    {
        return [
            'json' => ['{"authenticated":true}'],
            'php serialization' => [serialize(['authenticated' => true])],
            'opaque encrypted bytes' => ["\x01\x02ciphertext\x00payload"],
        ];
    }

    public function testReadsValidEnvelopesAndRejectsCorruptFamilyRecordsRegardlessOfTrackingFlag(): void
    {
        $redis = $this->rawRedisClientWithoutPrefix();
        $handler = $this->handler();
        $key = $this->physicalPayloadKey(self::SESSION_ID);

        try {
            $redis->set($key, "\0HVS1" . str_repeat('a', 32) . 'payload');
            $this->assertSame('payload', $handler->read(self::SESSION_ID));

            foreach ([
                "\0HVS",
                "\0HVS1" . str_repeat('a', 31),
                "\0HVS1" . str_repeat('A', 32) . 'payload',
                "\0HVS2" . str_repeat('a', 32) . 'payload',
            ] as $corrupt) {
                $redis->set($key, $corrupt);
                $this->assertSame('', $handler->read(self::SESSION_ID));
            }
        } finally {
            $redis->close();
        }
    }

    public function testTrackedWriteStoresEnvelopeMetadataAndSynchronizedExpiry(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $lastActivity = time();
        $expiresAt = $lastActivity + 600;
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($lastActivity));
        $handler = $this->handler(tracked: true, container: $this->identityContainer('user-1'));
        $owner = $this->ownerDigest('users', 'user-1');
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $this->assertTrue($handler->write(self::SESSION_ID, 'payload'));
            $this->assertSame(
                "\0HVS1{$owner}payload",
                $redis->get($this->physicalPayloadKey(self::SESSION_ID)),
            );

            $metadata = json_decode(
                $redis->hGet($this->physicalUserIndexKey($owner), self::SESSION_ID),
                true,
            );
            $this->assertSame([
                'ip_address' => '203.0.113.10',
                'user_agent' => 'Browser/1.0',
                'last_activity' => $lastActivity,
            ], $metadata);

            $payloadTtl = $redis->ttl($this->physicalPayloadKey(self::SESSION_ID));
            $fieldTtl = $redis->httl($this->physicalUserIndexKey($owner), [self::SESSION_ID]);
            $this->assertGreaterThan(590, $payloadTtl);
            $this->assertGreaterThan(590, $fieldTtl[0]);
            $this->assertLessThanOrEqual(1, abs($payloadTtl - $fieldTtl[0]));
            $this->assertSame($expiresAt, $redis->expiretime($this->physicalPayloadKey(self::SESSION_ID)));
            $this->assertSame(
                [$expiresAt],
                $redis->hexpiretime($this->physicalUserIndexKey($owner), [self::SESSION_ID]),
            );

            $sessions = $handler->userSessions('users', 'user-1');
            $this->assertCount(1, $sessions);
            $this->assertSame(self::SESSION_ID, $sessions->first()->id);
            $this->assertSame('203.0.113.10', $sessions->first()->ipAddress);
            $this->assertSame('Browser/1.0', $sessions->first()->userAgent);
            $this->assertSame(CarbonImmutable::now()->getTimestamp(), $sessions->first()->lastActivity->getTimestamp());
        } finally {
            $redis->close();
        }
    }

    public function testTrackedReassignmentMovesTheSessionBetweenUserIndexes(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $redis = $this->rawRedisClientWithoutPrefix();
        $firstOwner = $this->ownerDigest('users', 'user-1');
        $secondOwner = $this->ownerDigest('users', 'user-2');

        try {
            $this->assertTrue($this->handler(true, $this->identityContainer('user-1'))->write(self::SESSION_ID, 'first'));
            $this->assertTrue($this->handler(true, $this->identityContainer('user-2'))->write(self::SESSION_ID, 'second'));

            $this->assertFalse($redis->hExists($this->physicalUserIndexKey($firstOwner), self::SESSION_ID));
            $this->assertTrue($redis->hExists($this->physicalUserIndexKey($secondOwner), self::SESSION_ID));
            $this->assertSame(
                "\0HVS1{$secondOwner}second",
                $redis->get($this->physicalPayloadKey(self::SESSION_ID)),
            );
        } finally {
            $redis->close();
        }
    }

    public function testEqualUserIdentifiersRemainIsolatedAcrossProviders(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $usersOwner = $this->ownerDigest('users', '1');
        $adminsOwner = $this->ownerDigest('admins', '1');
        $usersHandler = $this->handler(true, $this->identityContainer('1', authProvider: 'users'));
        $adminsHandler = $this->handler(true, $this->identityContainer('1', authProvider: 'admins'));
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $this->assertTrue($usersHandler->write(self::SESSION_ID, 'user'));
            $this->assertTrue($adminsHandler->write(self::OTHER_SESSION_ID, 'admin'));

            $this->assertTrue($redis->hExists(
                $this->physicalUserIndexKey($usersOwner),
                self::SESSION_ID,
            ));
            $this->assertFalse($redis->hExists(
                $this->physicalUserIndexKey($usersOwner),
                self::OTHER_SESSION_ID,
            ));
            $this->assertTrue($redis->hExists(
                $this->physicalUserIndexKey($adminsOwner),
                self::OTHER_SESSION_ID,
            ));
            $this->assertSame(
                [self::SESSION_ID],
                $usersHandler->userSessions('users', '1')->pluck('id')->all(),
            );
            $this->assertSame(
                [self::OTHER_SESSION_ID],
                $adminsHandler->userSessions('admins', '1')->pluck('id')->all(),
            );
            $this->assertFalse($adminsHandler->destroyUserSession('admins', '1', self::SESSION_ID));
            $this->assertTrue($usersHandler->destroyUserSession('users', '1', self::SESSION_ID));
            $this->assertSame(1, $adminsHandler->destroyUserSessions('admins', '1'));
        } finally {
            $redis->close();
        }
    }

    public function testProviderlessAuthenticatedWriteClearsManagedOwnership(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $owner = $this->ownerDigest('users', 'user-1');
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $this->assertTrue($this->handler(true, $this->identityContainer('user-1'))->write(self::SESSION_ID, 'first'));
            $this->assertTrue($this->handler(
                true,
                $this->identityContainer('custom-user', authProvider: null),
            )->write(self::SESSION_ID, 'providerless'));

            $this->assertSame('providerless', $redis->get($this->physicalPayloadKey(self::SESSION_ID)));
            $this->assertFalse($redis->hExists($this->physicalUserIndexKey($owner), self::SESSION_ID));
        } finally {
            $redis->close();
        }
    }

    public function testTrackedWritesUseOnlyTheCurrentlySelectedGuard(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $primaryGuard = m::mock(Guard::class);
        $primaryGuard->shouldReceive('id')->once()->andReturn('primary-user');
        $secondaryGuard = m::mock(Guard::class);
        $secondaryGuard->shouldReceive('id')->once()->andReturn('secondary-user');
        $config = $this->app->make('config');
        $config->set([
            'auth.defaults.guard' => 'primary',
            'auth.guards.primary' => ['driver' => 'session-test', 'provider' => 'users'],
            'auth.guards.secondary' => ['driver' => 'session-test', 'provider' => 'users'],
        ]);
        $auth = $this->app->make(AuthManager::class);
        $auth->extend('session-test', fn ($app, string $name) => match ($name) {
            'primary' => $primaryGuard,
            'secondary' => $secondaryGuard,
        });
        RequestContext::set(Request::create('/'));
        $handler = $this->handler(tracked: true, container: $this->app);
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $auth->shouldUse('secondary');
            $this->assertTrue($handler->write(self::SESSION_ID, 'secondary'));

            $auth->shouldUse('primary');
            $this->assertTrue($handler->write(self::OTHER_SESSION_ID, 'primary'));

            $this->assertTrue($redis->hExists(
                $this->physicalUserIndexKey($this->ownerDigest('users', 'secondary-user')),
                self::SESSION_ID,
            ));
            $this->assertFalse($redis->hExists(
                $this->physicalUserIndexKey($this->ownerDigest('users', 'primary-user')),
                self::SESSION_ID,
            ));
            $this->assertTrue($redis->hExists(
                $this->physicalUserIndexKey($this->ownerDigest('users', 'primary-user')),
                self::OTHER_SESSION_ID,
            ));
            $this->assertFalse($redis->hExists(
                $this->physicalUserIndexKey($this->ownerDigest('users', 'secondary-user')),
                self::OTHER_SESSION_ID,
            ));
        } finally {
            $redis->close();
        }
    }

    public function testIdentityLessWritePreservesOwnerAndRequestMetadata(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $firstActivity = time();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($firstActivity));
        $owner = $this->ownerDigest('users', 'user-1');
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $this->assertTrue($this->handler(true, $this->identityContainer('user-1'))->write(self::SESSION_ID, 'first'));
            $lastActivity = $firstActivity + 300;
            $expiresAt = $lastActivity + 600;
            CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($lastActivity));
            RequestContext::forget();

            $this->assertTrue($this->handler(tracked: true)->write(self::SESSION_ID, 'second'));
            $this->assertSame(
                "\0HVS1{$owner}second",
                $redis->get($this->physicalPayloadKey(self::SESSION_ID)),
            );
            $metadata = json_decode(
                $redis->hGet($this->physicalUserIndexKey($owner), self::SESSION_ID),
                true,
            );
            $this->assertSame('203.0.113.10', $metadata['ip_address']);
            $this->assertSame('Browser/1.0', $metadata['user_agent']);
            $this->assertSame($lastActivity, $metadata['last_activity']);

            $payloadTtl = $redis->ttl($this->physicalPayloadKey(self::SESSION_ID));
            $fieldTtl = $redis->httl($this->physicalUserIndexKey($owner), [self::SESSION_ID]);
            $this->assertGreaterThan(590, $payloadTtl);
            $this->assertGreaterThan(590, $fieldTtl[0]);
            $this->assertLessThanOrEqual(1, abs($payloadTtl - $fieldTtl[0]));
            $this->assertSame($expiresAt, $redis->expiretime($this->physicalPayloadKey(self::SESSION_ID)));
            $this->assertSame(
                [$expiresAt],
                $redis->hexpiretime($this->physicalUserIndexKey($owner), [self::SESSION_ID]),
            );
        } finally {
            $redis->close();
        }
    }

    public function testUnownedWriteRemovesOwnershipAndStoresRawPayload(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $owner = $this->ownerDigest('users', 'user-1');
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $this->assertTrue($this->handler(true, $this->identityContainer('user-1'))->write(self::SESSION_ID, 'first'));
            UserSessionIdentity::suppress(self::SESSION_ID);

            $this->assertTrue($this->handler(true, $this->identityContainer('user-1', guardCalls: 0))->write(self::SESSION_ID, 'flushed'));
            $this->assertSame('flushed', $redis->get($this->physicalPayloadKey(self::SESSION_ID)));
            $this->assertFalse($redis->hExists($this->physicalUserIndexKey($owner), self::SESSION_ID));
        } finally {
            $redis->close();
        }
    }

    public function testScopedDestroyRequiresAuthoritativeOwnershipAndPrunesStaleIndexFields(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $owner = $this->ownerDigest('users', 'user-1');
        $otherOwner = $this->ownerDigest('users', 'user-2');
        $redis = $this->rawRedisClientWithoutPrefix();
        $handler = $this->handler(tracked: true, container: $this->identityContainer('user-1'));

        try {
            $this->assertTrue($handler->write(self::SESSION_ID, 'payload'));
            $redis->hsetex(
                $this->physicalUserIndexKey($otherOwner),
                [self::SESSION_ID => $this->metadata()],
                ['EX' => 600],
            );

            $this->assertFalse($handler->destroyUserSession('users', 'user-2', self::SESSION_ID));
            $this->assertTrue($redis->exists($this->physicalPayloadKey(self::SESSION_ID)) > 0);
            $this->assertFalse($redis->hExists($this->physicalUserIndexKey($otherOwner), self::SESSION_ID));

            $this->assertTrue($handler->destroyUserSession('users', 'user-1', self::SESSION_ID));
            $this->assertSame(0, $redis->exists($this->physicalPayloadKey(self::SESSION_ID)));
            $this->assertFalse($redis->hExists($this->physicalUserIndexKey($owner), self::SESSION_ID));
        } finally {
            $redis->close();
        }
    }

    public function testBulkDestroyPreservesExceptionsAndRemovesOnlyProvenSessions(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $owner = $this->ownerDigest('users', 'user-1');
        $handler = $this->handler(tracked: true, container: $this->identityContainer('user-1', writes: 3));
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            foreach ([self::SESSION_ID, self::OTHER_SESSION_ID, self::THIRD_SESSION_ID] as $sessionId) {
                $this->assertTrue($handler->write($sessionId, $sessionId));
            }

            $redis->set($this->physicalPayloadKey(self::THIRD_SESSION_ID), 'unowned');

            $this->assertSame(1, $handler->destroyUserSessions('users', 'user-1', [self::SESSION_ID]));
            $this->assertTrue($redis->exists($this->physicalPayloadKey(self::SESSION_ID)) > 0);
            $this->assertSame(0, $redis->exists($this->physicalPayloadKey(self::OTHER_SESSION_ID)));
            $this->assertTrue($redis->exists($this->physicalPayloadKey(self::THIRD_SESSION_ID)) > 0);
            $this->assertSame([self::SESSION_ID], $redis->hKeys($this->physicalUserIndexKey($owner)));
        } finally {
            $redis->close();
        }
    }

    public function testListingPrunesMalformedMetadataWithoutHidingValidSessions(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $owner = $this->ownerDigest('users', 'user-1');
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $redis->hsetex(
                $this->physicalUserIndexKey($owner),
                [
                    self::SESSION_ID => $this->metadata(lastActivity: 200),
                    self::OTHER_SESSION_ID => '{',
                    'invalid' => $this->metadata(lastActivity: 100),
                ],
                ['EX' => 600],
            );

            $sessions = $this->handler(tracked: true)->userSessions('users', 'user-1');

            $this->assertCount(1, $sessions);
            $this->assertSame(self::SESSION_ID, $sessions->first()->id);
            $this->assertSame([self::SESSION_ID], $redis->hKeys($this->physicalUserIndexKey($owner)));
        } finally {
            $redis->close();
        }
    }

    public function testTrackingCanBeEnabledAndDisabledWithoutLosingTheSession(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $owner = $this->ownerDigest('users', 'user-1');
        $redis = $this->rawRedisClientWithoutPrefix();
        $plain = $this->handler();
        $tracked = $this->handler(true, $this->identityContainer('user-1'));

        try {
            $this->assertTrue($plain->write(self::SESSION_ID, 'raw'));
            $this->assertSame('raw', $tracked->read(self::SESSION_ID));
            $this->assertTrue($tracked->write(self::SESSION_ID, 'tracked'));
            $this->assertSame('tracked', $plain->read(self::SESSION_ID));
            $this->assertTrue($plain->write(self::SESSION_ID, 'raw-again'));
            $this->assertSame('raw-again', $redis->get($this->physicalPayloadKey(self::SESSION_ID)));
            $this->assertTrue($redis->hExists($this->physicalUserIndexKey($owner), self::SESSION_ID));
        } finally {
            $redis->close();
        }
    }

    #[DataProvider('lateCleanupIdentityProvider')]
    public function testLateObsoleteIndexFailureLeavesTheNewPayloadAuthoritative(?string $newUserId): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $oldOwner = $this->ownerDigest('users', 'old-user');
        $newOwner = $newUserId === null ? null : $this->ownerDigest('users', $newUserId);
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $redis->set(
                $this->physicalPayloadKey(self::SESSION_ID),
                "\0HVS1{$oldOwner}old-payload",
                ['ex' => 600],
            );
            $redis->set($this->physicalUserIndexKey($oldOwner), 'wrong-type', ['ex' => 600]);

            $handler = $newUserId === null
                ? $this->handler(tracked: true)
                : $this->handler(true, $this->identityContainer($newUserId));

            if ($newUserId === null) {
                UserSessionIdentity::suppress(self::SESSION_ID);
            }

            try {
                $handler->write(self::SESSION_ID, 'new-payload');
                $this->fail('Expected obsolete index cleanup to fail.');
            } catch (LuaScriptException) {
                $this->addToAssertionCount(1);
            }

            $expected = $newOwner === null
                ? 'new-payload'
                : "\0HVS1{$newOwner}new-payload";
            $this->assertSame($expected, $redis->get($this->physicalPayloadKey(self::SESSION_ID)));

            if ($newOwner !== null) {
                $this->assertTrue($redis->hExists($this->physicalUserIndexKey($newOwner), self::SESSION_ID));
            }
        } finally {
            $redis->close();
        }
    }

    public static function lateCleanupIdentityProvider(): array
    {
        return [
            'reassignment' => ['new-user'],
            'unowned' => [null],
        ];
    }

    public function testResultingIndexFailureLeavesTheOldPayloadAuthoritative(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $oldOwner = $this->ownerDigest('users', 'old-user');
        $newOwner = $this->ownerDigest('users', 'new-user');
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $redis->set(
                $this->physicalPayloadKey(self::SESSION_ID),
                "\0HVS1{$oldOwner}old-payload",
                ['ex' => 600],
            );
            $redis->set($this->physicalUserIndexKey($newOwner), 'wrong-type', ['ex' => 600]);

            try {
                $this->handler(true, $this->identityContainer('new-user'))->write(self::SESSION_ID, 'new-payload');
                $this->fail('Expected the resulting index write to fail.');
            } catch (LuaScriptException) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame(
                "\0HVS1{$oldOwner}old-payload",
                $redis->get($this->physicalPayloadKey(self::SESSION_ID)),
            );
        } finally {
            $redis->close();
        }
    }

    public function testHashFieldExpiryRemovesTheFinalUserIndex(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        $owner = $this->ownerDigest('users', 'user-1');
        $redis = $this->rawRedisClientWithoutPrefix();

        try {
            $this->assertTrue($this->handler(true, $this->identityContainer('user-1'))->write(self::SESSION_ID, 'payload'));
            $redis->pexpire($this->physicalPayloadKey(self::SESSION_ID), 50);
            $redis->hpexpire($this->physicalUserIndexKey($owner), 50, [self::SESSION_ID]);
            usleep(100_000);

            $this->assertFalse($redis->hGet($this->physicalUserIndexKey($owner), self::SESSION_ID));
            $this->assertSame(0, $redis->exists($this->physicalPayloadKey(self::SESSION_ID)));
            $this->assertSame(0, $redis->exists($this->physicalUserIndexKey($owner)));
        } finally {
            $redis->close();
        }
    }

    #[DataProvider('redisEncodingProvider')]
    public function testSerializerAndCompressionOptionsDoNotChangeAnySessionOperationAndAreRestoredAfterFailure(
        bool $withCompression,
    ): void {
        $this->skipIfHashFieldExpirationUnsupported();

        if ($withCompression && ! defined('Redis::COMPRESSION_LZF')) {
            $this->markTestSkipped('Redis extension is not configured to support lzf compression.');
        }

        $connectionName = $withCompression ? 'session_encoded_lzf' : 'session_encoded_serializer';
        $physicalPrefix = $withCompression ? 'session-encoded-lzf:' : 'session-encoded-serializer:';
        $connectionOptions = [
            'prefix' => $physicalPrefix,
            'serializer' => PhpRedis::SERIALIZER_PHP,
        ];

        if ($withCompression) {
            $connectionOptions['compression'] = PhpRedis::COMPRESSION_LZF;
        }

        $connectionName = $this->createRedisConnectionWithOptions(
            $connectionName,
            $connectionOptions,
            maxConnections: 1,
        );
        $handler = $this->handler(
            tracked: true,
            container: $this->identityContainer('user-1'),
            connection: $connectionName,
        );
        $plainHandler = $this->handler(connection: $connectionName);
        $owner = $this->ownerDigest('users', 'user-1');
        $redis = $this->rawRedisClientWithoutPrefix($connectionName);

        try {
            $this->assertTrue($plainHandler->write(self::OTHER_SESSION_ID, 'plain'));
            $this->assertSame('plain', $plainHandler->read(self::OTHER_SESSION_ID));
            $this->assertTrue($plainHandler->destroy(self::OTHER_SESSION_ID));
            $this->assertSame('', $plainHandler->read(self::OTHER_SESSION_ID));

            $this->assertTrue($handler->write(self::SESSION_ID, 'payload'));
            $this->assertSame(
                "\0HVS1{$owner}payload",
                $redis->get($physicalPrefix . 'sessions:' . self::SESSION_ID),
            );
            $this->assertSame('payload', $handler->read(self::SESSION_ID));
            $this->assertSame(self::SESSION_ID, $handler->userSessions('users', 'user-1')->sole()->id);

            $redis->set(
                $physicalPrefix . 'sessions:_users:' . $this->ownerDigest('users', 'user-2') . ':sessions',
                'wrong-type',
            );

            try {
                $this->handler(
                    tracked: true,
                    container: $this->identityContainer('user-2'),
                    connection: $connectionName,
                )->write(self::SESSION_ID, 'changed');
                $this->fail('Expected the tracked write to fail.');
            } catch (LuaScriptException) {
                $this->addToAssertionCount(1);
            }

            $options = $this->app->make(RedisFactory::class)
                ->connection($connectionName)
                ->withConnection(static fn (RedisConnection $connection): array => [
                    $connection->getOption(PhpRedis::OPT_SERIALIZER),
                    $connection->getOption(PhpRedis::OPT_COMPRESSION),
                ], transform: false);

            $this->assertSame(PhpRedis::SERIALIZER_PHP, $options[0]);
            $this->assertSame(
                $withCompression ? PhpRedis::COMPRESSION_LZF : PhpRedis::COMPRESSION_NONE,
                $options[1],
            );
            $this->assertTrue($handler->destroy(self::SESSION_ID));
            $this->assertSame('', $handler->read(self::SESSION_ID));
        } finally {
            $redis->close();
        }
    }

    public static function redisEncodingProvider(): array
    {
        return [
            'PHP serializer' => [false],
            'PHP serializer and LZF compression' => [true],
        ];
    }

    private function handler(
        bool $tracked = false,
        ?Container $container = null,
        string $connection = 'default',
    ): RedisSessionHandler {
        return new RedisSessionHandler(
            $this->app->make(RedisFactory::class),
            $connection,
            'sessions:',
            10,
            $tracked,
            $container,
        );
    }

    private function identityContainer(
        string $userId,
        int $writes = 1,
        ?int $guardCalls = null,
        ?string $authProvider = 'users',
    ): Container {
        $guardCalls ??= $writes;
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->times($guardCalls)->andReturn($userId);

        $guardConfig = ['driver' => 'session-test'];

        if ($authProvider !== null) {
            $guardConfig['provider'] = $authProvider;
        }

        $request = Request::create('/', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Browser/1.0',
        ]);
        RequestContext::set($request);

        $container = new ConcreteContainer;
        $container->instance('config', new Repository([
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => ['web' => $guardConfig],
            ],
        ]));
        $auth = new AuthManager($container);
        $auth->extend('session-test', static fn () => $guard);
        $container->instance('auth', $auth);
        $container->instance('request', $request);

        return $container;
    }

    private function metadata(int $lastActivity = 100): string
    {
        return json_encode([
            'ip_address' => null,
            'user_agent' => null,
            'last_activity' => $lastActivity,
        ]);
    }

    private function physicalPayloadKey(string $sessionId): string
    {
        return 'session-test:sessions:' . $sessionId;
    }

    private function physicalUserIndexKey(string $owner): string
    {
        return 'session-test:sessions:_users:' . $owner . ':sessions';
    }

    private function ownerDigest(string $authProvider, string $userId): string
    {
        return hash('xxh128', strlen($authProvider) . ':' . $authProvider . ':' . $userId);
    }
}
