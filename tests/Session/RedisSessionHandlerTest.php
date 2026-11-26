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
use Hypervel\Redis\RedisProxy;
use Hypervel\Session\RedisSessionHandler;
use Hypervel\Session\UserSessionIdentity;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\Redis\Fixtures\PhpRedisClusterConnectionStub;
use Hypervel\Tests\Redis\Fixtures\PhpRedisConnectionStub;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Redis;
use RedisCluster;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use UnexpectedValueException;

use function Hypervel\Coroutine\parallel;

class RedisSessionHandlerTest extends TestCase
{
    use VerifiesDoubles;

    private const string SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string OTHER_SESSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private RedisFactory $redis;

    /**
     * Set up the Redis factory.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = Double::for(RedisFactory::class)->strict();
    }

    public function testLifecycleAndCapabilityMethodsDoNotUseRedis(): void
    {
        $this->redis->expects('connection')->never();

        $plain = $this->handler();
        $tracked = $this->handler(tracked: true);

        $this->assertTrue($plain->open('', 'session'));
        $this->assertTrue($plain->close());
        $this->assertSame(0, $plain->gc(120));
        $this->assertFalse($plain->supportsUserSessionManagement());
        $this->assertTrue($tracked->supportsUserSessionManagement());
    }

    public function testItCreatesSessionIds(): void
    {
        $sessionId = $this->handler()->create_sid();

        $this->assertIsString($sessionId);
        $this->assertNotEmpty($sessionId);
    }

    #[DataProvider('sessionExistenceProvider')]
    public function testValidateIdChecksRedis(int $exists, bool $expected): void
    {
        $connection = $this->expectConnection();
        $connection->expects('exists')->with('sessions:' . self::SESSION_ID)->returns($exists);

        $this->assertSame($expected, $this->handler()->validateId(self::SESSION_ID));
    }

    /**
     * Provide Redis session existence results.
     */
    public static function sessionExistenceProvider(): array
    {
        return [[0, false], [1, true]];
    }

    #[DataProvider('storedPayloadProvider')]
    public function testReadUnderstandsRawAndVersionedPayloads(string $stored, string $expected): void
    {
        $connection = $this->expectConnection();
        $connection->expects('get')
            ->with('sessions:' . self::SESSION_ID)
            ->returns($stored);

        $this->assertSame($expected, $this->handler()->read(self::SESSION_ID));
    }

    /**
     * Provide stored session payloads.
     */
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
        $connection->expects('get')->returns(false);

