<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use Hypervel\Auth\AuthManager;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Session\Contracts\CanManageUserSessions;
use Hypervel\Session\Store;
use Hypervel\Session\UserSession;
use Hypervel\Session\UserSessionIdentity;
use Hypervel\Session\UserSessions;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;
use SessionHandlerInterface;

class UserSessionsTest extends TestCase
{
    private const string AUTH_PROVIDER = 'users';

    private const string CURRENT_SESSION_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string OTHER_SESSION_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string THIRD_SESSION_ID = 'cccccccccccccccccccccccccccccccccccccccc';

    public function testItReturnsTheHandlersTypedCollection(): void
    {
        $session = new UserSession(
            self::OTHER_SESSION_ID,
            '203.0.113.10',
            'Browser/1.0',
            CarbonImmutable::createFromTimestampUTC(100),
            CarbonImmutable::createFromTimestampUTC(3700),
        );
        $handler = new InMemoryUserSessionHandler;
        $handler->sessions = new Collection([$session]);

        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $this->makeStore($handler));

        $this->assertSame($handler->sessions, $sessions->all());
        $this->assertSame([['list', self::AUTH_PROVIDER, 'user-1']], $handler->operations);
    }

    public function testIndividualInvalidationDoesNotRotateAnUnrelatedCurrentSession(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::OTHER_SESSION_ID);
        $store = $this->makeStore($handler);
        $store->put('name', 'Taylor');

        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertTrue($sessions->invalidate(self::OTHER_SESSION_ID));
        $this->assertSame(self::CURRENT_SESSION_ID, $store->getId());
        $this->assertSame('Taylor', $store->get('name'));
        $this->assertSame([['single', self::AUTH_PROVIDER, 'user-1', self::OTHER_SESSION_ID]], $handler->operations);
    }

    public function testFailedCurrentInvalidationDoesNotRotateTheStore(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $store = $this->makeStore($handler);
        $token = $store->token();
        $store->put('name', 'Taylor');

        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertFalse($sessions->invalidate(self::CURRENT_SESSION_ID));
        $this->assertSame(self::CURRENT_SESSION_ID, $store->getId());
        $this->assertSame($token, $store->token());
        $this->assertSame('Taylor', $store->get('name'));
    }

    public function testSuccessfulCurrentInvalidationFlushesRotatesAndSuppressesTheReplacement(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID);
        $store = $this->makeStore($handler);
        $token = $store->token();
        $store->put('name', 'Taylor');

        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertTrue($sessions->invalidate(self::CURRENT_SESSION_ID));

        $this->assertNotSame(self::CURRENT_SESSION_ID, $store->getId());
        $this->assertNotSame($token, $store->token());
        $this->assertSame(['_token' => $store->token()], $store->all());
        $this->assertTrue(UserSessionIdentity::resolve(null, $store->getId())->isUnowned());
    }

    public function testReusedRepositorySnapshotsTheCurrentStoreIdentifierForEveryMutation(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID);
        $store = $this->makeStore($handler);
        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertTrue($sessions->invalidate(self::CURRENT_SESSION_ID));

        $replacementSessionId = $store->getId();
        $handler->own(self::AUTH_PROVIDER, 'user-1', $replacementSessionId, self::OTHER_SESSION_ID);

        $this->assertSame(2, $sessions->invalidateAll());
        $this->assertNotSame($replacementSessionId, $store->getId());
        $this->assertSame([
            ['single', self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID],
            ['single', self::AUTH_PROVIDER, 'user-1', $replacementSessionId],
            ['bulk', self::AUTH_PROVIDER, 'user-1', [$replacementSessionId]],
        ], $handler->operations);
    }

    public function testInvalidatingOthersProvesAndRotatesTheCurrentSessionBeforeBulkDeletion(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(
            self::AUTH_PROVIDER,
            'user-1',
            self::CURRENT_SESSION_ID,
            self::OTHER_SESSION_ID,
            self::THIRD_SESSION_ID,
        );
        $store = $this->makeStore($handler);
        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertSame(2, $sessions->invalidateOthers(self::OTHER_SESSION_ID));

        $this->assertSame([self::OTHER_SESSION_ID], $handler->sessionIdsFor(self::AUTH_PROVIDER, 'user-1'));
        $this->assertNotSame(self::CURRENT_SESSION_ID, $store->getId());
        $this->assertSame([
            ['single', self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID],
            ['bulk', self::AUTH_PROVIDER, 'user-1', [self::OTHER_SESSION_ID, self::CURRENT_SESSION_ID]],
        ], $handler->operations);
    }

    public function testInvalidatingOthersPreservesTheCurrentSessionWithoutASeparateProbe(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID, self::OTHER_SESSION_ID);
        $store = $this->makeStore($handler);
        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertSame(1, $sessions->invalidateOthers(self::CURRENT_SESSION_ID));

        $this->assertSame(self::CURRENT_SESSION_ID, $store->getId());
        $this->assertSame([self::CURRENT_SESSION_ID], $handler->sessionIdsFor(self::AUTH_PROVIDER, 'user-1'));
        $this->assertSame([
            ['bulk', self::AUTH_PROVIDER, 'user-1', [self::CURRENT_SESSION_ID]],
        ], $handler->operations);
    }

    public function testUnstartedStoreDoesNotReadOrRotateItsIdentifier(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::OTHER_SESSION_ID);
        $store = new class('name', $handler, self::CURRENT_SESSION_ID) extends Store {
            public int $getIdCalls = 0;

            public function getId(): string
            {
                ++$this->getIdCalls;

                return parent::getId();
            }
        };
        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertSame(1, $sessions->invalidateAll());
        $this->assertSame(0, $store->getIdCalls);
        $this->assertSame([['bulk', self::AUTH_PROVIDER, 'user-1', []]], $handler->operations);
    }

    public function testManagingAnotherUserLeavesTheCurrentStoreUntouched(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'admin', self::CURRENT_SESSION_ID);
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::OTHER_SESSION_ID);
        $store = $this->makeStore($handler);
        $token = $store->token();
        $store->put('role', 'admin');

        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertSame(1, $sessions->invalidateAll());
        $this->assertSame(self::CURRENT_SESSION_ID, $store->getId());
        $this->assertSame($token, $store->token());
        $this->assertSame('admin', $store->get('role'));
        $this->assertSame([self::CURRENT_SESSION_ID], $handler->sessionIdsFor(self::AUTH_PROVIDER, 'admin'));
    }

    public function testRepositoriesWithTheSameUserIdentifierRemainProviderScoped(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own('users', '1', self::OTHER_SESSION_ID);
        $handler->own('admins', '1', self::THIRD_SESSION_ID);
        $store = new Store('name', $handler, self::CURRENT_SESSION_ID);
        $sessions = new UserSessions('admins', '1', $handler, $store);

        $this->assertSame(1, $sessions->invalidateAll());
        $this->assertSame([self::OTHER_SESSION_ID], $handler->sessionIdsFor('users', '1'));
        $this->assertSame([], $handler->sessionIdsFor('admins', '1'));
        $this->assertSame([['bulk', 'admins', '1', []]], $handler->operations);
    }

    public function testNeverPersistedCurrentSessionDoesNotRotateThroughBulkInvalidation(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::OTHER_SESSION_ID);
        $store = $this->makeStore($handler);
        $token = $store->token();
        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertSame(1, $sessions->invalidateAll());
        $this->assertSame(self::CURRENT_SESSION_ID, $store->getId());
        $this->assertSame($token, $store->token());
        $this->assertSame([
            ['single', self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID],
            ['bulk', self::AUTH_PROVIDER, 'user-1', [self::CURRENT_SESSION_ID]],
        ], $handler->operations);
    }

    public function testBulkFailureCannotUndoAnAlreadyRotatedCurrentSession(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID, self::OTHER_SESSION_ID);
        $handler->destroyManyException = new RuntimeException('Unable to destroy remaining sessions.');
        $store = $this->makeStore($handler);
        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        try {
            $sessions->invalidateAll();

            $this->fail('Expected the bulk session deletion to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to destroy remaining sessions.', $exception->getMessage());
        }

        $this->assertNotSame(self::CURRENT_SESSION_ID, $store->getId());
        $this->assertFalse($handler->owns(self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID));
        $this->assertTrue(UserSessionIdentity::resolve(null, $store->getId())->isUnowned());
    }

    public function testRepeatedSavesRetainReplacementSuppression(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $handler->own(self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID);
        $store = $this->makeStore($handler);
        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

        $this->assertTrue($sessions->invalidate(self::CURRENT_SESSION_ID));

        $replacementSessionId = $store->getId();
        $store->save();
        $store->save();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->never();
        $container->shouldReceive('make')->never();

        $this->assertTrue(UserSessionIdentity::resolve($container, $replacementSessionId)->isUnowned());
        $this->assertTrue(UserSessionIdentity::resolve($container, $replacementSessionId)->isUnowned());
        $this->assertSame([$replacementSessionId, $replacementSessionId], $handler->writtenSessionIds);
    }

    public function testLaterLoginStyleRegenerationRotatesBeyondTheUnownedReplacementForAnyUser(): void
    {
        foreach (['user-1', 'user-2'] as $userId) {
            $handler = new InMemoryUserSessionHandler;
            $handler->own(self::AUTH_PROVIDER, 'user-1', self::CURRENT_SESSION_ID);
            $store = $this->makeStore($handler);
            $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $store);

            $this->assertTrue($sessions->invalidate(self::CURRENT_SESSION_ID));
            $unownedSessionId = $store->getId();

            $store->regenerate(true);

            $guard = m::mock(Guard::class);
            $guard->shouldReceive('id')->once()->andReturn($userId);

            $container = $this->identityContainer($guard);

            $identity = UserSessionIdentity::resolve($container, $store->getId());

            $this->assertNotSame($unownedSessionId, $store->getId());
            $this->assertTrue($identity->isResolved());
            $this->assertFalse($identity->isUnowned());
            $this->assertSame(self::AUTH_PROVIDER, $identity->authProvider);
            $this->assertSame($userId, $identity->userId);
        }
    }

    public function testInvalidSessionIdentifiersFailBeforeReachingTheHandler(): void
    {
        $handler = new InMemoryUserSessionHandler;
        $sessions = new UserSessions(self::AUTH_PROVIDER, 'user-1', $handler, $this->makeStore($handler));

        foreach (['short', str_repeat('a', 39), str_repeat('-', 40)] as $invalidSessionId) {
            try {
                $sessions->invalidate($invalidSessionId);

                $this->fail('Expected an invalid session identifier to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The session identifier is invalid.', $exception->getMessage());
            }

            try {
                $sessions->invalidateOthers($invalidSessionId);

                $this->fail('Expected an invalid exception identifier to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The session identifier is invalid.', $exception->getMessage());
            }
        }

        $this->assertSame([], $handler->operations);
    }

    private function makeStore(InMemoryUserSessionHandler $handler): Store
    {
        $store = new Store('name', $handler, self::CURRENT_SESSION_ID);
        $store->start();

        return $store;
    }

    private function identityContainer(Guard $guard): Container
    {
        $container = new Container;
        $container->instance('config', new Repository([
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => [
                    'web' => [
                        'driver' => 'custom',
                        'provider' => self::AUTH_PROVIDER,
                    ],
                ],
            ],
        ]));
        $auth = new AuthManager($container);
        $auth->extend('custom', fn () => $guard);
        $container->instance('auth', $auth);

        return $container;
    }
}

