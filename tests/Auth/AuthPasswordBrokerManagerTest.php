<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Passwords\PasswordBroker as PasswordBrokerImplementation;
use Hypervel\Auth\Passwords\PasswordBrokerManager;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\PasswordBroker as PasswordBrokerContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Hashing\Hasher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Coroutine\parallel;

class AuthPasswordBrokerManagerTest extends TestCase
{
    public function testResolveBrokerNameForGuardReturnsDeclaredBroker(): void
    {
        $manager = new PasswordBrokerManager($this->makeContainer([
            'auth' => [
                'guards' => [
                    'staff' => [
                        'passwords' => 'staff',
                    ],
                ],
            ],
        ]));

        $this->assertSame('staff', $manager->resolveBrokerNameForGuard('staff'));
    }

    public function testResolveBrokerNameForGuardReturnsNullWhenAbsent(): void
    {
        $manager = new PasswordBrokerManager($this->makeContainer([
            'auth' => [
                'guards' => [
                    'staff' => [],
                ],
            ],
        ]));

        $this->assertNull($manager->resolveBrokerNameForGuard('staff'));
    }

    public function testResolveBrokerNameForGuardReturnsNullForEmptyString(): void
    {
        $manager = new PasswordBrokerManager($this->makeContainer([
            'auth' => [
                'guards' => [
                    'staff' => [
                        'passwords' => '',
                    ],
                ],
            ],
        ]));

        $this->assertNull($manager->resolveBrokerNameForGuard('staff'));
    }

