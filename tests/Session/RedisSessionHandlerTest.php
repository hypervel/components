<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Auth\AuthManager;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Http\Request;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Session\RedisSessionHandler;
use Hypervel\Session\UserSessionIdentity;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use UnexpectedValueException;

class RedisSessionHandlerTest extends TestCase
{
    private const string SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string OTHER_SESSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private RedisFactory $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = m::mock(RedisFactory::class);
    }

    public function testLifecycleAndCapabilityMethodsDoNotUseRedis(): void
    {
        $this->redis->shouldReceive('connection')->never();

        $plain = $this->handler();
        $tracked = $this->handler(tracked: true);

        $this->assertTrue($plain->open('', 'session'));
        $this->assertTrue($plain->close());
        $this->assertSame(0, $plain->gc(120));
        $this->assertFalse($plain->supportsUserSessionManagement());
        $this->assertTrue($tracked->supportsUserSessionManagement());
    }

    #[DataProvider('storedPayloadProvider')]
    public function testReadUnderstandsRawAndVersionedPayloads(string $stored, string $expected): void
    {
        $connection = $this->expectConnection();
        $connection->shouldReceive('get')
            ->once()
            ->with('sessions:' . self::SESSION_ID)
            ->andReturn($stored);

        $this->assertSame($expected, $this->handler()->read(self::SESSION_ID));
    }

    public static function storedPayloadProvider(): array
    {
        return [
            'raw payload' => ['payload', 'payload'],
            'raw nul payload outside family' => ["\0other", "\0other"],
            'valid envelope' => ["\0HVS1" . str_repeat('a', 32) . 'payload', 'payload'],
            'valid empty payload' => ["\0HVS1" . str_repeat('a', 32), ''],
            'short version one' => ["\0HVS1" . str_repeat('a', 31), ''],
            'uppercase owner' => ["\0HVS1" . str_repeat('A', 32) . 'payload', ''],
            'invalid owner' => ["\0HVS1" . str_repeat('g', 32) . 'payload', ''],
            'unknown version' => ["\0HVS2" . str_repeat('a', 32) . 'payload', ''],
            'family only' => ["\0HVS", ''],
        ];
    }

    public function testMissingReadReturnsAnEmptyString(): void
    {
        $connection = $this->expectConnection();
        $connection->shouldReceive('get')->once()->andReturnFalse();

        $this->assertSame('', $this->handler()->read(self::SESSION_ID));
    }

    public function testInvalidReadResultFailsExplicitly(): void
    {
        $connection = $this->expectConnection();
        $connection->shouldReceive('get')->once()->andReturn(123);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Redis returned an invalid session payload.');

        $this->handler()->read(self::SESSION_ID);
    }

    public function testTrackingDisabledWriteUsesOneRawSetex(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->never();
        $container->shouldReceive('make')->never();
        $connection = $this->expectConnection();
        $connection->shouldReceive('setex')
            ->once()
            ->with('sessions:' . self::SESSION_ID, 600, 'payload')
            ->andReturnTrue();

        $this->assertTrue($this->handler(container: $container)->write(self::SESSION_ID, 'payload'));
    }

    public function testTrackingDisabledWriteReturnsFalseWithoutPublishingSuccess(): void
    {
        $connection = $this->expectConnection();
        $connection->shouldReceive('setex')->once()->andReturnFalse();

        $this->assertFalse($this->handler()->write(self::SESSION_ID, 'payload'));
    }

    public function testTrackingDisabledDestroyUsesOneRawDelete(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->never();
        $container->shouldReceive('make')->never();
        $connection = $this->expectConnection();
        $connection->shouldReceive('del')
            ->once()
            ->with('sessions:' . self::SESSION_ID)
            ->andReturn(1);

        $this->assertTrue($this->handler(container: $container)->destroy(self::SESSION_ID));
    }

    public function testStandaloneTrackedWriteUsesOneScriptWithPhysicalPrefixAndFreshMetadata(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $container = $this->authenticatedContainer('user-1', withRequest: true);
        $connection = $this->expectConnection();
        $captured = [];

        $connection->shouldReceive('getOption')->once()->andReturn('redis:');
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturnUsing(static function (string $script, array $keys, array $arguments) use (&$captured): int {
                $captured = compact('script', 'keys', 'arguments');

                return 1;
            });

        $this->assertTrue($this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload'));
        $this->assertSame(['sessions:' . self::SESSION_ID], $captured['keys']);
        $this->assertSame('600', $captured['arguments'][0]);
        $this->assertSame(self::SESSION_ID, $captured['arguments'][1]);
        $this->assertSame('redis:sessions:', $captured['arguments'][2]);
        $this->assertSame('resolved', $captured['arguments'][3]);
        $this->assertSame($this->ownerDigest('users', 'user-1'), $captured['arguments'][4]);
        $this->assertSame('payload', $captured['arguments'][5]);
        $this->assertSame('1', $captured['arguments'][7]);
        $this->assertSame((string) CarbonImmutable::now()->getTimestamp(), $captured['arguments'][8]);
        $this->assertSame([
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Browser/1.0',
            'last_activity' => CarbonImmutable::now()->getTimestamp(),
        ], json_decode($captured['arguments'][6], true));
        $this->assertStringNotContainsString('{', $captured['keys'][0]);
    }

    public function testStandaloneTrackedWritePassesUnresolvedIntentWithoutRequestMetadata(): void
    {
        $connection = $this->expectConnection();
        $captured = [];

        $connection->shouldReceive('getOption')->once()->andReturn('');
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturnUsing(static function (string $script, array $keys, array $arguments) use (&$captured): int {
                $captured = compact('script', 'keys', 'arguments');

                return 1;
            });

        $this->assertTrue($this->handler(tracked: true)->write(self::SESSION_ID, 'payload'));
        $this->assertSame('unresolved', $captured['arguments'][3]);
        $this->assertSame('', $captured['arguments'][4]);
        $this->assertSame('0', $captured['arguments'][7]);
        $this->assertNull(json_decode($captured['arguments'][6], true)['ip_address']);
        $this->assertNull(json_decode($captured['arguments'][6], true)['user_agent']);
    }

    public function testStandaloneTrackedWritePassesUnownedIntentAndMapsNonSuccessToFalse(): void
    {
        UserSessionIdentity::suppress(self::SESSION_ID);
        $connection = $this->expectConnection();
        $captured = [];

        $connection->shouldReceive('getOption')->once()->andReturn('');
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturnUsing(static function (string $script, array $keys, array $arguments) use (&$captured): false {
                $captured = compact('script', 'keys', 'arguments');

                return false;
            });

        $this->assertFalse($this->handler(tracked: true)->write(self::SESSION_ID, 'payload'));
        $this->assertSame('unowned', $captured['arguments'][3]);
    }

    public function testProviderlessAuthenticatedWriteUsesUnownedIntent(): void
    {
        $container = $this->authenticatedContainer('custom-user', withRequest: false, authProvider: null);
        $connection = $this->expectConnection();
        $captured = [];

        $connection->shouldReceive('getOption')->once()->andReturn('');
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturnUsing(static function (string $script, array $keys, array $arguments) use (&$captured): int {
                $captured = compact('script', 'keys', 'arguments');

                return 1;
            });

        $this->assertTrue($this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload'));
        $this->assertSame('unowned', $captured['arguments'][3]);
        $this->assertSame('', $captured['arguments'][4]);
    }

    public function testProviderQualifiedOwnerDigestIsolatesEqualUserIdentifiers(): void
    {
        $owners = [];

        foreach (['users', 'admins'] as $authProvider) {
            $container = $this->authenticatedContainer('1', withRequest: false, authProvider: $authProvider);
            $connection = $this->expectConnection();

            $connection->shouldReceive('getOption')->once()->andReturn('');
            $connection->shouldReceive('evalWithShaCache')
                ->once()
                ->andReturnUsing(static function (
                    string $script,
                    array $keys,
                    array $arguments,
                ) use (&$owners, $authProvider): int {
                    $owners[$authProvider] = $arguments[4];

                    return 1;
                });

            $this->assertTrue($this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload'));
        }

        $this->assertSame($this->ownerDigest('users', '1'), $owners['users']);
        $this->assertSame($this->ownerDigest('admins', '1'), $owners['admins']);
        $this->assertNotSame($owners['users'], $owners['admins']);
    }

    public function testClusterWriteUpdatesNewIndexBeforeCleaningOldIndex(): void
    {
        $oldOwner = $this->ownerDigest('users', 'old-user');
        $newOwner = $this->ownerDigest('users', 'new-user');
        $container = $this->authenticatedContainer('new-user', withRequest: true);
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->andReturn([$oldOwner, $newOwner]);
        $connection->shouldReceive('hsetex')
            ->once()
            ->ordered()
            ->with(
                'sessions:_users:' . $newOwner . ':sessions',
                m::on(static fn (array $fields): bool => array_key_exists(self::SESSION_ID, $fields)),
                ['EX' => 600],
            )
            ->andReturn(1);
        $connection->shouldReceive('hdel')
            ->once()
            ->ordered()
            ->with('sessions:_users:' . $oldOwner . ':sessions', self::SESSION_ID)
            ->andReturn(1);

        $this->assertTrue($this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterWritePreservesSameOwnerMetadataWithOneIndexScript(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->andReturn([$owner, $owner]);
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:_users:' . $owner . ':sessions'],
                m::on(static fn (array $arguments): bool => $arguments[0] === self::SESSION_ID && $arguments[3] === '1'),
            )
            ->andReturn(1);
        $connection->shouldReceive('hdel')->never();

        $this->assertTrue($this->handler(tracked: true)->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterWriteReturnsFalseWhenFreshIndexWriteFails(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $container = $this->authenticatedContainer('user-1', withRequest: true);
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturn(['', $owner]);
        $connection->shouldReceive('hsetex')->once()->ordered()->andReturn(0);
        $connection->shouldReceive('hdel')->never();

        $this->assertFalse(
            $this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload')
        );
    }

    public function testClusterWriteReturnsFalseWhenPreservedMetadataIndexWriteFails(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturn([$owner, $owner]);
        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturnFalse();
        $connection->shouldReceive('hdel')->never();

        $this->assertFalse($this->handler(tracked: true)->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterWriteReturnsFalseWhenObsoleteIndexCleanupFails(): void
    {
        $oldOwner = $this->ownerDigest('users', 'old-user');
        $newOwner = $this->ownerDigest('users', 'new-user');
        $container = $this->authenticatedContainer('new-user', withRequest: true);
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturn([$oldOwner, $newOwner]);
        $connection->shouldReceive('hsetex')->once()->ordered()->andReturn(1);
        $connection->shouldReceive('hdel')->once()->ordered()->andReturnFalse();

        $this->assertFalse(
            $this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload')
        );
    }

    public function testClusterWriteRejectsMalformedOwnershipResults(): void
    {
        $connection = $this->expectConnection(cluster: true);
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn(['invalid']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Redis returned an invalid session ownership transition.');

        $this->handler(tracked: true)->write(self::SESSION_ID, 'payload');
    }

    public function testStandaloneTrackedDestroyUsesOneScript(): void
    {
        $connection = $this->expectConnection();
        $captured = [];

        $connection->shouldReceive('getOption')->once()->andReturn('redis:');
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturnUsing(static function (string $script, array $keys, array $arguments) use (&$captured): int {
                $captured = compact('script', 'keys', 'arguments');

                return 1;
            });

        $this->assertTrue($this->handler(tracked: true)->destroy(self::SESSION_ID));
        $this->assertSame(['sessions:' . self::SESSION_ID], $captured['keys']);
        $this->assertSame([self::SESSION_ID, 'redis:sessions:'], $captured['arguments']);
    }

    public function testClusterTrackedDestroyDeletesPayloadBeforeCleaningItsIndex(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturn([1, $owner]);
        $connection->shouldReceive('hdel')
            ->once()
            ->ordered()
            ->with('sessions:_users:' . $owner . ':sessions', self::SESSION_ID)
            ->andReturn(1);

        $this->assertTrue($this->handler(tracked: true)->destroy(self::SESSION_ID));
    }

    public function testClusterTrackedDestroyRejectsFailedIndexCleanup(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturn([1, $owner]);
        $connection->shouldReceive('hdel')->once()->ordered()->andReturnFalse();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Redis failed to clean the user session index.');

        $this->handler(tracked: true)->destroy(self::SESSION_ID);
    }

    public function testUserSessionsUsesOneHealthyReadAndReturnsSortedValues(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection();
        $connection->shouldReceive('hGetAll')
            ->once()
            ->with('sessions:_users:' . $owner . ':sessions')
            ->andReturn([
                self::SESSION_ID => json_encode([
                    'ip_address' => '203.0.113.1',
                    'user_agent' => 'First',
                    'last_activity' => 100,
                ]),
                self::OTHER_SESSION_ID => json_encode([
                    'ip_address' => null,
                    'user_agent' => null,
                    'last_activity' => 200,
                ]),
            ]);
        $connection->shouldReceive('hdel')->never();

        $sessions = $this->handler(tracked: true)->userSessions('users', 'user-1');

        $this->assertSame([self::OTHER_SESSION_ID, self::SESSION_ID], $sessions->pluck('id')->all());
        $this->assertNull($sessions[0]->ipAddress);
        $this->assertSame(200, $sessions[0]->lastActivity->getTimestamp());
        $this->assertSame(800, $sessions[0]->expiresAt->getTimestamp());
        $this->assertSame('203.0.113.1', $sessions[1]->ipAddress);
        $this->assertSame('First', $sessions[1]->userAgent);
    }

    public function testUserSessionsPrunesMalformedMetadataAndInvalidIdentifiersOnce(): void
    {
        $owner = $this->ownerDigest('users', '42');
        $connection = $this->expectConnection();
        $connection->shouldReceive('hGetAll')->once()->andReturn([
            self::SESSION_ID => '{',
            'invalid' => json_encode([
                'ip_address' => null,
                'user_agent' => null,
                'last_activity' => 100,
            ]),
            self::OTHER_SESSION_ID => json_encode([
                'ip_address' => null,
                'user_agent' => null,
                'last_activity' => 100,
            ]),
        ]);
        $connection->shouldReceive('hdel')
            ->once()
            ->with('sessions:_users:' . $owner . ':sessions', self::SESSION_ID, 'invalid')
            ->andReturn(2);

        $sessions = $this->handler(tracked: true)->userSessions('users', 42);

        $this->assertCount(1, $sessions);
        $this->assertSame(self::OTHER_SESSION_ID, $sessions->first()->id);
    }

    public function testUserSessionsRejectsFailedInvalidMetadataPruning(): void
    {
        $connection = $this->expectConnection();
        $connection->shouldReceive('hGetAll')->once()->andReturn([
            self::SESSION_ID => '{',
        ]);
        $connection->shouldReceive('hdel')->once()->with(
            'sessions:_users:' . $this->ownerDigest('users', 'user-1') . ':sessions',
            self::SESSION_ID,
        )->andReturnFalse();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Redis failed to prune invalid user session metadata.');

        $this->handler(tracked: true)->userSessions('users', 'user-1');
    }

    public function testUserSessionsRejectsInvalidIndexResponses(): void
    {
        $connection = $this->expectConnection();
        $connection->shouldReceive('hGetAll')->once()->andReturnFalse();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Redis returned an invalid user session index.');

        $this->handler(tracked: true)->userSessions('users', 'user-1');
    }

    public function testStandaloneScopedDestroyUsesOneOwnershipScript(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection();
        $captured = [];

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturnUsing(static function (string $script, array $keys, array $arguments) use (&$captured): int {
                $captured = compact('script', 'keys', 'arguments');

                return 1;
            });

        $this->assertTrue($this->handler(tracked: true)->destroyUserSession('users', 'user-1', self::SESSION_ID));
        $this->assertSame([
            'sessions:' . self::SESSION_ID,
            'sessions:_users:' . $owner . ':sessions',
        ], $captured['keys']);
        $this->assertSame([$owner, self::SESSION_ID], $captured['arguments']);
    }

    public function testClusterScopedDestroyAlwaysCleansTheRequestedIndexAfterOwnershipCheck(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $otherOwner = $this->ownerDigest('users', 'other-user');
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturn([0, $otherOwner]);
        $connection->shouldReceive('hdel')
            ->once()
            ->ordered()
            ->with('sessions:_users:' . $owner . ':sessions', self::SESSION_ID)
            ->andReturn(1);

        $this->assertFalse($this->handler(tracked: true)->destroyUserSession('users', 'user-1', self::SESSION_ID));
    }

    public function testClusterScopedDestroyRejectsFailedRequestedIndexCleanup(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturn([1, $owner]);
        $connection->shouldReceive('hdel')->once()->ordered()->andReturnFalse();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Redis failed to clean the user session index.');

        $this->handler(tracked: true)->destroyUserSession('users', 'user-1', self::SESSION_ID);
    }

    public function testStandaloneBulkDestroyUsesOneScriptAndDeduplicatesExceptions(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection();
        $captured = [];

        $connection->shouldReceive('getOption')->once()->andReturn('redis:');
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturnUsing(static function (string $script, array $keys, array $arguments) use (&$captured): int {
                $captured = compact('script', 'keys', 'arguments');

                return 3;
            });

        $deleted = $this->handler(tracked: true)->destroyUserSessions(
            'users',
            'user-1',
            [self::SESSION_ID, self::SESSION_ID],
        );

        $this->assertSame(3, $deleted);
        $this->assertSame(['sessions:_users:' . $owner . ':sessions'], $captured['keys']);
        $this->assertSame([$owner, 'redis:sessions:', self::SESSION_ID], $captured['arguments']);
    }

    #[DataProvider('invalidBulkDeletionResultProvider')]
    public function testStandaloneBulkDestroyRejectsInvalidResults(mixed $result): void
    {
        $connection = $this->expectConnection();
        $connection->shouldReceive('getOption')->once()->andReturn('redis:');
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn($result);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Redis returned an invalid bulk session deletion result.');

        $this->handler(tracked: true)->destroyUserSessions('users', 'user-1');
    }

    public static function invalidBulkDeletionResultProvider(): array
    {
        return [
            'non-integer' => [false],
            'negative integer' => [-1],
        ];
    }

    public function testClusterBulkDestroyPreservesExceptionsAndCleansCandidatesOnce(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection(cluster: true);
        $connection->shouldReceive('hKeys')
            ->once()
            ->ordered()
            ->andReturn([self::SESSION_ID, self::OTHER_SESSION_ID, 'invalid']);
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(m::type('string'), ['sessions:' . self::OTHER_SESSION_ID], [$owner])
            ->andReturn([1, $owner]);
        $connection->shouldReceive('hdel')
            ->once()
            ->ordered()
            ->with('sessions:_users:' . $owner . ':sessions', self::OTHER_SESSION_ID, 'invalid')
            ->andReturn(2);

        $deleted = $this->handler(tracked: true)->destroyUserSessions('users', 'user-1', [self::SESSION_ID]);

        $this->assertSame(1, $deleted);
    }

    public function testClusterBulkDestroyRethrowsPayloadFailureAfterSuccessfulCleanup(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $failure = new RuntimeException('payload failed');
        $connection = $this->expectConnection(cluster: true);
        $connection->shouldReceive('hKeys')->once()->andReturn(['invalid', self::SESSION_ID]);
        $connection->shouldReceive('evalWithShaCache')->once()->andThrow($failure);
        $connection->shouldReceive('hdel')
            ->once()
            ->with('sessions:_users:' . $owner . ':sessions', 'invalid')
            ->andReturn(1);

        try {
            $this->handler(tracked: true)->destroyUserSessions('users', 'user-1');
            $this->fail('Expected the payload failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testClusterBulkDestroyRetainsCleanupFailureWhenBothOperationsFail(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $payloadFailure = new UnexpectedValueException('payload failed');
        $connection = $this->expectConnection(cluster: true);
        $connection->shouldReceive('hKeys')->once()->andReturn(['invalid', self::SESSION_ID]);
        $connection->shouldReceive('evalWithShaCache')->once()->andThrow($payloadFailure);
        $connection->shouldReceive('hdel')
            ->once()
            ->with('sessions:_users:' . $owner . ':sessions', 'invalid')
            ->andReturnFalse();

        try {
            $this->handler(tracked: true)->destroyUserSessions('users', 'user-1');
            $this->fail('Expected the combined failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(UnexpectedValueException::class, $exception->getMessage());
            $this->assertStringContainsString('payload failed', $exception->getMessage());
            $this->assertInstanceOf(UnexpectedValueException::class, $exception->getPrevious());
            $this->assertSame('Redis failed to clean the user session index.', $exception->getPrevious()->getMessage());
        }
    }

    #[DataProvider('invalidSessionIdProvider')]
    public function testManagedDeletesRejectInvalidSessionIdentifiers(string $sessionId): void
    {
        $this->redis->shouldReceive('connection')->never();

        try {
            $this->handler(tracked: true)->destroyUserSession('users', 'user-1', $sessionId);
            $this->fail('Expected the scoped delete to reject the identifier.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);

        $this->handler(tracked: true)->destroyUserSessions('users', 'user-1', [$sessionId]);
    }

    public static function invalidSessionIdProvider(): array
    {
        return [
            'empty' => [''],
            'short' => [str_repeat('a', 39)],
            'long' => [str_repeat('a', 41)],
            'punctuation' => [str_repeat('a', 39) . ':'],
        ];
    }

    public function testManagementRejectsAnEmptyAuthenticationProviderWithoutUsingRedis(): void
    {
        $this->redis->shouldReceive('connection')->never();

        foreach ([
            fn () => $this->handler(tracked: true)->userSessions('', 'user-1'),
            fn () => $this->handler(tracked: true)->destroyUserSession('', 'user-1', self::SESSION_ID),
            fn () => $this->handler(tracked: true)->destroyUserSessions('', 'user-1'),
        ] as $operation) {
            try {
                $operation();

                $this->fail('Expected an empty authentication provider to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'The authentication provider may not be empty.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testRawConnectionExceptionsPropagateThroughTheHandler(): void
    {
        $connection = $this->expectConnection();
        $failure = new RuntimeException('connection failed');
        $connection->shouldReceive('get')->once()->andThrow($failure);

        try {
            $this->handler()->read(self::SESSION_ID);
            $this->fail('Expected the Redis exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    private function handler(bool $tracked = false, ?Container $container = null): RedisSessionHandler
    {
        return new RedisSessionHandler(
            $this->redis,
            'session',
            'sessions:',
            10,
            $tracked,
            $container,
        );
    }

    private function expectConnection(bool $cluster = false): RedisConnection
    {
        $proxy = m::mock(RedisProxy::class);
        $connection = m::mock(RedisConnection::class);

        $this->redis->shouldReceive('connection')->once()->with('session')->andReturn($proxy);
        $proxy->shouldReceive('isCluster')->once()->andReturn($cluster);
        $proxy->shouldReceive('withConnection')
            ->once()
            ->with(m::type('callable'), false)
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($connection));
        $connection->shouldReceive('withoutSerializationOrCompression')
            ->once()
            ->with(m::type('callable'))
            ->andReturnUsing(static fn (callable $callback): mixed => $callback());

        return $connection;
    }

    private function authenticatedContainer(
        string $userId,
        bool $withRequest,
        ?string $authProvider = 'users',
    ): Container {
        $guard = m::mock(Guard::class);
        $guard->shouldReceive('id')->once()->andReturn($userId);

        $guardConfig = ['driver' => 'custom'];

        if ($authProvider !== null) {
            $guardConfig['provider'] = $authProvider;
        }

        $container = new Container;
        $container->instance('config', new Repository([
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => ['web' => $guardConfig],
            ],
        ]));
        $auth = new AuthManager($container);
        $auth->extend('custom', static fn () => $guard);
        $container->instance('auth', $auth);

        if ($withRequest) {
            $request = Request::create('/', 'GET', server: [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_USER_AGENT' => 'Browser/1.0',
            ]);
            RequestContext::set($request);
            $container->instance('request', $request);
        }

        return $container;
    }

    private function ownerDigest(string $authProvider, string $userId): string
    {
        return hash('xxh128', strlen($authProvider) . ':' . $authProvider . ':' . $userId);
    }
}
