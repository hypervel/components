<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use ArrayObject;
use Attribute;
use Hypervel\Auth\AuthManager;
use Hypervel\Auth\GenericUser;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Config\Repository;
use Hypervel\Container\Attributes\Auth;
use Hypervel\Container\Attributes\Authenticated;
use Hypervel\Container\Attributes\Cache;
use Hypervel\Container\Attributes\Config;
use Hypervel\Container\Attributes\Context;
use Hypervel\Container\Attributes\CurrentUser;
use Hypervel\Container\Attributes\Database;
use Hypervel\Container\Attributes\Give;
use Hypervel\Container\Attributes\Log;
use Hypervel\Container\Attributes\RequestAttribute;
use Hypervel\Container\Attributes\RouteParameter;
use Hypervel\Container\Attributes\Storage;
use Hypervel\Container\Attributes\Tag;
use Hypervel\Container\Container;
use Hypervel\Container\RewindableGenerator;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Auth\Guard as GuardContract;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Http\Request;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Log\Logger as HypervelLogger;
use Hypervel\Log\LogManager;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use ReflectionParameter;
use RuntimeException;
use TypeError;

use function Hypervel\Coroutine\parallel;

class ContextualAttributeBindingTest extends TestCase
{
    public function testDependencyCanBeResolvedFromAttributeBinding()
    {
        $container = new Container;

        $container->bind(ContainerTestContract::class, fn (): ContainerTestImplB => new ContainerTestImplB);
        $container->whenHasAttribute(ContainerTestAttributeThatResolvesContractImpl::class, function (ContainerTestAttributeThatResolvesContractImpl $attribute) {
            return match ($attribute->name) {
                'A' => new ContainerTestImplA,
                'B' => new ContainerTestImplB,
            };
        });

        $classA = $container->make(ContainerTestHasAttributeThatResolvesToImplA::class);

        $this->assertInstanceOf(ContainerTestHasAttributeThatResolvesToImplA::class, $classA);
        $this->assertInstanceOf(ContainerTestImplA::class, $classA->property);

        $classB = $container->make(ContainerTestHasAttributeThatResolvesToImplB::class);

        $this->assertInstanceOf(ContainerTestHasAttributeThatResolvesToImplB::class, $classB);
        $this->assertInstanceOf(ContainerTestImplB::class, $classB->property);
    }

    public function testSimpleDependencyCanBeResolvedCorrectlyFromGiveAttributeBinding()
    {
        $container = new Container;

        $container->bind(ContainerTestContract::class, concrete: ContainerTestImplA::class);

        $resolution = $container->make(GiveTestSimple::class);

        $this->assertInstanceOf(SimpleDependency::class, $resolution->dependency);
    }

    public function testComplexDependencyCanBeResolvedCorrectlyFromGiveAttributeBinding()
    {
        $container = new Container;

        $container->bind(ContainerTestContract::class, concrete: ContainerTestImplA::class);

        $resolution = $container->make(GiveTestComplex::class);

        $this->assertInstanceOf(ComplexDependency::class, $resolution->dependency);
        $this->assertTrue($resolution->dependency->param);
    }

    public function testScalarDependencyCanBeResolvedFromAttributeBinding()
    {
        $container = new Container;
        $container->singleton('config', fn () => new Repository([
            'app' => [
                'timezone' => 'Europe/Paris',
            ],
        ]));

        $container->whenHasAttribute(ContainerTestConfigValue::class, function (ContainerTestConfigValue $attribute, Container $container) {
            return $container->make('config')->get($attribute->key);
        });

        $class = $container->make(ContainerTestHasConfigValueProperty::class);

        $this->assertInstanceOf(ContainerTestHasConfigValueProperty::class, $class);
        $this->assertEquals('Europe/Paris', $class->timezone);
    }

    public function testScalarDependencyCanBeResolvedFromAttributeResolveMethod()
    {
        $container = new Container;
        $container->singleton('config', fn () => new Repository([
            'app' => [
                'env' => 'production',
            ],
        ]));

        $class = $container->make(ContainerTestHasConfigValueWithResolveProperty::class);

        $this->assertInstanceOf(ContainerTestHasConfigValueWithResolveProperty::class, $class);
        $this->assertEquals('production', $class->env);
    }