class InMemoryUserSessionHandler implements CanManageUserSessions, SessionHandlerInterface
{
    /** @var array<string, array<string, array<string, true>>> */
    public array $ownedSessionIds = [];

    /** @var list<array<mixed>> */
    public array $operations = [];

    /** @var list<string> */
    public array $writtenSessionIds = [];

    /** @var Collection<int, UserSession> */
    public Collection $sessions;

    public ?RuntimeException $destroyManyException = null;

    public function __construct()
    {
        $this->sessions = new Collection;
    }

    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): false|string
    {
        return '';
    }

    public function write(string $sessionId, string $data): bool
    {
        $this->writtenSessionIds[] = $sessionId;

        return true;
    }

    public function destroy(string $sessionId): bool
    {
        return true;
    }

    public function gc(int $lifetime): int
    {
        return 0;
    }

    public function supportsUserSessionManagement(): bool
    {
        return true;
    }

    public function userSessions(string $authProvider, int|string $userId): Collection
    {
        $this->operations[] = ['list', $authProvider, (string) $userId];

        return $this->sessions;
    }

    public function destroyUserSession(
        string $authProvider,
        int|string $userId,
        string $sessionId,
    ): bool {
        $normalizedUserId = (string) $userId;
        $this->operations[] = ['single', $authProvider, $normalizedUserId, $sessionId];

        if (! $this->owns($authProvider, $normalizedUserId, $sessionId)) {
            return false;
        }

        unset($this->ownedSessionIds[$authProvider][$normalizedUserId][$sessionId]);

        return true;
    }

    public function destroyUserSessions(
        string $authProvider,
        int|string $userId,
        array $except = [],
    ): int {
        $normalizedUserId = (string) $userId;
        $this->operations[] = ['bulk', $authProvider, $normalizedUserId, $except];

        if ($this->destroyManyException !== null) {
            throw $this->destroyManyException;
        }

        $destroyed = 0;

        foreach (array_keys($this->ownedSessionIds[$authProvider][$normalizedUserId] ?? []) as $sessionId) {
            if (in_array($sessionId, $except, true)) {
                continue;
            }

            unset($this->ownedSessionIds[$authProvider][$normalizedUserId][$sessionId]);
            ++$destroyed;
        }

        return $destroyed;
    }

    public function own(string $authProvider, string $userId, string ...$sessionIds): void
    {
        foreach ($sessionIds as $sessionId) {
            $this->ownedSessionIds[$authProvider][$userId][$sessionId] = true;
        }
    }

    public function owns(string $authProvider, string $userId, string $sessionId): bool
    {
        return isset($this->ownedSessionIds[$authProvider][$userId][$sessionId]);
    }

    /** @return list<string> */
    public function sessionIdsFor(string $authProvider, string $userId): array
    {
        return array_keys($this->ownedSessionIds[$authProvider][$userId] ?? []);
    }
}
