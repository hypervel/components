<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Config\Repository;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Foundation\Bootstrap\RegisterFacades;
use Hypervel\Foundation\Events\LocaleUpdated;
use Hypervel\Support\ServiceProvider;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @internal
 * @coversNothing
 */
class FoundationApplicationTest extends TestCase
{
    use HasMockedApplication;

    public function testSetLocaleSetsLocaleAndFiresLocaleChangedEvent()
    {
        $trans = m::mock(stdClass::class);
        $trans->shouldReceive('getLocale')->once()->andReturn('bar');
        $trans->shouldReceive('setLocale')->once()->with('foo');
        $events = m::mock(stdClass::class);
        $events->shouldReceive('dispatch')->once()->with(m::on(function (LocaleUpdated $event) {
            return $event->locale === 'foo' && $event->previousLocale === 'bar';
        }));

        $app = $this->getApplication([
            'translator' => fn () => $trans,
            'events' => fn () => $events,
        ]);

        $app->setLocale('foo');
    }

    public function testSetFallbackLocaleSetsTranslatorFallback()
    {
        $trans = m::mock(stdClass::class);
        $trans->shouldReceive('setFallback')->once()->with('fr');

        $app = $this->getApplication([
            'translator' => fn () => $trans,
        ]);

        $app->setFallbackLocale('fr');
    }

    public function testGetFallbackLocaleReadsFromTranslator()
    {
        $trans = m::mock(stdClass::class);
        $trans->shouldReceive('getFallback')->once()->andReturn('en');

        $app = $this->getApplication([
            'translator' => fn () => $trans,
        ]);

        $this->assertSame('en', $app->getFallbackLocale());
    }

    public function testServiceProvidersAreCorrectlyRegistered()
    {
        $provider = m::mock(ApplicationBasicServiceProviderStub::class);
        $class = get_class($provider);
        $provider->shouldReceive('register')->once();
        $app = $this->getApplication();
        $app->register($provider);

        $this->assertArrayHasKey($class, $app->getLoadedProviders());
    }

    public function testClassesAreBoundWhenServiceProviderIsRegistered()
    {
        $app = $this->getApplication();
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

    public function testServiceProvidersAreCorrectlyRegisteredWhenRegisterMethodIsNotFilled()
    {
        $provider = m::mock(ServiceProvider::class);
        $class = get_class($provider);
        $provider->shouldReceive('register')->once();
        $app = $this->getApplication();
        $app->register($provider);

        $this->assertArrayHasKey($class, $app->getLoadedProviders());
    }

    public function testServiceProvidersCouldBeLoaded()
    {
        $provider = m::mock(ServiceProvider::class);
        $class = get_class($provider);
        $provider->shouldReceive('register')->once();
        $app = $this->getApplication();
        $app->register($provider);

        $this->assertTrue($app->providerIsLoaded($class));
        $this->assertFalse($app->providerIsLoaded(ApplicationBasicServiceProviderStub::class));
    }

    public function testEnvironment()
    {
        $app = $this->getApplication();
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
        $local = $this->getApplication();
        $local->instance('env', 'local');

        $this->assertTrue($local->isLocal());
        $this->assertFalse($local->isProduction());
        $this->assertFalse($local->runningUnitTests());

        $production = $this->getApplication();
        $production->instance('env', 'production');

        $this->assertTrue($production->isProduction());
        $this->assertFalse($production->isLocal());
        $this->assertFalse($production->runningUnitTests());

        $testing = $this->getApplication();
        $testing->instance('env', 'testing');

        $this->assertTrue($testing->runningUnitTests());
        $this->assertFalse($testing->isLocal());
        $this->assertFalse($testing->isProduction());
    }

    public function testDebugHelper()
    {
        $debugOff = $this->getApplication();
        $debugOff->instance('config', new Repository(['app' => ['debug' => false]]));

        $this->assertFalse($debugOff->hasDebugModeEnabled());

        $debugOn = $this->getApplication();
        $debugOn->instance('config', new Repository(['app' => ['debug' => true]]));

        $this->assertTrue($debugOn->hasDebugModeEnabled());
    }

    public function testBeforeBootstrappingAddsClosure()
    {
        $app = $this->getApplication();
        $eventDispatcher = new EventDispatcher($app);
        $app->instance('events', $eventDispatcher);

        $closure = function () {};
        $app->beforeBootstrapping(RegisterFacades::class, $closure);
        $this->assertArrayHasKey(0, $app['events']->getListeners('bootstrapping: Hypervel\Foundation\Bootstrap\RegisterFacades'));
    }

    public function testAfterBootstrappingAddsClosure()
    {
        $app = $this->getApplication();
        $eventDispatcher = new EventDispatcher($app);
        $app->instance('events', $eventDispatcher);

        $closure = function () {};
        $app->afterBootstrapping(RegisterFacades::class, $closure);
        $this->assertArrayHasKey(0, $app['events']->getListeners('bootstrapped: Hypervel\Foundation\Bootstrap\RegisterFacades'));
    }

    public function testGetNamespace()
    {
        $app1 = $this->getApplication([], realpath(__DIR__ . '/Fixtures/project1'));
        $app2 = $this->getApplication([], realpath(__DIR__ . '/Fixtures/project2'));

        $this->assertSame('App\One\\', $app1->getNamespace());
        $this->assertSame('App\Two\\', $app2->getNamespace());
    }

    public function testMacroable()
    {
        $app = $this->getApplication();
        $app->macro('foo', function () {
            return 'bar';
        });

        $this->assertSame('bar', $app->foo());
    }

    protected function assertExpectationCount(int $times): void
    {
        $this->assertSame($times, m::getContainer()->mockery_getExpectationCount());
    }

    public function testAbortThrowsNotFoundHttpException()
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Page was not found');

        $app = $this->getApplication();
        $app->abort(404, 'Page was not found');
    }