    public function testDependencyWithAfterCallbackAttributeCanBeResolved()
    {
        $container = new Container;

        $class = $container->make(ContainerTestHasConfigValueWithResolvePropertyAndAfterCallback::class);

        $this->assertEquals('Developer', $class->person->role);
    }

    public function testAuthedAttribute()
    {
        $container = new Container;
        $container->singleton('auth', function () {
            $manager = m::mock(AuthManager::class);
            $manager->shouldReceive('userResolver')->andReturn(fn ($guard = null) => $manager->guard($guard)->user());
            $manager->shouldReceive('guard')->with('foo')->andReturnUsing(function () {
                $guard = m::mock(GuardContract::class);
                $guard->shouldReceive('user')->andReturn(m::mock(AuthenticatableContract::class));

                return $guard;
            });
            $manager->shouldReceive('guard')->with('bar')->andReturnUsing(function () {
                $guard = m::mock(GuardContract::class);
                $guard->shouldReceive('user')->andReturn(m::mock(AuthenticatableContract::class));

                return $guard;
            });
            $manager->shouldReceive('guard')->with(AuthGuardUnitEnum::unit)->andReturnUsing(function () {
                $guard = m::mock(GuardContract::class);
                $guard->shouldReceive('user')->andReturn(m::mock(AuthenticatableContract::class));

                return $guard;
            });
            $manager->shouldReceive('guard')->with(AuthGuardBackedEnum::Backed)->andReturnUsing(function () {
                $guard = m::mock(GuardContract::class);
                $guard->shouldReceive('user')->andReturn(m::mock(AuthenticatableContract::class));

                return $guard;
            });

            return $manager;
        });

        $this->assertTrue($container->isScoped(AuthedTest::class));

        $container->make(AuthedTest::class);
    }

    public function testAuthenticatedAttributesCanExtractPropertyPaths(): void
    {
        $container = new Container;
        $user = new GenericUser([
            'id' => 10,
            'profile' => ['id' => 20],
        ]);

        $manager = m::mock(AuthManager::class);
        $manager->shouldReceive('userResolver')->twice()->andReturn(fn () => $user);
        $container->singleton('auth', fn () => $manager);

        $resolved = $container->make(AuthenticatedPropertyTest::class);

        $this->assertTrue($container->isScoped(AuthenticatedPropertyTest::class));
        $this->assertSame(10, $resolved->userId);
        $this->assertSame(20, $resolved->profileId);
    }

