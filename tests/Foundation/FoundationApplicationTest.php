<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\FoundationApplicationTest;

use Hypervel\Config\Repository;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Foundation\Events\LocaleUpdated;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FoundationApplicationTest extends TestCase
{
    public function testSetLocaleSetsLocaleAndFiresLocaleChangedEvent()
    {
        $trans = m::mock(stdClass::class);
        $trans->shouldReceive('getLocale')->once()->andReturn('bar');
        $trans->shouldReceive('setLocale')->once()->with('foo');
        $events = m::mock(stdClass::class);
        $events->shouldReceive('dispatch')->once()->with(m::on(function (LocaleUpdated $event) {
            return $event->locale === 'foo' && $event->previousLocale === 'bar';
        }));

        $app = new Application;
        $app->singleton('translator', fn () => $trans);
        $app->singleton('events', fn () => $events);

        $app->setLocale('foo');
    }

    public function testSetFallbackLocaleSetsTranslatorFallback()
    {
        $trans = m::mock(stdClass::class);
        $trans->shouldReceive('setFallback')->once()->with('fr');

        $app = new Application;
        $app->singleton('translator', fn () => $trans);

        $app->setFallbackLocale('fr');
    }

    public function testGetFallbackLocaleReadsFromTranslator()
    {
        $trans = m::mock(stdClass::class);
        $trans->shouldReceive('getFallback')->once()->andReturn('en');

        $app = new Application;
        $app->singleton('translator', fn () => $trans);

        $this->assertSame('en', $app->getFallbackLocale());
    }

    public function testServiceProvidersAreCorrectlyRegistered()
    {
        $provider = m::mock(ApplicationBasicServiceProviderStub::class);
        $class = get_class($provider);
        $provider->shouldReceive('isEnabled')->andReturn(true);
        $provider->shouldReceive('register')->once();
        $app = new Application;
        $app->register($provider);

        $this->assertArrayHasKey($class, $app->getLoadedProviders());
    }

    public function testClassesAreBoundWhenServiceProviderIsRegistered()
    {
        $app = new Application;
        $app->register($provider = new class($app) extends ServiceProvider {
            public $bindings = [
                AbstractClass::class => ConcreteClass::class,
            ];
        });

        $this->assertArrayHasKey(get_class($provider), $app->getLoadedProviders());

        $instance = $app->make(AbstractClass::class);

        $this->assertInstanceOf(ConcreteClass::class, $instance);
        $this->assertNotSame($instance, $app->make(AbstractClass::class));
    }

    public function testSingletonsAreCreatedWhenServiceProviderIsRegistered()
    {
        $app = new Application;
        $app->register($provider = new class($app) extends ServiceProvider {
            public $singletons = [
                NonContractBackedClass::class,
                AbstractClass::class => ConcreteClass::class,
            ];
        });

        $this->assertArrayHasKey(get_class($provider), $app->getLoadedProviders());

        $instance = $app->make(AbstractClass::class);

        $this->assertInstanceOf(ConcreteClass::class, $instance);
        $this->assertSame($instance, $app->make(AbstractClass::class));

        $instance = $app->make(NonContractBackedClass::class);

        $this->assertInstanceOf(NonContractBackedClass::class, $instance);
        $this->assertSame($instance, $app->make(NonContractBackedClass::class));
    }

    public function testServiceProvidersAreCorrectlyRegisteredWhenRegisterMethodIsNotFilled()
    {
        $provider = m::mock(ServiceProvider::class);
        $class = get_class($provider);
        $provider->shouldReceive('isEnabled')->andReturn(true);
        $provider->shouldReceive('register')->once();
        $app = new Application;
        $app->register($provider);

        $this->assertArrayHasKey($class, $app->getLoadedProviders());
    }

    public function testServiceProvidersCouldBeLoaded()
    {
        $provider = m::mock(ServiceProvider::class);
        $class = get_class($provider);
        $provider->shouldReceive('isEnabled')->andReturn(true);
        $provider->shouldReceive('register')->once();
        $app = new Application;
        $app->register($provider);

        $this->assertTrue($app->providerIsLoaded($class));
        $this->assertFalse($app->providerIsLoaded(ApplicationBasicServiceProviderStub::class));
    }

    public function testDisabledServiceProviderIsNotRegisteredOrTracked()
    {
        $app = new Application;
        $app->register($provider = new ApplicationDisabledServiceProviderStub($app));

        $this->assertArrayNotHasKey(get_class($provider), $app->getLoadedProviders());
        $this->assertFalse($app->providerIsLoaded(get_class($provider)));
        $this->assertNull($app->getProvider(get_class($provider)));
        $this->assertSame([], $app->getProviders(get_class($provider)));
    }

    public function testDisabledServiceProviderBindingsArrayIsSkipped()
    {
        $app = new Application;
        $app->register(new class($app) extends ServiceProvider {
            public $bindings = [
                AbstractClass::class => ConcreteClass::class,
            ];

            public function isEnabled(): bool
            {
                return false;
            }
        });

        $this->assertFalse($app->bound(AbstractClass::class));
    }

    public function testDisabledServiceProviderSingletonsArrayIsSkipped()
    {
        $app = new Application;
        $app->register(new class($app) extends ServiceProvider {
            public $singletons = [
                AbstractClass::class => ConcreteClass::class,
            ];

            public function isEnabled(): bool
            {
                return false;
            }
        });

        $this->assertFalse($app->bound(AbstractClass::class));
    }

    public function testDisabledServiceProviderIsNotBootedWhenAppAlreadyBooted()
    {
        $app = new Application;
        $app->boot();

        // boot() throws if called — passing the late-register branch without
        // the isEnabled() check would call bootProvider() and trigger it.
        $app->register(new ApplicationDisabledServiceProviderStub($app));

        $this->assertTrue($app->isBooted());
    }

    public function testEnvironment()
    {
        $app = new Application;
        $app->instance('env', 'foo');

        $this->assertSame('foo', $app->environment());

        $this->assertTrue($app->environment('foo'));
        $this->assertTrue($app->environment('f*'));
        $this->assertTrue($app->environment('foo', 'bar'));
        $this->assertTrue($app->environment(['foo', 'bar']));

        $this->assertFalse($app->environment('qux'));
        $this->assertFalse($app->environment('q*'));
        $this->assertFalse($app->environment('qux', 'bar'));
        $this->assertFalse($app->environment(['qux', 'bar']));
    }

    public function testEnvironmentHelpers()
    {
        $local = new Application;
        $local->instance('env', 'local');

        $this->assertTrue($local->isLocal());
        $this->assertFalse($local->isProduction());
        $this->assertFalse($local->runningUnitTests());

        $production = new Application;
        $production->instance('env', 'production');

        $this->assertTrue($production->isProduction());
        $this->assertFalse($production->isLocal());
        $this->assertFalse($production->runningUnitTests());

        $testing = new Application;
        $testing->instance('env', 'testing');

        $this->assertTrue($testing->runningUnitTests());
        $this->assertFalse($testing->isLocal());
        $this->assertFalse($testing->isProduction());
    }

    public function testDebugHelper()
    {
        $debugOff = new Application;
        $debugOff->instance('config', new Repository(['app' => ['debug' => false]]));

        $this->assertFalse($debugOff->hasDebugModeEnabled());

        $debugOn = new Application;
        $debugOn->instance('config', new Repository(['app' => ['debug' => true]]));

        $this->assertTrue($debugOn->hasDebugModeEnabled());
    }

    public function testBeforeBootstrappingAddsClosure()
    {
        $app = new Application;
        $eventDispatcher = new EventDispatcher($app);
        $app->instance('events', $eventDispatcher);

        $closure = function () {};
        $app->beforeBootstrapping(RegisterFacades::class, $closure);
        $this->assertArrayHasKey(0, $app['events']->getListeners('bootstrapping: Hypervel\Foundation\Bootstrap\RegisterFacades'));
    }

    public function testAfterBootstrappingAddsClosure()
    {
        $app = new Application;
        $eventDispatcher = new EventDispatcher($app);
        $app->instance('events', $eventDispatcher);

        $closure = function () {};
        $app->afterBootstrapping(RegisterFacades::class, $closure);
        $this->assertArrayHasKey(0, $app['events']->getListeners('bootstrapped: Hypervel\Foundation\Bootstrap\RegisterFacades'));
    }

    public function testTerminationTests()
    {
        $app = new Application;

        $result = [];
        $callback1 = function () use (&$result) {
            $result[] = 1;
        };

        $callback2 = function () use (&$result) {
            $result[] = 2;
        };

        $callback3 = function () use (&$result) {
            $result[] = 3;
        };

        $app->terminating($callback1);
        $app->terminating($callback2);
        $app->terminating($callback3);

        $app->terminate();

        $this->assertEquals([1, 2, 3], $result);
    }

    public function testTerminationCallbacksCanAcceptAtNotation()
    {
        $app = new Application;
        $app->terminating(ConcreteTerminator::class . '@terminate');

        $app->terminate();

        $this->assertEquals(1, ConcreteTerminator::$counter);
    }

    public function testBootingCallbacks()
    {
        $application = new Application;

        $counter = 0;
        $closure = function ($app) use (&$counter, $application) {
            ++$counter;
            $this->assertSame($application, $app);
        };

        $closure2 = function ($app) use (&$counter, $application) {
            ++$counter;
            $this->assertSame($application, $app);
        };

        $application->booting($closure);
        $application->booting($closure2);

        $application->boot();

        $this->assertEquals(2, $counter);
    }

    public function testBootedCallbacks()
    {
        $application = new Application;

        $counter = 0;
        $closure = function ($app) use (&$counter, $application) {
            ++$counter;
            $this->assertSame($application, $app);
        };

        $closure2 = function ($app) use (&$counter, $application) {
            ++$counter;
            $this->assertSame($application, $app);
        };

        $closure3 = function ($app) use (&$counter, $application) {
            ++$counter;
            $this->assertSame($application, $app);
        };

        $application->booting($closure);
        $application->booted($closure);
        $application->booted($closure2);
        $application->boot();

        $this->assertEquals(3, $counter);

        $application->booted($closure3);

        $this->assertEquals(4, $counter);
    }

    public function testGetNamespace()
    {
        $app1 = new Application(realpath(__DIR__ . '/Fixtures/project1'));
        $app2 = new Application(realpath(__DIR__ . '/Fixtures/project2'));

        $this->assertSame('App\One\\', $app1->getNamespace());
        $this->assertSame('App\Two\\', $app2->getNamespace());
    }

    public function testCachePathsResolveToBootstrapCacheDirectory()
    {
        $envKeys = ['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'];
        $saved = [];

        foreach ($envKeys as $key) {
            if (isset($_SERVER[$key])) {
                $saved[$key] = $_SERVER[$key];
                unset($_SERVER[$key]);
            }
        }

        try {
            $app = new Application('/base/path');

            $ds = DIRECTORY_SEPARATOR;
            $this->assertSame('/base/path' . $ds . 'bootstrap' . $ds . 'cache/config.php', $app->getCachedConfigPath());
            $this->assertSame('/base/path' . $ds . 'bootstrap' . $ds . 'cache/routes-v7.php', $app->getCachedRoutesPath());
            $this->assertSame('/base/path' . $ds . 'bootstrap' . $ds . 'cache/events.php', $app->getCachedEventsPath());
        } finally {
            foreach ($saved as $key => $value) {
                $_SERVER[$key] = $value;
            }
        }
    }

    public function testEnvPathsAreUsedForCachePathsWhenSpecified()
    {
        $app = new Application('/base/path');
        $_SERVER['APP_CONFIG_CACHE'] = '/absolute/path/config.php';
        $_SERVER['APP_ROUTES_CACHE'] = '/absolute/path/routes.php';
        $_SERVER['APP_EVENTS_CACHE'] = '/absolute/path/events.php';

        try {
            $this->assertSame('/absolute/path/config.php', $app->getCachedConfigPath());
            $this->assertSame('/absolute/path/routes.php', $app->getCachedRoutesPath());
            $this->assertSame('/absolute/path/events.php', $app->getCachedEventsPath());
        } finally {
            unset(
                $_SERVER['APP_CONFIG_CACHE'],
                $_SERVER['APP_ROUTES_CACHE'],
                $_SERVER['APP_EVENTS_CACHE'],
            );
        }
    }

    public function testEnvPathsAreUsedAndMadeAbsoluteForCachePathsWhenSpecifiedAsRelative()
    {
        $app = new Application('/base/path');
        $_SERVER['APP_CONFIG_CACHE'] = 'relative/path/config.php';
        $_SERVER['APP_ROUTES_CACHE'] = 'relative/path/routes.php';
        $_SERVER['APP_EVENTS_CACHE'] = 'relative/path/events.php';

        try {
            $ds = DIRECTORY_SEPARATOR;
            $this->assertSame('/base/path' . $ds . 'relative/path/config.php', $app->getCachedConfigPath());
            $this->assertSame('/base/path' . $ds . 'relative/path/routes.php', $app->getCachedRoutesPath());
            $this->assertSame('/base/path' . $ds . 'relative/path/events.php', $app->getCachedEventsPath());
        } finally {
            unset(
                $_SERVER['APP_CONFIG_CACHE'],
                $_SERVER['APP_ROUTES_CACHE'],
                $_SERVER['APP_EVENTS_CACHE'],
            );
        }
    }

    public function testEnvPathsAreUsedAndMadeAbsoluteForCachePathsWhenSpecifiedAsRelativeWithEmptyBasePath()
    {
        $app = new Application('');
        $_SERVER['APP_CONFIG_CACHE'] = 'relative/path/config.php';
        $_SERVER['APP_ROUTES_CACHE'] = 'relative/path/routes.php';
        $_SERVER['APP_EVENTS_CACHE'] = 'relative/path/events.php';

        try {
            $ds = DIRECTORY_SEPARATOR;
            $this->assertSame($ds . 'relative/path/config.php', $app->getCachedConfigPath());
            $this->assertSame($ds . 'relative/path/routes.php', $app->getCachedRoutesPath());
            $this->assertSame($ds . 'relative/path/events.php', $app->getCachedEventsPath());
        } finally {
            unset(
                $_SERVER['APP_CONFIG_CACHE'],
                $_SERVER['APP_ROUTES_CACHE'],
                $_SERVER['APP_EVENTS_CACHE'],
            );
        }
    }

    public function testEnvPathsAreUsedAndMadeAbsoluteForCachePathsWhenSpecifiedAsRelativeWithNullBasePath()
    {
        $app = new Application;
        $_SERVER['APP_CONFIG_CACHE'] = 'relative/path/config.php';
        $_SERVER['APP_ROUTES_CACHE'] = 'relative/path/routes.php';
        $_SERVER['APP_EVENTS_CACHE'] = 'relative/path/events.php';

        try {
            $ds = DIRECTORY_SEPARATOR;
            $this->assertSame($ds . 'relative/path/config.php', $app->getCachedConfigPath());
            $this->assertSame($ds . 'relative/path/routes.php', $app->getCachedRoutesPath());
            $this->assertSame($ds . 'relative/path/events.php', $app->getCachedEventsPath());
        } finally {
            unset(
                $_SERVER['APP_CONFIG_CACHE'],
                $_SERVER['APP_ROUTES_CACHE'],
                $_SERVER['APP_EVENTS_CACHE'],
            );
        }
    }

    public function testEnvPathsAreAbsoluteInWindows()
    {
        $app = new Application(__DIR__);
        $app->addAbsoluteCachePathPrefix('C:');
        $_SERVER['APP_CONFIG_CACHE'] = 'C:\framework\config.php';
        $_SERVER['APP_ROUTES_CACHE'] = 'C:\framework\routes.php';
        $_SERVER['APP_EVENTS_CACHE'] = 'C:\framework\events.php';

        try {
            $this->assertSame('C:\framework\config.php', $app->getCachedConfigPath());
            $this->assertSame('C:\framework\routes.php', $app->getCachedRoutesPath());
            $this->assertSame('C:\framework\events.php', $app->getCachedEventsPath());
        } finally {
            unset(
                $_SERVER['APP_CONFIG_CACHE'],
                $_SERVER['APP_ROUTES_CACHE'],
                $_SERVER['APP_EVENTS_CACHE'],
            );
        }
    }

    public function testMacroable()
    {
        $app = new Application;
        $app->macro('foo', function () {
            return 'bar';
        });

        $this->assertSame('bar', $app->foo());
    }

    public function testUseConfigPath()
    {
        $app = new Application;
        $app->useConfigPath(__DIR__ . '/Fixtures/config');
        $app->bootstrapWith([LoadConfiguration::class]);

        $this->assertSame('bar', $app->make('config')->get('app.foo'));
    }

    public function testMergingConfig()
    {
        $app = new Application;
        $app->useConfigPath(__DIR__ . '/Fixtures/config');
        $app->bootstrapWith([LoadConfiguration::class]);

        $config = $app->make('config');

        $this->assertSame('UTC', $config->get('app.timezone'));
        $this->assertSame('bar', $config->get('app.foo'));

        $this->assertSame('overwrite', $config->get('broadcasting.default'));
        $this->assertSame('broadcasting', $config->get('broadcasting.custom_option'));
        $this->assertIsArray($config->get('broadcasting.connections.pusher'));
        $this->assertSame(['overwrite' => true], $config->get('broadcasting.connections.reverb'));
        $this->assertSame(['merge' => true], $config->get('broadcasting.connections.new'));

        $this->assertSame('overwrite', $config->get('cache.default'));
        $this->assertSame('cache', $config->get('cache.custom_option'));
        $this->assertIsArray($config->get('cache.stores.database'));
        $this->assertSame(['overwrite' => true], $config->get('cache.stores.array'));
        $this->assertSame(['merge' => true], $config->get('cache.stores.new'));

        $this->assertSame('overwrite', $config->get('database.default'));
        $this->assertSame('database', $config->get('database.custom_option'));
        $this->assertIsArray($config->get('database.connections.pgsql'));
        $this->assertSame(['overwrite' => true], $config->get('database.connections.mysql'));
        $this->assertSame(['merge' => true], $config->get('database.connections.new'));

        $this->assertSame('overwrite', $config->get('filesystems.default'));
        $this->assertSame('filesystems', $config->get('filesystems.custom_option'));
        $this->assertIsArray($config->get('filesystems.disks.s3'));
        $this->assertSame(['overwrite' => true], $config->get('filesystems.disks.local'));
        $this->assertSame(['merge' => true], $config->get('filesystems.disks.new'));

        $this->assertSame('overwrite', $config->get('logging.default'));
        $this->assertSame('logging', $config->get('logging.custom_option'));
        $this->assertIsArray($config->get('logging.channels.single'));
        $this->assertSame(['overwrite' => true], $config->get('logging.channels.stack'));
        $this->assertSame(['merge' => true], $config->get('logging.channels.new'));

        $this->assertSame('overwrite', $config->get('mail.default'));
        $this->assertSame('mail', $config->get('mail.custom_option'));
        $this->assertIsArray($config->get('mail.mailers.ses'));
        $this->assertSame(['overwrite' => true], $config->get('mail.mailers.smtp'));
        $this->assertSame(['merge' => true], $config->get('mail.mailers.new'));

        $this->assertSame('overwrite', $config->get('queue.default'));
        $this->assertSame('queue', $config->get('queue.custom_option'));
        $this->assertIsArray($config->get('queue.connections.redis'));
        $this->assertSame(['overwrite' => true], $config->get('queue.connections.database'));
        $this->assertSame(['merge' => true], $config->get('queue.connections.new'));
    }

    protected function assertExpectationCount(int $times): void
    {
        $this->assertSame($times, m::getContainer()->mockery_getExpectationCount());
    }

    public function testAbortThrowsNotFoundHttpException()
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Page was not found');

        $app = new Application;
        $app->abort(404, 'Page was not found');
    }

    public function testAbortThrowsHttpException()
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Request is bad');

        $app = new Application;
        $app->abort(400, 'Request is bad');
    }

    public function testAbortAcceptsHeaders()
    {
        try {
            $app = new Application;
            $app->abort(400, 'Bad request', ['X-FOO' => 'BAR']);
            $this->fail(sprintf('abort must throw an %s.', HttpException::class));
        } catch (HttpException $exception) {
            $this->assertSame(['X-FOO' => 'BAR'], $exception->getHeaders());
        }
    }

    public function testMethodAfterLoadingEnvironmentAddsClosure()
    {
        $app = new Application;
        $eventDispatcher = new EventDispatcher($app);
        $app->instance('events', $eventDispatcher);

        $closure = function () {};
        $app->afterLoadingEnvironment($closure);

        $listeners = $app['events']->getListeners('bootstrapped: ' . LoadEnvironmentVariables::class);
        $this->assertArrayHasKey(0, $listeners);
    }

    public function testConfigurationIsCachedReturnsFalseWhenNoCacheFile()
    {
        $app = new Application(sys_get_temp_dir() . '/hypervel-test-app-' . uniqid());

        $this->assertFalse($app->configurationIsCached());
    }

    public function testConfigurationIsCachedReturnsTrueWhenCacheFileExists()
    {
        $basePath = sys_get_temp_dir() . '/hypervel-test-app-' . uniqid();
        $cachePath = $basePath . '/bootstrap/cache/config.php';

        mkdir(dirname($cachePath), 0755, true);
        file_put_contents($cachePath, '<?php return [];');

        try {
            $app = new Application($basePath);
            $this->assertTrue($app->configurationIsCached());
        } finally {
            unlink($cachePath);
            rmdir(dirname($cachePath));
            rmdir(dirname($cachePath, 2));
            rmdir($basePath);
        }
    }

    public function testRoutesAreCachedReturnsFalseWhenNoCacheFile()
    {
        $app = new Application(sys_get_temp_dir() . '/hypervel-test-app-' . uniqid());

        $this->assertFalse($app->routesAreCached());
    }

    public function testRoutesAreCachedReturnsTrueWhenCacheFileExists()
    {
        $basePath = sys_get_temp_dir() . '/hypervel-test-app-' . uniqid();
        $cachePath = $basePath . '/bootstrap/cache/routes-v7.php';

        mkdir(dirname($cachePath), 0755, true);
        file_put_contents($cachePath, '<?php return [];');

        try {
            $app = new Application($basePath);
            $this->assertTrue($app->routesAreCached());
        } finally {
            unlink($cachePath);
            rmdir(dirname($cachePath));
            rmdir(dirname($cachePath, 2));
            rmdir($basePath);
        }
    }

    public function testEventsAreCachedReturnsFalseWhenNoCacheFile()
    {
        $app = new Application(sys_get_temp_dir() . '/hypervel-test-app-' . uniqid());

        $this->assertFalse($app->eventsAreCached());
    }

    public function testEventsAreCachedReturnsTrueWhenCacheFileExists()
    {
        $basePath = sys_get_temp_dir() . '/hypervel-test-app-' . uniqid();
        $cachePath = $basePath . '/bootstrap/cache/events.php';

        mkdir(dirname($cachePath), 0755, true);
        file_put_contents($cachePath, '<?php return [];');

        try {
            $app = new Application($basePath);
            $this->assertTrue($app->eventsAreCached());
        } finally {
            unlink($cachePath);
            rmdir(dirname($cachePath));
            rmdir(dirname($cachePath, 2));
            rmdir($basePath);
        }
    }

    public function testCoreContainerAliasesAreRegisteredByDefault()
    {
        $app = new Application;

        $this->assertTrue($app->isAlias(\Hypervel\Contracts\Translation\Translator::class));
        $this->assertSame('translator', $app->getAlias(\Hypervel\Contracts\Translation\Translator::class));
        $this->assertTrue($app->isAlias(\Hypervel\Contracts\Auth\PasswordBrokerFactory::class));
        $this->assertSame('auth.password', $app->getAlias(\Hypervel\Contracts\Auth\PasswordBrokerFactory::class));
        $this->assertTrue($app->isAlias(\Hypervel\Contracts\Auth\PasswordBroker::class));
        $this->assertSame('auth.password.broker', $app->getAlias(\Hypervel\Contracts\Auth\PasswordBroker::class));
    }

    public function testAddAbsoluteCachePathPrefixReturnsSelf()
    {
        $app = new Application;

        $this->assertSame($app, $app->addAbsoluteCachePathPrefix('s3:'));
    }
}

class ApplicationBasicServiceProviderStub extends ServiceProvider
{
    public function boot()
    {
    }

    public function register(): void
    {
    }
}

class ApplicationDisabledServiceProviderStub extends ServiceProvider
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function register(): void
    {
        throw new RuntimeException('register() must not be called on a disabled provider');
    }

    public function boot(): void
    {
        throw new RuntimeException('boot() must not be called on a disabled provider');
    }
}

abstract class AbstractClass
{
}

class ConcreteClass extends AbstractClass
{
}

class NonContractBackedClass
{
}

class ConcreteTerminator
{
    public static int $counter = 0;

    public function terminate(): int
    {
        return self::$counter++;
    }
}
