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

use function Hypervel\Coroutine\parallel;

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
        $this->assertSame(
            (string) (CarbonImmutable::now()->getTimestamp() + 600),
            $captured['arguments'][0],
        );
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

    public function testClusterResolvedWriteUpdatesNewIndexBeforePayloadAndOldIndexCleanup(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $oldOwner = $this->ownerDigest('users', 'old-user');
        $newOwner = $this->ownerDigest('users', 'new-user');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $container = $this->authenticatedContainer('new-user', withRequest: true);
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('hsetex')
            ->once()
            ->ordered()
            ->with(
                'sessions:_users:' . $newOwner . ':sessions',
                m::on(static fn (array $fields): bool => array_key_exists(self::SESSION_ID, $fields)),
                ['EXAT' => $expiresAt],
            )
            ->andReturn(1);
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:' . self::SESSION_ID],
                [(string) $expiresAt, 'resolved', $newOwner, $newOwner, 'payload'],
            )
            ->andReturn([1, $oldOwner, $newOwner]);
        $connection->shouldReceive('hdel')
            ->once()
            ->ordered()
            ->with('sessions:_users:' . $oldOwner . ':sessions', self::SESSION_ID)
            ->andReturn(1);

        $this->assertTrue($this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterWritePreservesSameOwnerMetadataWithOneIndexScript(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $owner = $this->ownerDigest('users', 'user-1');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $handler = $this->handler(tracked: true);
        $readConnection = $this->expectConnection(cluster: true);
        $readConnection->shouldReceive('get')->once()->andReturn("\0HVS1{$owner}old");

        $this->assertSame('old', $handler->read(self::SESSION_ID));

        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:_users:' . $owner . ':sessions'],
                m::on(static fn (array $arguments): bool => $arguments[0] === self::SESSION_ID
                    && $arguments[1] === (string) $expiresAt
                    && count($arguments) === 4),
            )
            ->andReturn(1);
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:' . self::SESSION_ID],
                [(string) $expiresAt, 'unresolved', '', $owner, 'payload'],
            )
            ->andReturn([1, $owner, $owner]);
        $connection->shouldReceive('hdel')->never();
        $connection->shouldReceive('get')->never();

        $this->assertTrue($handler->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterWriteReturnsFalseWhenFreshIndexWriteFails(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $owner = $this->ownerDigest('users', 'user-1');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $container = $this->authenticatedContainer('user-1', withRequest: true);
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('hsetex')
            ->once()
            ->with(
                'sessions:_users:' . $owner . ':sessions',
                m::type('array'),
                ['EXAT' => $expiresAt],
            )
            ->andReturn(0);
        $connection->shouldReceive('evalWithShaCache')->never();
        $connection->shouldReceive('hdel')->never();

        $this->assertFalse(
            $this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload')
        );
    }

    public function testClusterUnresolvedWriteDoesNotRefreshPayloadWhenIndexRefreshFails(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $handler = $this->handler(tracked: true);
        $readConnection = $this->expectConnection(cluster: true);
        $readConnection->shouldReceive('get')->once()->andReturn("\0HVS1{$owner}old");

        $this->assertSame('old', $handler->read(self::SESSION_ID));

        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->with(m::type('string'), ['sessions:_users:' . $owner . ':sessions'], m::type('array'))
            ->andReturnFalse();
        $connection->shouldReceive('hdel')->never();

        $this->assertFalse($handler->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterWriteReturnsFalseWhenObsoleteIndexCleanupFails(): void
    {
        $oldOwner = $this->ownerDigest('users', 'old-user');
        $newOwner = $this->ownerDigest('users', 'new-user');
        $container = $this->authenticatedContainer('new-user', withRequest: true);
        $handler = new ObservableRedisSessionHandler(
            $this->redis,
            'session',
            'sessions:',
            10,
            true,
            $container,
        );
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('hsetex')->once()->ordered()->andReturn(1);
        $connection->shouldReceive('evalWithShaCache')->once()->ordered()->andReturn([1, $oldOwner, $newOwner]);
        $connection->shouldReceive('hdel')->once()->ordered()->andReturnFalse();

        $this->assertFalse($handler->write(self::SESSION_ID, 'payload'));
        $this->assertSame($newOwner, $handler->observedOwnerForTesting(self::SESSION_ID));
    }

    public function testClusterUnresolvedWriteProbesAnUnobservedOwnerBeforeUpdatingItsIndex(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $owner = $this->ownerDigest('users', 'user-1');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('get')
            ->once()
            ->ordered()
            ->with('sessions:' . self::SESSION_ID)
            ->andReturn("\0HVS1{$owner}old");
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(m::type('string'), ['sessions:_users:' . $owner . ':sessions'], m::type('array'))
            ->andReturn(1);
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:' . self::SESSION_ID],
                [(string) $expiresAt, 'unresolved', '', $owner, 'payload'],
            )
            ->andReturn([1, $owner, $owner]);
        $connection->shouldReceive('hdel')->never();

        $this->assertTrue($this->handler(tracked: true)->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterUnresolvedWriteRejectsAnOwnerAppearingAfterAnOwnerlessReadAndRetriesWithoutAProbe(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $owner = $this->ownerDigest('users', 'user-1');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $handler = $this->handler(tracked: true);
        $readConnection = $this->expectConnection(cluster: true);
        $readConnection->shouldReceive('get')->once()->andReturnFalse();

        $this->assertSame('', $handler->read(self::SESSION_ID));

        $racedConnection = $this->expectConnection(cluster: true);
        $racedConnection->shouldReceive('get')->never();
        $racedConnection->shouldReceive('hsetex')->never();
        $racedConnection->shouldReceive('evalWithShaCache')
            ->once()
            ->with(
                m::type('string'),
                ['sessions:' . self::SESSION_ID],
                [(string) $expiresAt, 'unresolved', '', '', 'first'],
            )
            ->andReturn([0, $owner, '']);
        $racedConnection->shouldReceive('hdel')->never();

        $this->assertFalse($handler->write(self::SESSION_ID, 'first'));

        $retryConnection = $this->expectConnection(cluster: true);
        $retryConnection->shouldReceive('get')->never();
        $retryConnection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(m::type('string'), ['sessions:_users:' . $owner . ':sessions'], m::type('array'))
            ->andReturn(1);
        $retryConnection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:' . self::SESSION_ID],
                [(string) $expiresAt, 'unresolved', '', $owner, 'second'],
            )
            ->andReturn([1, $owner, $owner]);
        $retryConnection->shouldReceive('hdel')->never();

        $this->assertTrue($handler->write(self::SESSION_ID, 'second'));
    }

    public function testClusterUnresolvedWriteRejectsAReassignmentAfterPreIndexingTheObservedOwnerAndRetriesWithTheActualOwner(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $observedOwner = $this->ownerDigest('users', 'observed-user');
        $actualOwner = $this->ownerDigest('users', 'actual-user');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $handler = new ObservableRedisSessionHandler($this->redis, 'session', 'sessions:', 10, true);
        $readConnection = $this->expectConnection(cluster: true);
        $readConnection->shouldReceive('get')->once()->andReturn("\0HVS1{$observedOwner}old");

        $this->assertSame('old', $handler->read(self::SESSION_ID));

        $racedConnection = $this->expectConnection(cluster: true);
        $racedConnection->shouldReceive('get')->never();
        $racedConnection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(m::type('string'), ['sessions:_users:' . $observedOwner . ':sessions'], m::type('array'))
            ->andReturn(1);
        $racedConnection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:' . self::SESSION_ID],
                [(string) $expiresAt, 'unresolved', '', $observedOwner, 'first'],
            )
            ->andReturn([0, $actualOwner, '']);
        $racedConnection->shouldReceive('hdel')->never();

        $this->assertFalse($handler->write(self::SESSION_ID, 'first'));
        $this->assertSame($actualOwner, $handler->observedOwnerForTesting(self::SESSION_ID));

        $retryConnection = $this->expectConnection(cluster: true);
        $retryConnection->shouldReceive('get')->never();
        $retryConnection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(m::type('string'), ['sessions:_users:' . $actualOwner . ':sessions'], m::type('array'))
            ->andReturn(1);
        $retryConnection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:' . self::SESSION_ID],
                [(string) $expiresAt, 'unresolved', '', $actualOwner, 'second'],
            )
            ->andReturn([1, $actualOwner, $actualOwner]);
        $retryConnection->shouldReceive('hdel')->never();

        $this->assertTrue($handler->write(self::SESSION_ID, 'second'));
        $this->assertSame($actualOwner, $handler->observedOwnerForTesting(self::SESSION_ID));
    }

    public function testClusterUnownedWriteChangesThePayloadBeforeCleaningItsOldIndex(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $oldOwner = $this->ownerDigest('users', 'old-user');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        UserSessionIdentity::suppress(self::SESSION_ID);
        $connection = $this->expectConnection(cluster: true);

        $connection->shouldReceive('get')->never();
        $connection->shouldReceive('hsetex')->never();
        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->ordered()
            ->with(
                m::type('string'),
                ['sessions:' . self::SESSION_ID],
                [(string) $expiresAt, 'unowned', '', '', 'payload'],
            )
            ->andReturn([1, $oldOwner, '']);
        $connection->shouldReceive('hdel')
            ->once()
            ->ordered()
            ->with('sessions:_users:' . $oldOwner . ':sessions', self::SESSION_ID)
            ->andReturn(1);

        $this->assertTrue($this->handler(tracked: true)->write(self::SESSION_ID, 'payload'));
    }

    #[DataProvider('invalidOwnershipTransitionProvider')]
    public function testClusterWriteRejectsMalformedOwnershipResults(mixed $result): void
    {
        $connection = $this->expectConnection(cluster: true);
        $connection->shouldReceive('get')->once()->andReturnFalse();
        $connection->shouldReceive('evalWithShaCache')->once()->andReturn($result);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Redis returned an invalid session ownership transition.');

        $this->handler(tracked: true)->write(self::SESSION_ID, 'payload');
    }

    public static function invalidOwnershipTransitionProvider(): array
    {
        return [
            'wrong tuple size' => [[1, '']],
            'invalid status' => [[2, '', '']],
            'non-string old owner' => [[1, 1, '']],
            'invalid old owner' => [[1, 'invalid', '']],
            'invalid resulting owner' => [[1, '', 'invalid']],
            'failure with a resulting owner' => [[0, '', str_repeat('a', 32)]],
        ];
    }

    public function testClusterOwnerObservationsAreIsolatedBetweenCoroutines(): void
    {
        $firstOwner = $this->ownerDigest('users', 'first');
        $secondOwner = $this->ownerDigest('users', 'second');
        $handler = new ObservableRedisSessionHandler($this->redis, 'session', 'sessions:', 10, true);

        $owners = parallel([
            'first' => function () use ($handler, $firstOwner): ?string {
                $handler->rememberOwnerForTesting(self::SESSION_ID, $firstOwner);
                usleep(1000);

                return $handler->observedOwnerForTesting(self::SESSION_ID);
            },
            'second' => function () use ($handler, $secondOwner): ?string {
                $handler->rememberOwnerForTesting(self::SESSION_ID, $secondOwner);
                usleep(1000);

                return $handler->observedOwnerForTesting(self::SESSION_ID);
            },
        ]);

        $this->assertSame($firstOwner, $owners['first']);
        $this->assertSame($secondOwner, $owners['second']);
        $this->assertNull($handler->observedOwnerForTesting(self::SESSION_ID));
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

    public function testUserSessionsUsesOneReadAndReturnsSortedValues(): void
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
        $connection->shouldReceive('getRange')->never();

        $sessions = $this->handler(tracked: true)->userSessions('users', 'user-1');

        $this->assertSame([self::OTHER_SESSION_ID, self::SESSION_ID], $sessions->pluck('id')->all());
        $this->assertNull($sessions[0]->ipAddress);
        $this->assertSame(200, $sessions[0]->lastActivity->getTimestamp());
        $this->assertSame(800, $sessions[0]->expiresAt->getTimestamp());
        $this->assertSame('203.0.113.1', $sessions[1]->ipAddress);
        $this->assertSame('First', $sessions[1]->userAgent);
    }

    public function testUserSessionsIgnoresMalformedMetadataAndInvalidIdentifiers(): void
    {
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
        $connection->shouldReceive('hdel')->never();
        $connection->shouldReceive('getRange')->never();

        $sessions = $this->handler(tracked: true)->userSessions('users', 42);

        $this->assertCount(1, $sessions);
        $this->assertSame(self::OTHER_SESSION_ID, $sessions->first()->id);
    }

    public function testClusterUserSessionsReturnsOnlyAuthoritativeOwnerMatches(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $otherOwner = $this->ownerDigest('users', 'user-2');
        $missingSessionId = str_repeat('c', 40);
        $rawSessionId = str_repeat('d', 40);
        $corruptSessionId = str_repeat('e', 40);
        $unknownVersionSessionId = str_repeat('f', 40);
        $unreadableSessionId = str_repeat('g', 40);
        $metadata = json_encode([
            'ip_address' => null,
            'user_agent' => null,
            'last_activity' => 100,
        ]);
        $connection = $this->expectConnection(cluster: true);
        $connection->shouldReceive('hGetAll')->once()->andReturn([
            self::SESSION_ID => $metadata,
            self::OTHER_SESSION_ID => $metadata,
            $missingSessionId => $metadata,
            $rawSessionId => $metadata,
            $corruptSessionId => $metadata,
            $unknownVersionSessionId => $metadata,
            $unreadableSessionId => $metadata,
        ]);
        $connection->shouldReceive('getRange')
            ->once()
            ->with('sessions:' . self::SESSION_ID, 0, 36)
            ->andReturn("\0HVS1" . $owner);
        $connection->shouldReceive('getRange')
            ->once()
            ->with('sessions:' . self::OTHER_SESSION_ID, 0, 36)
            ->andReturn("\0HVS1" . $otherOwner);
        $connection->shouldReceive('getRange')
            ->once()
            ->with('sessions:' . $missingSessionId, 0, 36)
            ->andReturn('');
        $connection->shouldReceive('getRange')
            ->once()
            ->with('sessions:' . $rawSessionId, 0, 36)
            ->andReturn('raw payload');
        $connection->shouldReceive('getRange')
            ->once()
            ->with('sessions:' . $corruptSessionId, 0, 36)
            ->andReturn("\0HVS1" . str_repeat('A', 32));
        $connection->shouldReceive('getRange')
            ->once()
            ->with('sessions:' . $unknownVersionSessionId, 0, 36)
            ->andReturn("\0HVS2" . $owner);
        $connection->shouldReceive('getRange')
            ->once()
            ->with('sessions:' . $unreadableSessionId, 0, 36)
            ->andReturnFalse();
        $connection->shouldReceive('hdel')->never();

        $sessions = $this->handler(tracked: true)->userSessions('users', 'user-1');

        $this->assertSame([self::SESSION_ID], $sessions->pluck('id')->all());
    }

    public function testClusterUserSessionsDoesNotReadPayloadsWhenIndexHasNoValidCandidates(): void
    {
        $connection = $this->expectConnection(cluster: true);
        $connection->shouldReceive('hGetAll')->once()->andReturn([
            self::SESSION_ID => '{',
            'invalid' => json_encode([
                'ip_address' => null,
                'user_agent' => null,
                'last_activity' => 100,
            ]),
        ]);
        $connection->shouldReceive('getRange')->never();
        $connection->shouldReceive('hdel')->never();

        $this->assertEmpty($this->handler(tracked: true)->userSessions('users', 'user-1'));
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

    public function testClusterBulkDestroyClearsObservedOwners(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $handler = new ObservableRedisSessionHandler($this->redis, 'session', 'sessions:', 10, true);
        $handler->rememberOwnerForTesting(self::SESSION_ID, $owner);
        $connection = $this->expectConnection(cluster: true);
        $connection->shouldReceive('hKeys')->once()->andReturn([]);

        $this->assertSame(0, $handler->destroyUserSessions('users', 'user-1'));
        $this->assertNull($handler->observedOwnerForTesting(self::SESSION_ID));
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
        return substr(
            hash('sha256', strlen($authProvider) . ':' . $authProvider . ':' . $userId),
            0,
            32,
        );
    }
}

class ObservableRedisSessionHandler extends RedisSessionHandler
{
    public function rememberOwnerForTesting(string $sessionId, string $owner): void
    {
        $this->rememberOwner($sessionId, $owner);
    }

    public function observedOwnerForTesting(string $sessionId): ?string
    {
        return $this->observedOwner($sessionId);
    }
}