    public function testAuthenticatedNullIsAuthoritativeForMakeAndCall(): void
    {
        $container = new Container;
        $manager = m::mock(AuthManager::class);
        $manager->shouldReceive('userResolver')->times(3)->andReturn(fn () => null);
        $container->singleton('auth', fn () => $manager);

        $withoutDefault = $container->make(NullableAuthenticatedWithoutDefault::class);
        $withDefault = $container->make(NullableAuthenticatedWithDefault::class);
        $called = $container->call(
            fn (#[Authenticated] ?AuthenticatableContract $user): ?AuthenticatableContract => $user,
        );

        $this->assertNull($withoutDefault->user);
        $this->assertNull($withDefault->user);
        $this->assertNull($called);
    }

    public function testCacheAttribute()
    {
        $container = new Container;
        $container->singleton('cache', function () {
            $manager = m::mock(CacheManager::class);
            $manager->shouldReceive('store')->with('foo')->andReturn(m::mock(CacheRepository::class));
            $manager->shouldReceive('store')->with('bar')->andReturn(m::mock(CacheRepository::class));
            $manager->shouldReceive('store')->with(CacheStoreUnitEnum::unit)->andReturn(m::mock(CacheRepository::class));
            $manager->shouldReceive('store')->with(CacheStoreBackedEnum::Backed)->andReturn(m::mock(CacheRepository::class));
            $manager->shouldReceive('store')->with(CacheStoreIntegerBackedEnum::Zero)->andReturn(m::mock(CacheRepository::class));
            $manager->shouldReceive('memo')->with('foo')->andReturn(m::mock(CacheRepository::class));
            $manager->shouldReceive('memo')->with('bar')->andReturn(m::mock(CacheRepository::class));

            return $manager;
        });

        $this->assertTrue($container->isScoped(CacheTest::class));
        $this->assertFalse($container->isScoped(OrdinaryCacheTest::class));

        $container->make(CacheTest::class);
    }

    public function testConfigAttribute()
    {
        $container = new Container;
        $container->singleton('config', function () {
            $repository = m::mock(Repository::class);
            $repository->shouldReceive('get')->with('foo', null)->andReturn('foo');
            $repository->shouldReceive('get')->with('bar', null)->andReturn('bar');

            return $repository;
        });

        $container->make(ConfigTest::class);
    }

    public function testDatabaseAttribute()
    {
        $container = new Container;
        $container->singleton('db', function () {
            $manager = m::mock(DatabaseManager::class);
            $manager->shouldReceive('connection')->with('foo')->andReturn(m::mock(ConnectionInterface::class));
            $manager->shouldReceive('connection')->with('bar')->andReturn(m::mock(ConnectionInterface::class));
            $manager->shouldReceive('connection')->with(DatabaseConnectionIntegerBackedEnum::Zero)->andReturn(m::mock(ConnectionInterface::class));

            return $manager;
        });

        $this->assertTrue($container->isScoped(DatabaseTest::class));

        $container->make(DatabaseTest::class);
    }

    public function testAuthAttribute()
    {
        $container = new Container;
        $container->singleton('auth', function () {
            $manager = m::mock(AuthManager::class);
            $manager->shouldReceive('guard')->with('foo')->andReturn(m::mock(GuardContract::class));
            $manager->shouldReceive('guard')->with('bar')->andReturn(m::mock(GuardContract::class));
            $manager->shouldReceive('guard')->with(AuthGuardUnitEnum::unit)->andReturn(m::mock(GuardContract::class));
            $manager->shouldReceive('guard')->with(AuthGuardBackedEnum::Backed)->andReturn(m::mock(GuardContract::class));
            $manager->shouldReceive('guard')->with(AuthGuardIntegerBackedEnum::Zero)->andReturn(m::mock(GuardContract::class));

            return $manager;
        });

        $container->make(GuardTest::class);
    }

    public function testLogAttribute()
    {
        $container = new Container;
        $container->singleton('log', function () {
            $manager = m::mock(LogManager::class);
            $manager->shouldReceive('channel')->with('foo')->andReturn(m::mock(LoggerInterface::class));
            $manager->shouldReceive('channel')->with('bar')->andReturn(m::mock(LoggerInterface::class));
            $manager->shouldReceive('channel')->with('unit_channel')->andReturn(m::mock(LoggerInterface::class));
            $manager->shouldReceive('channel')->with('7')->andReturn(m::mock(LoggerInterface::class));

            $named = m::mock(HypervelLogger::class);
            $named->shouldReceive('withName')->with('tenant')->andReturn(m::mock(HypervelLogger::class));
            $manager->shouldReceive('channel')->with('named')->andReturn($named);

            $integerNamed = m::mock(HypervelLogger::class);
            $integerNamed->shouldReceive('withName')->with('9')->andReturn(m::mock(HypervelLogger::class));
            $manager->shouldReceive('channel')->with('integer-named')->andReturn($integerNamed);

            return $manager;
        });

        $container->make(LogTest::class);
    }

    public function testNamedLogAttributeRejectsCustomNonMonologDriver(): void
    {
        $container = $this->app;
        $container->make('config')->set('logging.channels.custom', ['driver' => 'custom']);

        $manager = new LogManager($container);
        $manager->extend('custom', fn () => m::mock(LoggerInterface::class));
        $container->instance('log', $manager);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Named loggers are only supported by Monolog drivers.');

        $container->make(CustomNamedLogTest::class);
    }

    public function testRouteParameterAttribute()
    {
        $container = new Container;
        $container->singleton('request', function () {
            $request = m::mock(Request::class);
            $request->shouldReceive('route')->with('foo')->andReturn(m::mock(Model::class));
            $request->shouldReceive('route')->with('bar')->andReturn('bar');

            return $request;
        });

        $this->assertTrue($container->isScoped(RouteParameterTest::class));

        $container->make(RouteParameterTest::class);
    }

    public function testRouteParameterAttributeWithoutParameterName(): void
    {
        $container = new Container;
        $container->singleton('request', function () {
            $request = m::mock(Request::class);
            $request->shouldReceive('route')->with('foo')->andReturn(m::mock(Model::class));
            $request->shouldReceive('route')->with('bar')->andReturn('bar');

            return $request;
        });

        $container->make(RouteParameterTestWithoutParameterName::class);
    }

    public function testRouteParameterCanExtractSupportedPropertyPaths(): void
    {
        $container = new Container;
        $model = new ContextualRouteModel;
        $model->setAttribute('id', 40);

        $request = m::mock(Request::class);
        $request->shouldReceive('route')->with('array')->andReturn(['nested' => ['id' => 10]]);
        $request->shouldReceive('route')->with('array-access')->andReturn(new ArrayObject(['id' => 20]));
        $request->shouldReceive('route')->with('object')->andReturn((object) ['nested' => (object) ['id' => 30]]);
        $request->shouldReceive('route')->with('model')->andReturn($model);
        $request->shouldReceive('route')->with('missing')->andReturn(['other' => 50]);
        $request->shouldReceive('route')->with('null')->andReturnNull();
        $container->singleton('request', fn () => $request);

        $resolved = $container->make(RouteParameterPropertyTest::class);

        $this->assertTrue($container->isScoped(RouteParameterPropertyTest::class));
        $this->assertSame(10, $resolved->arrayId);
        $this->assertSame(20, $resolved->arrayAccessId);
        $this->assertSame(30, $resolved->objectId);
        $this->assertSame(40, $resolved->modelId);
        $this->assertNull($resolved->missingId);
        $this->assertNull($resolved->nullId);
    }

    public function testRouteParameterPropertyRejectsScalarValues(): void
    {
        $container = new Container;
        $request = m::mock(Request::class);
        $request->shouldReceive('route')->with('post')->andReturn(123);
        $container->singleton('request', fn () => $request);

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessageIs('Cannot extract property path [id] from scalar [int] resolved by [Hypervel\Container\Attributes\RouteParameter].');

        $container->make(ScalarRouteParameterPropertyTest::class);
    }

    public function testMissingRouteParameterDoesNotConstructAnEmptyModel(): void
    {
        $container = new Container;
        $request = m::mock(Request::class);
        $request->shouldReceive('route')->with('post')->andReturnNull();
        $container->singleton('request', fn () => $request);

        $this->expectException(TypeError::class);

        $container->make(MissingRouteModelTest::class);
    }

    public function testRequestAttributeResolvesSelectedBagValue(): void
    {
        $container = new Container;
        $request = Request::create('/');
        $request->attributes->set('tenant', 'acme');
        $container->singleton('request', fn () => $request);

        $this->assertTrue($container->isScoped(RequestAttributeTest::class));
        $this->assertSame('acme', $container->make(RequestAttributeTest::class)->tenant);
    }

    public function testRouteAndAuthenticatedPropertyExtractionIsIsolatedBetweenExecutions(): void
    {
        $auth = $this->app->make('auth');

        $resolve = function (string $routeId, int $userId) use ($auth): array {
            $request = Request::create('/');
            $request->setRouteResolver(fn () => new ContextualAttributeTestRoute([
                'team' => (object) ['id' => $routeId],
            ]));
            RequestContext::set($request);
            $auth->resolveUsersUsing(fn () => new GenericUser(['id' => $userId]));

            usleep(5000);

            $resolved = $this->app->make(InterleavedContextualPropertyTest::class);

            return [$resolved->routeId, $resolved->userId];
        };

        $results = parallel([
            fn () => $resolve('route-a', 10),
            fn () => $resolve('route-b', 20),
        ]);

        $this->assertSame([
            ['route-a', 10],
            ['route-b', 20],
        ], $results);
    }

    public function testContextAttribute()
    {
        $container = new Container;

        ContextRepository::getInstance()->add('foo', 'foo');

        $this->assertTrue($container->isScoped(ContextTest::class));

        $instance = $container->make(ContextTest::class);

        $this->assertSame('foo', $instance->foo);
    }

    public function testContextAttributeInteractingWithHidden()
    {
        $container = new Container;

        ContextRepository::getInstance()->addHidden('bar', 'bar');

        $instance = $container->make(ContextHiddenTest::class);

        $this->assertSame('bar', $instance->foo);
    }

    public function testStorageAttribute()
    {
        $container = new Container;
        $container->singleton('filesystem', function () {
            $manager = m::mock(FilesystemManager::class);
            $manager->shouldReceive('disk')->with('foo')->andReturn(m::mock(Filesystem::class));
            $manager->shouldReceive('disk')->with('bar')->andReturn(m::mock(Filesystem::class));
            $manager->shouldReceive('disk')->with(StorageDiskUnitEnum::unit)->andReturn(m::mock(Filesystem::class));
            $manager->shouldReceive('disk')->with(StorageDiskBackedEnum::Backed)->andReturn(m::mock(Filesystem::class));
            $manager->shouldReceive('disk')->with(StorageDiskIntegerBackedEnum::Zero)->andReturn(m::mock(Filesystem::class));

            return $manager;
        });

        $container->make(StorageTest::class);
    }

    public function testInjectionWithAttributeOnAppCall()
    {
        $container = new Container;

        $person = $container->call(function (ContainerTestHasConfigValueWithResolvePropertyAndAfterCallback $hasAttribute) {
            return $hasAttribute->person;
        });

        $this->assertEquals('Taylor', $person->name);
    }

    public function testAttributeOnAppCall()
    {
        $container = new Container;
        $container->singleton('config', fn () => new Repository([
            'app' => [
                'timezone' => 'Europe/Paris',
                'locale' => null,
            ],
        ]));

        $value = $container->call(function (#[Config('app.timezone')] string $value) {
            return $value;
        });

        $this->assertEquals('Europe/Paris', $value);

        $value = $container->call(function (#[Config('app.locale')] ?string $value) {
            return $value;
        });

        $this->assertNull($value);
    }

    public function testNestedAttributeOnAppCall()
    {
        $container = new Container;
        $container->singleton('config', fn () => new Repository([
            'app' => [
                'timezone' => 'Europe/Paris',
                'locale' => null,
            ],
        ]));

        $value = $container->call(function (TimezoneObject $object) {
            return $object;
        });

        $this->assertEquals('Europe/Paris', $value->timezone);

        $value = $container->call(function (LocaleObject $object) {
            return $object;
        });

        $this->assertNull($value->locale);
    }

    public function testTagAttribute()
    {
        $container = new Container;
        $container->bind('one', fn (): int => 1);
        $container->bind('two', fn (): int => 2);
        $container->tag(['one', 'two'], 'numbers');

        $value = $container->call(function (#[Tag('numbers')] RewindableGenerator $integers) {
            return $integers;
        });

        $this->assertEquals([1, 2], iterator_to_array($value));
    }

    public function testParameterIsPassedToContextualAttributeResolver(): void
    {
        $container = new Container;

        $value = $container->make(HasParameterAwareAttribute::class);

        $this->assertSame('name', $value->name);
    }

    public function testParameterIsPassedToContextualAttributeResolverOnAppCall(): void
    {
        $container = new Container;

        $value = $container->call(function (
            #[ContainerTestParameterAwareAttribute]
            ?string $name
        ) {
            return $name;
        });

        $this->assertSame('name', $value);
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
class ContainerTestAttributeThatResolvesContractImpl implements ContextualAttribute
{
    public function __construct(
        public readonly string $name
    ) {
    }
}

enum StorageDiskUnitEnum
{
    case unit;
}

enum StorageDiskBackedEnum: string
{
    case Backed = 'backed';
}

enum StorageDiskIntegerBackedEnum: int
{
    case Zero = 0;
}

enum AuthGuardUnitEnum
{
    case unit;
}

enum AuthGuardBackedEnum: string
{
    case Backed = 'backed';
}

enum AuthGuardIntegerBackedEnum: int
{
    case Zero = 0;
}

enum CacheStoreUnitEnum
{
    case unit;
}

enum CacheStoreBackedEnum: string
{
    case Backed = 'backed';
}

enum CacheStoreIntegerBackedEnum: int
{
    case Zero = 0;
}

enum DatabaseConnectionIntegerBackedEnum: int
{
    case Zero = 0;
}

enum LogChannelUnitEnum
{
    case unit_channel;
}

enum LogChannelBackedEnum: int
{
    case Backed = 7;
}

enum LogNameUnitEnum
{
    case tenant;
}

enum LogNameBackedEnum: int
{
    case Backed = 9;
}

interface ContainerTestContract
{
}

final class ContainerTestImplA implements ContainerTestContract
{
}

final class ContainerTestImplB implements ContainerTestContract
{
}

final class ContainerTestHasAttributeThatResolvesToImplA
{
    public function __construct(
        #[ContainerTestAttributeThatResolvesContractImpl('A')]
        public readonly ContainerTestContract $property
    ) {
    }
}

final class ContainerTestHasAttributeThatResolvesToImplB
{
    public function __construct(
        #[ContainerTestAttributeThatResolvesContractImpl('B')]
        public readonly ContainerTestContract $property
    ) {
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ContainerTestConfigValue implements ContextualAttribute
{
    public function __construct(
        public readonly string $key
    ) {
    }
}

final class ContainerTestHasConfigValueProperty
{
    public function __construct(
        #[ContainerTestConfigValue('app.timezone')]
        public string $timezone
    ) {
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ContainerTestConfigValueWithResolve implements ContextualAttribute
{
    public function __construct(
        public readonly string $key
    ) {
    }

    public function resolve(self $attribute, Container $container): string
    {
        return $container->make('config')->get($attribute->key);
    }
}

final class ContainerTestHasConfigValueWithResolveProperty
{
    public function __construct(
        #[ContainerTestConfigValueWithResolve('app.env')]
        public string $env
    ) {
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ContainerTestConfigValueWithResolveAndAfter implements ContextualAttribute
{
    public function resolve(self $attribute, Container $container): object
    {
        return (object) ['name' => 'Taylor'];
    }

    public function after(self $attribute, object $value, Container $container): void
    {
        $value->role = 'Developer';
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ContainerTestParameterAwareAttribute implements ContextualAttribute
{
    public function resolve(self $attribute, Container $container, ReflectionParameter $parameter): string
    {
        return $parameter->getName();
    }
}

final class ContainerTestHasConfigValueWithResolvePropertyAndAfterCallback
{
    public function __construct(
        #[ContainerTestConfigValueWithResolveAndAfter]
        public object $person
    ) {
    }
}

final class SimpleDependency implements ContainerTestContract
{
}

final class ComplexDependency implements ContainerTestContract
{
    public function __construct(public bool $param)
    {
    }
}

final class AuthedTest
{
    public function __construct(
        #[Authenticated('foo')]
        AuthenticatableContract $foo,
        #[CurrentUser('bar')]
        AuthenticatableContract $bar,
        #[Authenticated(AuthGuardUnitEnum::unit)]
        AuthenticatableContract $unit,
        #[CurrentUser(AuthGuardBackedEnum::Backed)]
        AuthenticatableContract $backed,
    ) {
    }
}

final class AuthenticatedPropertyTest
{
    public function __construct(
        #[Authenticated(property: 'id')]
        public int $userId,
        #[CurrentUser(property: 'profile.id')]
        public int $profileId,
    ) {
    }
}

final class NullableAuthenticatedWithoutDefault
{
    public function __construct(#[Authenticated] public ?AuthenticatableContract $user)
    {
    }
}

final class NullableAuthenticatedWithDefault
{
    public function __construct(#[Authenticated] public ?AuthenticatableContract $user = null)
    {
    }
}

final class CacheTest
{
    public function __construct(
        #[Cache('foo')]
        CacheRepository $foo,
        #[Cache('bar')]
        CacheRepository $bar,
        #[Cache(CacheStoreUnitEnum::unit)]
        CacheRepository $unit,
        #[Cache(CacheStoreBackedEnum::Backed)]
        CacheRepository $backed,
        #[Cache(CacheStoreIntegerBackedEnum::Zero)]
        CacheRepository $integerBacked,
        #[Cache('foo', memo: true)]
        CacheRepository $fooMemoized,
        #[Cache('bar', memo: true)]
        CacheRepository $barMemoized,
    ) {
    }
}

final class OrdinaryCacheTest
{
    public function __construct(#[Cache('foo')] CacheRepository $cache)
    {
    }
}

final class ConfigTest
{
    public function __construct(#[Config('foo')] string $foo, #[Config('bar')] string $bar)
    {
    }
}

final class ContextTest
{
    public function __construct(#[Context('foo')] public string $foo)
    {
    }
}

final class ContextHiddenTest
{
    public function __construct(#[Context('bar', hidden: true)] public string $foo)
    {
    }
}

final class DatabaseTest
{
    public function __construct(
        #[Database('foo')]
        ConnectionInterface $foo,
        #[Database('bar')]
        ConnectionInterface $bar,
        #[Database(DatabaseConnectionIntegerBackedEnum::Zero)]
        ConnectionInterface $integerBacked,
    ) {
    }
}

final class GuardTest
{
    public function __construct(
        #[Auth('foo')]
        GuardContract $foo,
        #[Auth('bar')]
        GuardContract $bar,
        #[Auth(AuthGuardUnitEnum::unit)]
        GuardContract $unit,
        #[Auth(AuthGuardBackedEnum::Backed)]
        GuardContract $backed,
        #[Auth(AuthGuardIntegerBackedEnum::Zero)]
        GuardContract $integerBacked,
    ) {
    }
}

final class LogTest
{
    public function __construct(
        #[Log('foo')]
        LoggerInterface $foo,
        #[Log('bar')]
        LoggerInterface $bar,
        #[Log(LogChannelUnitEnum::unit_channel)]
        LoggerInterface $unit,
        #[Log(LogChannelBackedEnum::Backed)]
        LoggerInterface $backed,
        #[Log('named', LogNameUnitEnum::tenant)]
        LoggerInterface $named,
        #[Log('integer-named', LogNameBackedEnum::Backed)]
        LoggerInterface $integerNamed,
    ) {
    }
}

final class CustomNamedLogTest
{
    public function __construct(#[Log('custom', 'tenant')] LoggerInterface $logger)
    {
    }
}

final class RouteParameterTest
{
    public function __construct(#[RouteParameter('foo')] Model $foo, #[RouteParameter('bar')] string $bar)
    {
    }
}

final class RouteParameterTestWithoutParameterName
{
    public function __construct(#[RouteParameter] Model $foo, #[RouteParameter] string $bar)
    {
    }
}

final class RouteParameterPropertyTest
{
    public function __construct(
        #[RouteParameter('array', 'nested.id')]
        public int $arrayId,
        #[RouteParameter('array-access', 'id')]
        public int $arrayAccessId,
        #[RouteParameter('object', 'nested.id')]
        public int $objectId,
        #[RouteParameter('model', 'id')]
        public int $modelId,
        #[RouteParameter('missing', 'id')]
        public ?int $missingId,
        #[RouteParameter('null', 'id')]
        public ?int $nullId,
    ) {
    }
}

final class ScalarRouteParameterPropertyTest
{
    public function __construct(#[RouteParameter('post', 'id')] public ?int $postId)
    {
    }
}

final class ContextualRouteModel extends Model
{
}

final class MissingRouteModelTest
{
    public function __construct(#[RouteParameter('post')] public ContextualRouteModel $post)
    {
    }
}

final class RequestAttributeTest
{
    public function __construct(#[RequestAttribute('tenant')] public string $tenant)
    {
    }
}

final class InterleavedContextualPropertyTest
{
    public function __construct(
        #[RouteParameter('team', 'id')]
        public string $routeId,
        #[CurrentUser(property: 'id')]
        public int $userId,
    ) {
    }
}

final class ContextualAttributeTestRoute
{
    public function __construct(private array $parameters)
    {
    }

    public function hasParameters(): bool
    {
        return true;
    }

    public function parameter(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }
}

final class StorageTest
{
    public function __construct(
        #[Storage('foo')]
        Filesystem $foo,
        #[Storage('bar')]
        Filesystem $bar,
        #[Storage(StorageDiskUnitEnum::unit)]
        Filesystem $unit,
        #[Storage(StorageDiskBackedEnum::Backed)]
        Filesystem $backed,
        #[Storage(StorageDiskIntegerBackedEnum::Zero)]
        Filesystem $integerBacked,
    ) {
    }
}

final class GiveTestSimple
{
    public function __construct(
        #[Give(SimpleDependency::class)]
        public readonly ContainerTestContract $dependency
    ) {
    }
}

final class GiveTestComplex
{
    public function __construct(
        #[Give(ComplexDependency::class, ['param' => true])]
        public readonly ContainerTestContract $dependency
    ) {
    }
}

final class TimezoneObject
{
    public function __construct(
        #[Config('app.timezone')]
        public readonly ?string $timezone
    ) {
    }
}

final class LocaleObject
{
    public function __construct(
        #[Config('app.locale')]
        public readonly ?string $locale
    ) {
    }
}

final class HasParameterAwareAttribute
{
    public function __construct(
        #[ContainerTestParameterAwareAttribute]
        public readonly ?string $name,
    ) {
    }
}