    public function testAbortThrowsHttpException()
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Request is bad');

        $app = $this->getApplication();
        $app->abort(400, 'Request is bad');
    }

    public function testAbortAcceptsHeaders()
    {
        try {
            $app = $this->getApplication();
            $app->abort(400, 'Bad request', ['X-FOO' => 'BAR']);
            $this->fail(sprintf('abort must throw an %s.', HttpException::class));
        } catch (HttpException $exception) {
            $this->assertSame(['X-FOO' => 'BAR'], $exception->getHeaders());
        }
    }

    public function testAfterLoadingEnvironmentRegistersCallback()
    {
        $app = $this->getApplication();
        $eventDispatcher = new EventDispatcher($app);
        $app->instance('events', $eventDispatcher);

        $closure = function () {};
        $app->afterLoadingEnvironment($closure);

        $listeners = $app['events']->getListeners('bootstrapped: ' . LoadEnvironmentVariables::class);
        $this->assertArrayHasKey(0, $listeners);
    }

    public function testGetCachedServicesPath()
    {
        $app = $this->getApplication([], sys_get_temp_dir() . '/hypervel-test-app');

        $this->assertStringEndsWith('cache/services.php', $app->getCachedServicesPath());
    }

    public function testConfigurationIsCachedReturnsFalseWhenNoCacheFile()
    {
        $app = $this->getApplication([], sys_get_temp_dir() . '/hypervel-test-app-' . uniqid());

        $this->assertFalse($app->configurationIsCached());
    }

    public function testConfigurationIsCachedReturnsTrueWhenCacheFileExists()
    {
        $basePath = sys_get_temp_dir() . '/hypervel-test-app-' . uniqid();
        $cachePath = $basePath . '/bootstrap/cache/config.php';

        mkdir(dirname($cachePath), 0755, true);
        file_put_contents($cachePath, '<?php return [];');

        try {
            $app = $this->getApplication([], $basePath);
            $this->assertTrue($app->configurationIsCached());
        } finally {
            unlink($cachePath);
            rmdir(dirname($cachePath));
            rmdir(dirname($cachePath, 2));
            rmdir($basePath);
        }
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

abstract class AbstractClass
{
}

class ConcreteClass extends AbstractClass
{
}
