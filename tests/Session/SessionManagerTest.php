<?php

declare(strict_types=1);

namespace Hypervel\Tests\Session;

use BadMethodCallException;
use Hypervel\Auth\AuthManager;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Foundation\Auth\User as FoundationUser;
use Hypervel\Session\Contracts\CanManageUserSessions;
use Hypervel\Session\DatabaseSessionHandler;
use Hypervel\Session\EncryptedStore;
use Hypervel\Session\RedisSessionHandler;
use Hypervel\Session\SessionManager;
use Hypervel\Session\Store;
use Hypervel\Session\UserSessions;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use SessionHandlerInterface;

class SessionManagerTest extends TestCase
{
    public function testEnumDefaultDriverIsNormalizedWithoutTreatingZeroAsAbsent(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session' => [
                'driver' => 'array',
                'lifetime' => 120,
                'cookie' => 'session',
                'encrypt' => false,
                'serialization' => 'php',
            ],
        ]));

        $manager->extend('0', fn () => m::mock(SessionHandlerInterface::class));
        $manager->setDefaultDriver(SessionIntegerIdentifier::Zero);

        $this->assertSame('0', $manager->getDefaultDriver());
        $this->assertSame($manager->driver('0'), $manager->driver());
    }

    public function testDatabaseDriverLeavesConnectionUnsetByDefault(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
        ]));

        $store = $manager->driver();

        $this->assertInstanceOf(Store::class, $store);
        $this->assertInstanceOf(DatabaseSessionHandler::class, $this->handlerFromStore($store));
        $this->assertNull($this->databaseConnectionFromHandler($this->handlerFromStore($store)));
    }

    public function testRedisDriverDefaultsToSessionConnectionWhenUnset(): void
    {
        $container = $this->getContainer([
            'session.driver' => 'redis',
            'session.connection' => null,
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
            'session.prefix' => 'application_session:',
            'session.track_user_sessions' => false,
        ]);
        $container->instance(RedisFactory::class, m::mock(RedisFactory::class));

        $sessionStore = (new SessionManager($container))->driver();
        $handler = $this->handlerFromStore($sessionStore);

        $this->assertInstanceOf(Store::class, $sessionStore);
        $this->assertInstanceOf(RedisSessionHandler::class, $handler);
        $this->assertSame('session', $this->propertyFromObject($handler, 'connection'));
        $this->assertSame('application_session:', $this->propertyFromObject($handler, 'prefix'));
        $this->assertSame(120, $this->propertyFromObject($handler, 'minutes'));
        $this->assertFalse($this->propertyFromObject($handler, 'trackUserSessions'));
        $this->assertFalse($container->bound('cache'));
    }

    public function testExplicitSessionConnectionOverridesBothDrivers(): void
    {
        $databaseManager = new SessionManager($this->getContainer([
            'session.driver' => 'database',
            'session.connection' => 'custom-session',
            'session.table' => 'sessions',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
        ]));

        $databaseStore = $databaseManager->driver();

        $this->assertSame(
            'custom-session',
            $this->databaseConnectionFromHandler($this->handlerFromStore($databaseStore))
        );

        $container = $this->getContainer([
            'session.driver' => 'redis',
            'session.connection' => 'custom-session',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
            'session.prefix' => 'application_session:',
            'session.track_user_sessions' => true,
        ]);
        $redis = m::mock(RedisFactory::class);
        $container->instance(RedisFactory::class, $redis);

        $redisSessionStore = (new SessionManager($container))->driver();
        $handler = $this->handlerFromStore($redisSessionStore);

        $this->assertInstanceOf(RedisSessionHandler::class, $handler);
        $this->assertSame($redis, $this->propertyFromObject($handler, 'redis'));
        $this->assertSame('custom-session', $this->propertyFromObject($handler, 'connection'));
        $this->assertSame('application_session:', $this->propertyFromObject($handler, 'prefix'));
        $this->assertTrue($this->propertyFromObject($handler, 'trackUserSessions'));
    }

    public function testCapabilityProbeReflectsTheConfiguredHandler(): void
    {
        $database = new SessionManager($this->getContainer([
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
        ]));
        $this->assertTrue($database->supportsUserSessionManagement());

        foreach ([false, true] as $tracking) {
            $container = $this->getContainer([
                'session.driver' => 'redis',
                'session.connection' => null,
                'session.lifetime' => 120,
                'session.cookie' => 'session',
                'session.encrypt' => false,
                'session.serialization' => 'php',
                'session.prefix' => 'application_session:',
                'session.track_user_sessions' => $tracking,
            ]);
            $container->instance(RedisFactory::class, m::mock(RedisFactory::class));

            $this->assertSame(
                $tracking,
                (new SessionManager($container))->supportsUserSessionManagement(),
            );
        }

        $array = new SessionManager($this->getContainer([
            'session.driver' => 'array',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
        ]));
        $this->assertFalse($array->supportsUserSessionManagement());
    }

    #[DataProvider('supportedUserIdentifierProvider')]
    public function testForUserNormalizesSupportedIdentifiers(int|string $userId, string $expected): void
    {
        $handler = new SessionManagerCapableHandler;
        $manager = $this->customManager($handler);

        $repository = $manager->forUser($userId);

        $this->assertInstanceOf(UserSessions::class, $repository);
        $this->assertSame([], $repository->all()->all());
        $this->assertSame('users', $handler->listedAuthProvider);
        $this->assertSame($expected, $handler->listedUserId);
    }

    public static function supportedUserIdentifierProvider(): array
    {
        return [
            'integer zero' => [0, '0'],
            'numeric string' => ['42', '42'],
            'uuid' => ['550e8400-e29b-41d4-a716-446655440000', '550e8400-e29b-41d4-a716-446655440000'],
            'ulid' => ['01HV4ZQ3R4N56M7P8Q9S0T1U2V', '01HV4ZQ3R4N56M7P8Q9S0T1U2V'],
        ];
    }

    public function testForUserExtractsAnAuthenticatableIdentifier(): void
    {
        $handler = new SessionManagerCapableHandler;
        $manager = $this->customManager($handler);
        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn('user-1');

        $manager->forUser($user)->all();

        $this->assertSame('users', $handler->listedAuthProvider);
        $this->assertSame('user-1', $handler->listedUserId);
    }

    public function testForUserUsesSelectedOrExplicitGuardProviderWithoutChangingSelection(): void
    {
        $handler = new SessionManagerCapableHandler;
        $manager = $this->customManager($handler);
        /** @var AuthManager $auth */
        $auth = Container::getInstance()->make('auth');
        $auth->shouldUse('admin');

        $manager->forUser('1')->all();
        $this->assertSame('admins', $handler->listedAuthProvider);

        $manager->forUser('1', 'web')->all();
        $this->assertSame('users', $handler->listedAuthProvider);

        $manager->forUser('1', SessionGuardIdentifier::Secondary)->all();
        $this->assertSame('users', $handler->listedAuthProvider);
        $this->assertSame('admin', $auth->getDefaultDriver());
    }

    public function testForUserRejectsAModelFromAnotherEloquentProvider(): void
    {
        $handler = new SessionManagerCapableHandler;
        $manager = $this->customManager($handler);
        Container::getInstance()->make('config')->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => SessionManagerUserStub::class,
            'cache' => [
                'enabled' => false,
                'store' => null,
                'ttl' => 300,
                'prefix' => 'auth_users',
                'tags' => null,
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'User [' . SessionManagerAdminStub::class . '] does not belong to auth provider [users].'
        );

        $manager->forUser(new SessionManagerAdminStub);
    }

    public function testForUserAcceptsAModelFromTheSelectedEloquentProvider(): void
    {
        $handler = new SessionManagerCapableHandler;
        $manager = $this->customManager($handler);
        Container::getInstance()->make('config')->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => SessionManagerUserStub::class,
            'cache' => [
                'enabled' => false,
                'store' => null,
                'ttl' => 300,
                'prefix' => 'auth_users',
                'tags' => null,
            ],
        ]);
        $user = new SessionManagerUserStub;
        $user->setAttribute('id', 42);

        $manager->forUser($user)->all();

        $this->assertSame('users', $handler->listedAuthProvider);
        $this->assertSame('42', $handler->listedUserId);
    }

    public function testForUserRejectsAGuardWithoutAProvider(): void
    {
        $handler = new SessionManagerCapableHandler;
        $manager = $this->customManager($handler);
        Container::getInstance()->make('config')->set('auth.guards.providerless', [
            'driver' => 'custom',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Auth guard [providerless] does not declare a user provider. Set auth.guards.providerless.provider.'
        );

        $manager->forUser('user-1', 'providerless');
    }

    public function testForUserRejectsEmptyAndInvalidModelIdentifiers(): void
    {
        $handler = new SessionManagerCapableHandler;
        $manager = $this->customManager($handler);

        try {
            $manager->forUser('');
            $this->fail('Expected an empty identifier to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The user identifier may not be empty.', $exception->getMessage());
        }

        $user = m::mock(Authenticatable::class);
        $user->shouldReceive('getAuthIdentifier')->once()->andReturnNull();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The user identifier must be an integer or string.');

        $manager->forUser($user);
    }

    public function testForUserRejectsUnsupportedAndDisabledDrivers(): void
    {
        $unsupported = new SessionManager($this->getContainer([
            'session.driver' => 'array',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
        ]));
        $container = $this->getContainer([
            'session.driver' => 'redis',
            'session.connection' => null,
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => false,
            'session.serialization' => 'php',
            'session.prefix' => 'application_session:',
            'session.track_user_sessions' => false,
        ]);
        $container->instance(RedisFactory::class, m::mock(RedisFactory::class));
        $disabled = new SessionManager($container);

        foreach ([$unsupported, $disabled] as $manager) {
            try {
                $manager->forUser('user-1');
                $this->fail('Expected the session driver to reject user session management.');
            } catch (BadMethodCallException $exception) {
                $this->assertSame(
                    'This session driver does not support user session management.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testEncryptedStoresExposeTheUnderlyingHandlerCapability(): void
    {
        $handler = new SessionManagerCapableHandler;
        $manager = $this->customManager($handler, encrypted: true);

        $this->assertTrue($manager->supportsUserSessionManagement());
        $this->assertInstanceOf(UserSessions::class, $manager->forUser('user-1'));
        $this->assertInstanceOf(EncryptedStore::class, $manager->driver());
    }

    public function testSessionSerializationUsesConfiguredStrategy(): void
    {
        foreach (['json', 'php'] as $serialization) {
            $manager = new SessionManager($this->getContainer([
                'session.driver' => 'array',
                'session.lifetime' => 120,
                'session.cookie' => 'session',
                'session.encrypt' => false,
                'session.serialization' => $serialization,
            ]));

            $this->assertSame($serialization, $this->serializationFromStore($manager->driver()));
        }
    }

    public function testEncryptedSessionSerializationUsesConfiguredStrategy(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session.driver' => 'array',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => true,
            'session.serialization' => 'json',
        ]));

        $store = $manager->driver();

        $this->assertInstanceOf(EncryptedStore::class, $store);
        $this->assertSame('json', $this->serializationFromStore($store));
    }

    public function testBlockingConfigurationUsesDeclaredValues(): void
    {
        $manager = new SessionManager($this->getContainer([
            'session.driver' => 'array',
            'session.block' => true,
            'session.block_store' => 'locks',
            'session.block_lock_seconds' => 30,
            'session.block_wait_seconds' => 15,
        ]));

        $this->assertTrue($manager->shouldBlock());
        $this->assertSame('locks', $manager->blockDriver());
        $this->assertSame(30, $manager->defaultRouteBlockLockSeconds());
        $this->assertSame(15, $manager->defaultRouteBlockWaitSeconds());
    }

    protected function getContainer(array $config): Container
    {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);
        $container->instance('config', new ConfigRepository($config));
        $container->instance(Encrypter::class, m::mock(Encrypter::class));
        $container->instance('db', m::mock(ConnectionResolverInterface::class));

        Container::setInstance($container);

        return $container;
    }

    protected function customManager(
        SessionManagerCapableHandler $handler,
        bool $encrypted = false,
    ): SessionManager {
        $container = $this->getContainer([
            'session.driver' => 'capable',
            'session.lifetime' => 120,
            'session.cookie' => 'session',
            'session.encrypt' => $encrypted,
            'session.serialization' => 'php',
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => [
                    'web' => [
                        'driver' => 'custom',
                        'provider' => 'users',
                    ],
                    'admin' => [
                        'driver' => 'custom',
                        'provider' => 'admins',
                    ],
                    'secondary' => [
                        'driver' => 'custom',
                        'provider' => 'users',
                    ],
                ],
                'providers' => [
                    'users' => ['driver' => 'custom'],
                    'admins' => ['driver' => 'custom'],
                ],
            ],
        ]);
        $container->instance('auth', new AuthManager($container));
        $manager = new SessionManager($container);
        $manager->extend('capable', static fn (): SessionHandlerInterface => $handler);

        return $manager;
    }

    protected function handlerFromStore(Store $store): object
    {
        $property = new ReflectionProperty($store, 'handler');

        return $property->getValue($store);
    }

    protected function databaseConnectionFromHandler(DatabaseSessionHandler $handler): ?string
    {
        $property = new ReflectionProperty($handler, 'connection');

        return $property->getValue($handler);
    }

    protected function propertyFromObject(object $object, string $property): mixed
    {
        $property = new ReflectionProperty($object, $property);

        return $property->getValue($object);
    }

    protected function serializationFromStore(Store $store): string
    {
        $property = new ReflectionProperty($store, 'serialization');

        return $property->getValue($store);
    }
}

enum SessionIntegerIdentifier: int
{
    case Zero = 0;
}

enum SessionGuardIdentifier: string
{
    case Secondary = 'secondary';
}

class SessionManagerUserStub extends FoundationUser
{
}

class SessionManagerAdminStub extends FoundationUser
{
}

class SessionManagerCapableHandler implements CanManageUserSessions, SessionHandlerInterface
{
    public ?string $listedAuthProvider = null;

    public ?string $listedUserId = null;

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        return '';
    }

    public function write(string $id, string $data): bool
    {
        return true;
    }

    public function destroy(string $id): bool
    {
        return true;
    }

    public function gc(int $maxLifetime): int
    {
        return 0;
    }

    public function supportsUserSessionManagement(): bool
    {
        return true;
    }

    public function userSessions(string $authProvider, int|string $userId): Collection
    {
        $this->listedAuthProvider = $authProvider;
        $this->listedUserId = (string) $userId;

        return new Collection;
    }

    public function destroyUserSession(
        string $authProvider,
        int|string $userId,
        string $sessionId,
    ): bool {
        return false;
    }

    public function destroyUserSessions(
        string $authProvider,
        int|string $userId,
        array $except = [],
    ): int {
        return 0;
    }
}