        $this->assertSame('', $this->handler()->read(self::SESSION_ID));
    }

    public function testInvalidReadResultFailsExplicitly(): void
    {
        $connection = $this->expectConnection();
        $connection->expects('get')->returns(123);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('Redis returned an invalid session payload.');

        $this->handler()->read(self::SESSION_ID);
    }

    public function testTrackingDisabledWriteUsesOneRawSetex(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('has')->never();
        $container->expects('make')->never();
        $connection = $this->expectConnection();
        $connection->expects('setex')
            ->with('sessions:' . self::SESSION_ID, 600, 'payload')
            ->returns(true);

        $this->assertTrue($this->handler(container: $container->instance())->write(self::SESSION_ID, 'payload'));
    }

    public function testTrackingDisabledWriteReturnsFalseWithoutPublishingSuccess(): void
    {
        $connection = $this->expectConnection();
        $connection->expects('setex')->returns(false);

        $this->assertFalse($this->handler()->write(self::SESSION_ID, 'payload'));
    }

    public function testTrackingDisabledDestroyUsesOneRawDelete(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('has')->never();
        $container->expects('make')->never();
        $connection = $this->expectConnection();
        $connection->expects('del')
            ->with('sessions:' . self::SESSION_ID)
            ->returns(1);

        $this->assertTrue($this->handler(container: $container->instance())->destroy(self::SESSION_ID));
    }

    public function testStandaloneTrackedWriteUsesOneScriptWithPhysicalPrefixAndFreshMetadata(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $container = $this->authenticatedContainer('user-1', withRequest: true);
        $connection = $this->expectConnection();
        $captured = [];

        $connection->expects('getOption')->with(Redis::OPT_PREFIX)->returns('redis:');
        $connection->expects('evalSha')
            ->resolves(static function (string $sha, array $parameters, int $keyCount) use (&$captured): int {
                $captured = [
                    'keys' => array_slice($parameters, 0, $keyCount),
                    'arguments' => array_slice($parameters, $keyCount),
                ];

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

        $connection->expects('getOption')->with(Redis::OPT_PREFIX)->returns('');
        $connection->expects('evalSha')
            ->resolves(static function (string $sha, array $parameters, int $keyCount) use (&$captured): int {
                $captured = [
                    'keys' => array_slice($parameters, 0, $keyCount),
                    'arguments' => array_slice($parameters, $keyCount),
                ];

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

        $connection->expects('getOption')->with(Redis::OPT_PREFIX)->returns('');
        $connection->expects('evalSha')
            ->resolves(static function (string $sha, array $parameters, int $keyCount) use (&$captured): false {
                $captured = [
                    'keys' => array_slice($parameters, 0, $keyCount),
                    'arguments' => array_slice($parameters, $keyCount),
                ];

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

        $connection->expects('getOption')->with(Redis::OPT_PREFIX)->returns('');
        $connection->expects('evalSha')
            ->resolves(static function (string $sha, array $parameters, int $keyCount) use (&$captured): int {
                $captured = [
                    'keys' => array_slice($parameters, 0, $keyCount),
                    'arguments' => array_slice($parameters, $keyCount),
                ];

                return 1;
            });

        $this->assertTrue($this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload'));
        $this->assertSame('unowned', $captured['arguments'][3]);
        $this->assertSame('', $captured['arguments'][4]);
    }

    public function testProviderQualifiedOwnerDigestIsolatesEqualUserIdentifiers(): void
    {
        $owners = [];

        $connections = $this->expectConnections(2);
        foreach (['users', 'admins'] as $index => $authProvider) {
            $connection = $connections[$index];
            $container = $this->authenticatedContainer('1', withRequest: false, authProvider: $authProvider);

            $connection->expects('getOption')->with(Redis::OPT_PREFIX)->returns('');
            $connection->expects('evalSha')
                ->resolves(static function (
                    string $sha,
                    array $parameters,
                    int $keyCount,
                ) use (&$owners, $authProvider): int {
                    $owners[$authProvider] = $parameters[$keyCount + 4];

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

        $connection->expects('hsetex')
            ->ordered()
            ->with(
                'sessions:_users:' . $newOwner . ':sessions',
                Argument::satisfies(static fn (array $fields): bool => array_key_exists(self::SESSION_ID, $fields)),
                ['EXAT' => $expiresAt],
            )
            ->returns(1);
        $connection->expects('evalSha')
            ->ordered()
            ->with(
                Argument::type('string'),
                ['sessions:' . self::SESSION_ID, (string) $expiresAt, 'resolved', $newOwner, $newOwner, 'payload'],
                1,
            )
            ->returns([1, $oldOwner, $newOwner]);
        $connection->expects('hdel')
            ->ordered()
            ->with('sessions:_users:' . $oldOwner . ':sessions', self::SESSION_ID)
            ->returns(1);

        $this->assertTrue($this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterWritePreservesSameOwnerMetadataWithOneIndexScript(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $owner = $this->ownerDigest('users', 'user-1');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $handler = $this->handler(tracked: true);
        [$readConnection, $connection] = $this->expectConnections(2, cluster: true);
        $readConnection->expects('get')->returns("\0HVS1{$owner}old");

        $this->assertSame('old', $handler->read(self::SESSION_ID));

        $connection->expects('evalSha')
            ->ordered()
            ->with(
                Argument::type('string'),
                Argument::satisfies(static fn (array $parameters): bool => $parameters[0] === 'sessions:_users:' . $owner . ':sessions'
                    && $parameters[1] === self::SESSION_ID
                    && $parameters[2] === (string) $expiresAt
                    && count($parameters) === 5),
                1,
            )
            ->returns(1);
        $connection->expects('evalSha')
            ->ordered()
            ->with(
                Argument::type('string'),
                ['sessions:' . self::SESSION_ID, (string) $expiresAt, 'unresolved', '', $owner, 'payload'],
                1,
            )
            ->returns([1, $owner, $owner]);
        $connection->expects('hdel')->never();
        $connection->expects('get')->never();

        $this->assertTrue($handler->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterWriteReturnsFalseWhenFreshIndexWriteFails(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $owner = $this->ownerDigest('users', 'user-1');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $container = $this->authenticatedContainer('user-1', withRequest: true);
        $connection = $this->expectConnection(cluster: true);

        $connection->expects('hsetex')
            ->with(
                'sessions:_users:' . $owner . ':sessions',
                Argument::type('array'),
                ['EXAT' => $expiresAt],
            )
            ->returns(0);
        $connection->expects('evalSha')->never();
        $connection->expects('hdel')->never();

        $this->assertFalse(
            $this->handler(tracked: true, container: $container)->write(self::SESSION_ID, 'payload')
        );
    }

    public function testClusterUnresolvedWriteDoesNotRefreshPayloadWhenIndexRefreshFails(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $handler = $this->handler(tracked: true);
        [$readConnection, $connection] = $this->expectConnections(2, cluster: true);
        $readConnection->expects('get')->returns("\0HVS1{$owner}old");

        $this->assertSame('old', $handler->read(self::SESSION_ID));

        $connection->expects('evalSha')
            ->with(Argument::type('string'), Argument::satisfies(static fn (array $parameters): bool => $parameters[0] === 'sessions:_users:' . $owner . ':sessions'), 1)
            ->returns(false);
        $connection->expects('hdel')->never();

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

        $connection->expects('hsetex')->ordered()->returns(1);
        $connection->expects('evalSha')->ordered()->returns([1, $oldOwner, $newOwner]);
        $connection->expects('hdel')->ordered()->returns(false);

        $this->assertFalse($handler->write(self::SESSION_ID, 'payload'));
        $this->assertSame($newOwner, $handler->observedOwnerForTesting(self::SESSION_ID));
    }

    public function testClusterUnresolvedWriteProbesAnUnobservedOwnerBeforeUpdatingItsIndex(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $owner = $this->ownerDigest('users', 'user-1');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $connection = $this->expectConnection(cluster: true);

        $connection->expects('get')
            ->ordered()
            ->with('sessions:' . self::SESSION_ID)
            ->returns("\0HVS1{$owner}old");
        $connection->expects('evalSha')
            ->ordered()
            ->with(Argument::type('string'), Argument::satisfies(static fn (array $parameters): bool => $parameters[0] === 'sessions:_users:' . $owner . ':sessions'), 1)
            ->returns(1);
        $connection->expects('evalSha')
            ->ordered()
            ->with(
                Argument::type('string'),
                ['sessions:' . self::SESSION_ID, (string) $expiresAt, 'unresolved', '', $owner, 'payload'],
                1,
            )
            ->returns([1, $owner, $owner]);
        $connection->expects('hdel')->never();

        $this->assertTrue($this->handler(tracked: true)->write(self::SESSION_ID, 'payload'));
    }

    public function testClusterUnresolvedWriteRejectsAnOwnerAppearingAfterAnOwnerlessReadAndRetriesWithoutAProbe(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $owner = $this->ownerDigest('users', 'user-1');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $handler = $this->handler(tracked: true);
        [$readConnection, $racedConnection, $retryConnection] = $this->expectConnections(3, cluster: true);
        $readConnection->expects('get')->returns(false);

        $this->assertSame('', $handler->read(self::SESSION_ID));

        $racedConnection->expects('get')->never();
        $racedConnection->expects('hsetex')->never();
        $racedConnection->expects('evalSha')
            ->with(
                Argument::type('string'),
                ['sessions:' . self::SESSION_ID, (string) $expiresAt, 'unresolved', '', '', 'first'],
                1,
            )
            ->returns([0, $owner, '']);
        $racedConnection->expects('hdel')->never();

        $this->assertFalse($handler->write(self::SESSION_ID, 'first'));

        $retryConnection->expects('get')->never();
        $retryConnection->expects('evalSha')
            ->ordered()
            ->with(Argument::type('string'), Argument::satisfies(static fn (array $parameters): bool => $parameters[0] === 'sessions:_users:' . $owner . ':sessions'), 1)
            ->returns(1);
        $retryConnection->expects('evalSha')
            ->ordered()
            ->with(
                Argument::type('string'),
                ['sessions:' . self::SESSION_ID, (string) $expiresAt, 'unresolved', '', $owner, 'second'],
                1,
            )
            ->returns([1, $owner, $owner]);
        $retryConnection->expects('hdel')->never();

        $this->assertTrue($handler->write(self::SESSION_ID, 'second'));
    }

    public function testClusterUnresolvedWriteRejectsAReassignmentAfterPreIndexingTheObservedOwnerAndRetriesWithTheActualOwner(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 12:00:00');
        $observedOwner = $this->ownerDigest('users', 'observed-user');
        $actualOwner = $this->ownerDigest('users', 'actual-user');
        $expiresAt = CarbonImmutable::now()->getTimestamp() + 600;
        $handler = new ObservableRedisSessionHandler($this->redis, 'session', 'sessions:', 10, true);
        [$readConnection, $racedConnection, $retryConnection] = $this->expectConnections(3, cluster: true);
        $readConnection->expects('get')->returns("\0HVS1{$observedOwner}old");

        $this->assertSame('old', $handler->read(self::SESSION_ID));

        $racedConnection->expects('get')->never();
        $racedConnection->expects('evalSha')
            ->ordered()
            ->with(Argument::type('string'), Argument::satisfies(static fn (array $parameters): bool => $parameters[0] === 'sessions:_users:' . $observedOwner . ':sessions'), 1)
            ->returns(1);
        $racedConnection->expects('evalSha')
            ->ordered()
            ->with(
                Argument::type('string'),
                ['sessions:' . self::SESSION_ID, (string) $expiresAt, 'unresolved', '', $observedOwner, 'first'],
                1,
            )
            ->returns([0, $actualOwner, '']);
        $racedConnection->expects('hdel')->never();

        $this->assertFalse($handler->write(self::SESSION_ID, 'first'));
        $this->assertSame($actualOwner, $handler->observedOwnerForTesting(self::SESSION_ID));

        $retryConnection->expects('get')->never();
        $retryConnection->expects('evalSha')
            ->ordered()
            ->with(Argument::type('string'), Argument::satisfies(static fn (array $parameters): bool => $parameters[0] === 'sessions:_users:' . $actualOwner . ':sessions'), 1)
            ->returns(1);
        $retryConnection->expects('evalSha')
            ->ordered()
            ->with(
                Argument::type('string'),
                ['sessions:' . self::SESSION_ID, (string) $expiresAt, 'unresolved', '', $actualOwner, 'second'],
                1,
            )
            ->returns([1, $actualOwner, $actualOwner]);
        $retryConnection->expects('hdel')->never();

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

        $connection->expects('get')->never();
        $connection->expects('hsetex')->never();
        $connection->expects('evalSha')
            ->ordered()
            ->with(
                Argument::type('string'),
                ['sessions:' . self::SESSION_ID, (string) $expiresAt, 'unowned', '', '', 'payload'],
                1,
            )
            ->returns([1, $oldOwner, '']);
        $connection->expects('hdel')
            ->ordered()
            ->with('sessions:_users:' . $oldOwner . ':sessions', self::SESSION_ID)
            ->returns(1);

        $this->assertTrue($this->handler(tracked: true)->write(self::SESSION_ID, 'payload'));
    }

    #[DataProvider('invalidOwnershipTransitionProvider')]
    public function testClusterWriteRejectsMalformedOwnershipResults(mixed $result): void
    {
        $connection = $this->expectConnection(cluster: true);
        $connection->expects('get')->returns(false);
        $connection->expects('evalSha')->returns($result);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('Redis returned an invalid session ownership transition.');

        $this->handler(tracked: true)->write(self::SESSION_ID, 'payload');
    }

    /**
     * Provide malformed ownership transitions.
     */
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

        $connection->expects('getOption')->with(Redis::OPT_PREFIX)->returns('redis:');
        $connection->expects('evalSha')
            ->resolves(static function (string $sha, array $parameters, int $keyCount) use (&$captured): int {
                $captured = [
                    'keys' => array_slice($parameters, 0, $keyCount),
                    'arguments' => array_slice($parameters, $keyCount),
                ];

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

        $connection->expects('evalSha')->ordered()->returns([1, $owner]);
        $connection->expects('hdel')
            ->ordered()
            ->with('sessions:_users:' . $owner . ':sessions', self::SESSION_ID)
            ->returns(1);

        $this->assertTrue($this->handler(tracked: true)->destroy(self::SESSION_ID));
    }

    public function testClusterTrackedDestroyRejectsFailedIndexCleanup(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection(cluster: true);

        $connection->expects('evalSha')->ordered()->returns([1, $owner]);
        $connection->expects('hdel')->ordered()->returns(false);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('Redis failed to clean the user session index.');

        $this->handler(tracked: true)->destroy(self::SESSION_ID);
    }

    public function testUserSessionsUsesOneReadAndReturnsSortedValues(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection();
        $connection->expects('hGetAll')
            ->with('sessions:_users:' . $owner . ':sessions')
            ->returns([
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
        $connection->expects('hdel')->never();
        $connection->expects('getRange')->never();

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
        $connection->expects('hGetAll')->returns([
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
        $connection->expects('hdel')->never();
        $connection->expects('getRange')->never();

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
        $connection->expects('hGetAll')->returns([
            self::SESSION_ID => $metadata,
            self::OTHER_SESSION_ID => $metadata,
            $missingSessionId => $metadata,
            $rawSessionId => $metadata,
            $corruptSessionId => $metadata,
            $unknownVersionSessionId => $metadata,
            $unreadableSessionId => $metadata,
        ]);
        $connection->expects('getRange')
            ->with('sessions:' . self::SESSION_ID, 0, 36)
            ->returns("\0HVS1" . $owner);
        $connection->expects('getRange')
            ->with('sessions:' . self::OTHER_SESSION_ID, 0, 36)
            ->returns("\0HVS1" . $otherOwner);
        $connection->expects('getRange')
            ->with('sessions:' . $missingSessionId, 0, 36)
            ->returns('');
        $connection->expects('getRange')
            ->with('sessions:' . $rawSessionId, 0, 36)
            ->returns('raw payload');
        $connection->expects('getRange')
            ->with('sessions:' . $corruptSessionId, 0, 36)
            ->returns("\0HVS1" . str_repeat('A', 32));
        $connection->expects('getRange')
            ->with('sessions:' . $unknownVersionSessionId, 0, 36)
            ->returns("\0HVS2" . $owner);
        $connection->expects('getRange')
            ->with('sessions:' . $unreadableSessionId, 0, 36)
            ->returns(false);
        $connection->expects('hdel')->never();

        $sessions = $this->handler(tracked: true)->userSessions('users', 'user-1');

        $this->assertSame([self::SESSION_ID], $sessions->pluck('id')->all());
    }

    public function testClusterUserSessionsDoesNotReadPayloadsWhenIndexHasNoValidCandidates(): void
    {
        $connection = $this->expectConnection(cluster: true);
        $connection->expects('hGetAll')->returns([
            self::SESSION_ID => '{',
            'invalid' => json_encode([
                'ip_address' => null,
                'user_agent' => null,
                'last_activity' => 100,
            ]),
        ]);
        $connection->expects('getRange')->never();
        $connection->expects('hdel')->never();

        $this->assertEmpty($this->handler(tracked: true)->userSessions('users', 'user-1'));
    }

    public function testUserSessionsRejectsInvalidIndexResponses(): void
    {
        $connection = $this->expectConnection();
        $connection->expects('hGetAll')->returns(false);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('Redis returned an invalid user session index.');

        $this->handler(tracked: true)->userSessions('users', 'user-1');
    }

    public function testStandaloneScopedDestroyUsesOneOwnershipScript(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection();
        $captured = [];

        $connection->expects('evalSha')
            ->resolves(static function (string $sha, array $parameters, int $keyCount) use (&$captured): int {
                $captured = [
                    'keys' => array_slice($parameters, 0, $keyCount),
                    'arguments' => array_slice($parameters, $keyCount),
                ];

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

        $connection->expects('evalSha')->ordered()->returns([0, $otherOwner]);
        $connection->expects('hdel')
            ->ordered()
            ->with('sessions:_users:' . $owner . ':sessions', self::SESSION_ID)
            ->returns(1);

        $this->assertFalse($this->handler(tracked: true)->destroyUserSession('users', 'user-1', self::SESSION_ID));
    }

    public function testClusterScopedDestroyRejectsFailedRequestedIndexCleanup(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection(cluster: true);

        $connection->expects('evalSha')->ordered()->returns([1, $owner]);
        $connection->expects('hdel')->ordered()->returns(false);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('Redis failed to clean the user session index.');

        $this->handler(tracked: true)->destroyUserSession('users', 'user-1', self::SESSION_ID);
    }

    public function testStandaloneBulkDestroyUsesOneScriptAndDeduplicatesExceptions(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $connection = $this->expectConnection();
        $captured = [];

        $connection->expects('getOption')->with(Redis::OPT_PREFIX)->returns('redis:');
        $connection->expects('evalSha')
            ->resolves(static function (string $sha, array $parameters, int $keyCount) use (&$captured): int {
                $captured = [
                    'keys' => array_slice($parameters, 0, $keyCount),
                    'arguments' => array_slice($parameters, $keyCount),
                ];

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
        $connection->expects('getOption')->with(Redis::OPT_PREFIX)->returns('redis:');
        $connection->expects('evalSha')->returns($result);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageIs('Redis returned an invalid bulk session deletion result.');

        $this->handler(tracked: true)->destroyUserSessions('users', 'user-1');
    }

    /**
     * Provide invalid bulk deletion results.
     */
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
        $connection->expects('hKeys')
            ->ordered()
            ->returns([self::SESSION_ID, self::OTHER_SESSION_ID, 'invalid']);
        $connection->expects('evalSha')
            ->ordered()
            ->with(Argument::type('string'), ['sessions:' . self::OTHER_SESSION_ID, $owner], 1)
            ->returns([1, $owner]);
        $connection->expects('hdel')
            ->ordered()
            ->with('sessions:_users:' . $owner . ':sessions', self::OTHER_SESSION_ID, 'invalid')
            ->returns(2);

        $deleted = $this->handler(tracked: true)->destroyUserSessions('users', 'user-1', [self::SESSION_ID]);

        $this->assertSame(1, $deleted);
    }

    public function testClusterBulkDestroyClearsObservedOwners(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $handler = new ObservableRedisSessionHandler($this->redis, 'session', 'sessions:', 10, true);
        $handler->rememberOwnerForTesting(self::SESSION_ID, $owner);
        $connection = $this->expectConnection(cluster: true);
        $connection->expects('hKeys')->returns([]);

        $this->assertSame(0, $handler->destroyUserSessions('users', 'user-1'));
        $this->assertNull($handler->observedOwnerForTesting(self::SESSION_ID));
    }

    public function testClusterBulkDestroyRethrowsPayloadFailureAfterSuccessfulCleanup(): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $failure = new RuntimeException('payload failed');
        $connection = $this->expectConnection(cluster: true);
        $connection->expects('hKeys')->returns(['invalid', self::SESSION_ID]);
        $connection->expects('evalSha')->throws($failure);
        $connection->expects('hdel')
            ->with('sessions:_users:' . $owner . ':sessions', 'invalid')
            ->returns(1);

        try {
            $this->handler(tracked: true)->destroyUserSessions('users', 'user-1');
            $this->fail('Expected the payload failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    #[TestWith(['ordinary'])]
    #[TestWith(['direct cancellation'])]
    #[TestWith(['wrapped cancellation'])]
    public function testClusterBulkDestroyRetainsCleanupFailureWhenBothOperationsFail(string $kind): void
    {
        $owner = $this->ownerDigest('users', 'user-1');
        $payloadFailure = match ($kind) {
            'direct cancellation' => new CanceledException,
            'wrapped cancellation' => new RuntimeException('Payload cleanup failed.', previous: new CanceledException),
            default => new UnexpectedValueException('payload failed'),
        };
        $connection = $this->expectConnection(cluster: true);
        $connection->expects('hKeys')->returns(['invalid', self::SESSION_ID]);
        $connection->expects('evalSha')->throws($payloadFailure);
        $connection->expects('hdel')
            ->with('sessions:_users:' . $owner . ':sessions', 'invalid')
            ->returns(false);

        $caught = null;
        try {
            $this->handler(tracked: true)->destroyUserSessions('users', 'user-1');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        if ($kind === 'ordinary') {
            $this->assertInstanceOf(RuntimeException::class, $caught);
            $this->assertStringContainsString(UnexpectedValueException::class, $caught->getMessage());
            $this->assertStringContainsString('payload failed', $caught->getMessage());
            $this->assertInstanceOf(UnexpectedValueException::class, $caught->getPrevious());
            $this->assertSame('Redis failed to clean the user session index.', $caught->getPrevious()->getMessage());
        } else {
            $this->assertSame($payloadFailure, $caught);
        }
    }

    #[DataProvider('invalidSessionIdProvider')]
    public function testManagedDeletesRejectInvalidSessionIdentifiers(string $sessionId): void
    {
        $this->redis->expects('connection')->never();

        try {
            $this->handler(tracked: true)->destroyUserSession('users', 'user-1', $sessionId);
            $this->fail('Expected the scoped delete to reject the identifier.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The session identifier is invalid.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);

        $this->handler(tracked: true)->destroyUserSessions('users', 'user-1', [$sessionId]);
    }

    /**
     * Provide invalid session identifiers.
     */
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
        $this->redis->expects('connection')->never();

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
        $connection->expects('get')->throws($failure);

        try {
            $this->handler()->read(self::SESSION_ID);
            $this->fail('Expected the Redis exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * Create the session handler.
     */
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

    /**
     * Expect a raw connection lease and return its native client double.
     */
    private function expectConnection(bool $cluster = false): (Redis&DoubleInterface)|(RedisCluster&DoubleInterface)
    {
        return $this->expectConnections(1, $cluster)[0];
    }

    /**
     * Expect the session connection lookups in order and return their native client doubles.
     *
     * @return list<(DoubleInterface&Redis)|(DoubleInterface&RedisCluster)>
     */
    private function expectConnections(int $count, bool $cluster = false): array
    {
        $clients = [];
        $proxies = [];

        for ($i = 0; $i < $count; ++$i) {
            $client = Double::for($cluster ? RedisCluster::class : Redis::class)->strict();
            $client->expects('getOption')->with(Redis::OPT_SERIALIZER)->returns(Redis::SERIALIZER_NONE);
            $client->expects('getOption')->with(Redis::OPT_COMPRESSION)->returns(Redis::COMPRESSION_NONE);
            $client->allows('clearLastError');
            $client->allows('getLastError')->returns(null);
            $client->allows('getMode')->returns(Redis::ATOMIC);

            $connection = ($cluster ? new PhpRedisClusterConnectionStub : new PhpRedisConnectionStub)
                ->setActiveConnection($client);
            $proxy = Double::for(RedisProxy::class)->strict();

            $proxy->expects('isCluster')->returns($cluster);
            $proxy->expects('withConnection')
                ->with(Argument::type('callable'), false)
                ->resolves(static fn (callable $callback): mixed => $callback($connection));

            $clients[] = $client;
            $proxies[] = $proxy;
        }

        $this->redis->expects('connection')->with('session')->times($count)->returns(...$proxies);

        return $clients;
    }

    /**
     * Create a container with an authenticated guard.
     */
    private function authenticatedContainer(
        string $userId,
        bool $withRequest,
        ?string $authProvider = 'users',
    ): Container {
        $guard = Double::for(Guard::class)->strict();
        $guard->expects('id')->returns($userId);

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

    /**
     * Calculate the provider-qualified owner digest.
     */
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
    /**
     * Record an observed session owner.
     */
    public function rememberOwnerForTesting(string $sessionId, string $owner): void
    {
        $this->rememberOwner($sessionId, $owner);
    }

    /**
     * Get the observed session owner.
     */
    public function observedOwnerForTesting(string $sessionId): ?string
    {
        return $this->observedOwner($sessionId);
    }
}