    #[DataProvider('malformedPasswordBrokerProvider')]
    public function testResolveBrokerNameForGuardFailsFastOnMalformedValues(mixed $broker): void
    {
        $manager = new PasswordBrokerManager($this->makeContainer([
            'auth' => [
                'guards' => [
                    'staff' => [
                        'passwords' => $broker,
                    ],
                ],
            ],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [auth.guards.staff.passwords] must be a string');

        $manager->resolveBrokerNameForGuard('staff');
    }

    /**
     * Provide malformed password broker names.
     */
    public static function malformedPasswordBrokerProvider(): array
    {
        return [
            'null' => [null],
            'integer' => [123],
            'array' => [['users']],
        ];
    }

    public function testDefaultDriverResolvesFromCurrentDefaultGuard(): void
    {
        $container = $this->makeContainer([
            'auth' => [
                'guards' => [
                    'web' => [
                        'passwords' => 'users',
                    ],
                ],
            ],
        ]);
        $container->instance(AuthFactory::class, $this->mockAuthFactory('web'));

        $this->assertSame('users', (new PasswordBrokerManager($container))->getDefaultDriver());
    }

    public function testDefaultDriverAcceptsFalseyBrokerNames(): void
    {
        $container = $this->makeContainer([
            'auth' => [
                'guards' => [
                    'web' => [
                        'passwords' => '0',
                    ],
                ],
            ],
        ]);
        $container->instance(AuthFactory::class, $this->mockAuthFactory('web'));

        $this->assertSame('0', (new PasswordBrokerManager($container))->getDefaultDriver());
    }

    public function testResolveBrokerNameForGuardAcceptsEnumIdentifiers(): void
    {
        $manager = new PasswordBrokerManager($this->makeContainer([
            'auth' => [
                'guards' => [
                    'staff' => [
                        'passwords' => 'staff-broker',
                    ],
                    'Api' => [
                        'passwords' => 'api-broker',
                    ],
                ],
            ],
        ]));

        $this->assertSame('staff-broker', $manager->resolveBrokerNameForGuard(AuthPasswordBrokerStringEnum::Staff));
        $this->assertSame('api-broker', $manager->resolveBrokerNameForGuard(AuthPasswordBrokerUnitEnum::Api));
    }

    public function testDefaultDriverThrowsWhenGuardDeclaresNoBroker(): void
    {
        $container = $this->makeContainer([
            'auth' => [
                'guards' => [
                    'web' => [],
                ],
            ],
        ]);
        $container->instance(AuthFactory::class, $this->mockAuthFactory('web'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth guard [web] does not declare a passwords broker. Set auth.guards.web.passwords.');

        (new PasswordBrokerManager($container))->getDefaultDriver();
    }

    public function testSetDefaultDriverOverridesGuardDeclaration(): void
    {
        $auth = $this->mockAuthFactory('web');
        $auth->shouldNotReceive('getDefaultDriver');

        $container = $this->makeContainer([
            'auth' => [
                'guards' => [
                    'web' => [
                        'passwords' => 'users',
                    ],
                ],
            ],
        ]);
        $container->instance(AuthFactory::class, $auth);

        $manager = new PasswordBrokerManager($container);
        $manager->setDefaultDriver('other');

        $this->assertSame('other', $manager->getDefaultDriver());
    }

    public function testSetDefaultDriverAcceptsAnIntBackedZeroEnum(): void
    {
        $manager = new PasswordBrokerManager(new Container);

        $manager->setDefaultDriver(AuthPasswordBrokerIntEnum::Zero);

        $this->assertSame('0', $manager->getDefaultDriver());
    }

    public function testSetDefaultDriverIsCoroutineIsolated(): void
    {
        $container = $this->makeContainer([
            'auth' => [
                'guards' => [
                    'web' => [
                        'passwords' => 'users',
                    ],
                ],
            ],
        ]);
        $container->instance(AuthFactory::class, $this->mockAuthFactory('web'));

        $manager = new PasswordBrokerManager($container);

        [$firstBroker, $secondBroker, $defaultBroker] = parallel([
            function () use ($manager): string {
                $manager->setDefaultDriver('first');
                usleep(5000);

                return $manager->getDefaultDriver();
            },
            function () use ($manager): string {
                $manager->setDefaultDriver('second');
                usleep(5000);

                return $manager->getDefaultDriver();
            },
            function () use ($manager): string {
                usleep(5000);

                return $manager->getDefaultDriver();
            },
        ]);

        $this->assertSame('first', $firstBroker);
        $this->assertSame('second', $secondBroker);
        $this->assertSame('users', $defaultBroker);
    }

    public function testBrokerWithExplicitNameBypassesDefaultResolution(): void
    {
        $container = $this->makeContainer([
            'app' => [
                'key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            ],
            'auth' => [
                'timebox_duration' => 200000,
                'passwords' => [
                    'admins' => [
                        'provider' => 'admins',
                        'table' => 'admin_password_reset_tokens',
                    ],
                ],
            ],
        ]);
        $container->instance('auth', $auth = m::mock());
        $container->instance('db', $db = m::mock());
        $container->instance('hash', m::mock(Hasher::class));

        $auth->shouldReceive('createUserProvider')
            ->once()
            ->with('admins')
            ->andReturn(m::mock(UserProvider::class));
        $db->shouldReceive('connection')
            ->once()
            ->with(null)
            ->andReturn(m::mock(ConnectionInterface::class));

        $this->assertInstanceOf(PasswordBrokerContract::class, (new PasswordBrokerManager($container))->broker('admins'));
    }

    public function testBrokerWithExplicitFalseyNameDoesNotFallBackToDefaultDriver(): void
    {
        $container = $this->makeContainer([
            'app' => [
                'key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            ],
            'auth' => [
                'timebox_duration' => 200000,
                'passwords' => [
                    '0' => [
                        'provider' => 'zero',
                        'table' => 'zero_password_reset_tokens',
                    ],
                ],
            ],
        ]);
        $container->instance('auth', $auth = m::mock());
        $container->instance('db', $db = m::mock());
        $container->instance('hash', m::mock(Hasher::class));

        $auth->shouldReceive('createUserProvider')
            ->once()
            ->with('zero')
            ->andReturn(m::mock(UserProvider::class));
        $db->shouldReceive('connection')
            ->once()
            ->with(null)
            ->andReturn(m::mock(ConnectionInterface::class));

        $this->assertInstanceOf(PasswordBrokerContract::class, (new PasswordBrokerManager($container))->broker('0'));
    }

    public function testBrokerNormalizesEnumsBeforeCaching(): void
    {
        $broker = m::mock(PasswordBrokerContract::class);
        $manager = new AuthPasswordBrokerManagerStub(new Container);
        $manager->resolvedBroker = $broker;

        $this->assertSame($broker, $manager->broker(AuthPasswordBrokerIntEnum::Zero));
        $this->assertSame($broker, $manager->broker('0'));
        $this->assertSame(['0'], $manager->resolvedNames);
    }

    public function testRefreshingDispatcherUpdatesOnlyConcreteResolvedBrokers(): void
    {
        $manager = new AuthPasswordBrokerManagerStub(new Container);
        $concreteBroker = m::mock(PasswordBrokerImplementation::class);
        $customBroker = m::mock(PasswordBrokerContract::class);
        $events = m::mock(Dispatcher::class);
        $concreteBroker->shouldReceive('setDispatcher')->once()->with($events);
        $manager->seedBroker('concrete', $concreteBroker);
        $manager->seedBroker('custom', $customBroker);

        $manager->refreshEventDispatcher($events);

        $this->assertSame($customBroker, $manager->broker('custom'));
    }

    public function testBrokerFailsFastWhenAppKeyIsNotConfigured(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [app.key] must be a string, NULL given.');

        $container = new Container;
        $container->instance('config', new Repository([
            'app' => [
                'key' => null,
            ],
            'auth' => [
                'passwords' => [
                    'users' => [
                        'provider' => 'users',
                        'table' => 'password_reset_tokens',
                    ],
                ],
            ],
        ]));

        (new PasswordBrokerManager($container))->broker('users');
    }

    /**
     * Create a container with config.
     */
    private function makeContainer(array $config): Container
    {
        $container = new Container;
        $container->instance('config', new Repository($config));

        return $container;
    }

    /**
     * Create a mock auth factory returning the given default guard.
     */
    private function mockAuthFactory(string $guard): AuthFactory
    {
        $auth = m::mock(AuthFactory::class);
        $auth->shouldReceive('getDefaultDriver')->andReturn($guard)->byDefault();

        return $auth;
    }
}

class AuthPasswordBrokerManagerStub extends PasswordBrokerManager
{
    public PasswordBrokerContract $resolvedBroker;

    /** @var list<string> */
    public array $resolvedNames = [];

    /**
     * Seed a resolved broker.
     */
    public function seedBroker(string $name, PasswordBrokerContract $broker): void
    {
        $this->brokers[$name] = $broker;
    }

    /**
     * Resolve the configured broker stub.
     */
    protected function resolve(string $name): PasswordBrokerContract
    {
        $this->resolvedNames[] = $name;

        return $this->resolvedBroker;
    }
}

enum AuthPasswordBrokerStringEnum: string
{
    case Staff = 'staff';
}

enum AuthPasswordBrokerIntEnum: int
{
    case Zero = 0;
}

enum AuthPasswordBrokerUnitEnum
{
    case Api;
}
