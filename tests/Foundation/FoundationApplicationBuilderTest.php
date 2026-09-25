<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Console\Kernel as ConsoleKernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Http\Kernel as HttpKernelContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\ApplicationBuilder;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Foundation\Http\Kernel as HttpKernel;
use Hypervel\Http\Middleware\PrefersJsonResponses;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class FoundationApplicationBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['HYPERVEL_STORAGE_PATH'], $_SERVER['HYPERVEL_STORAGE_PATH']);
        $this->unsetEnvironmentValue('APP_BASE_PATH');

        parent::tearDown();
    }

    public function testBaseDirectoryWithArg(): void
    {
        $_ENV['APP_BASE_PATH'] = __DIR__ . '/as-env';

        $app = Application::configure(__DIR__ . '/as-arg')->create();

        $this->assertSame(__DIR__ . '/as-arg', $app->basePath());
    }

    public function testBaseDirectoryWithEnv(): void
    {
        $_ENV['APP_BASE_PATH'] = __DIR__ . '/as-env';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/as-env', $app->basePath());
    }

    public function testBaseDirectoryWithEnvironmentRepository(): void
    {
        $this->setEnvironmentValue('APP_BASE_PATH', __DIR__ . '/as-env-repository');

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/as-env-repository', $app->basePath());
    }

    public function testBaseDirectoryPrefersEnvOverEnvironmentRepository(): void
    {
        $this->setEnvironmentValue('APP_BASE_PATH', __DIR__ . '/as-env-repository');
        $_ENV['APP_BASE_PATH'] = __DIR__ . '/as-env';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/as-env', $app->basePath());
    }

    public function testBaseDirectoryWithComposer(): void
    {
        $app = Application::configure()->create();

        $this->assertSame(dirname(__DIR__, 2), $app->basePath());
    }

    public function testStoragePathWithGlobalEnvVariable(): void
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/env-storage', $app->storagePath());
    }

    public function testStoragePathWithGlobalServerVariable(): void
    {
        $_SERVER['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/server-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/server-storage', $app->storagePath());
    }

    public function testStoragePathPrefersEnvVariable(): void
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';
        $_SERVER['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/server-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/env-storage', $app->storagePath());
    }

    public function testStoragePathBasedOnBasePath(): void
    {
        $app = Application::configure()->create();
        $this->assertSame($app->basePath() . DIRECTORY_SEPARATOR . 'storage', $app->storagePath());
    }

    public function testStoragePathCanBeCustomized(): void
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';

        $app = Application::configure()->create();
        $app->useStoragePath(__DIR__ . '/custom-storage');

        $this->assertSame(__DIR__ . '/custom-storage', $app->storagePath());
    }

    public function testKernelContractResolvesToSameInstanceAsConcrete(): void
    {
        $app = Application::configure()->create();

        $this->assertSame($app->make(HttpKernelContract::class), $app->make(HttpKernel::class));
        $this->assertSame($app->make(ConsoleKernelContract::class), $app->make(ConsoleKernel::class));
    }

    public function testKernelAbstractCanBeBoundToCustomConcrete(): void
    {
        $app = Application::configure()->create();

        $app->singleton(HttpKernelContract::class, CustomHttpKernelStub::class);

        $this->assertInstanceOf(CustomHttpKernelStub::class, $app->make(HttpKernelContract::class));
    }

    public function testKernelContractResolvesToSameInstanceThroughOtherBindings(): void
    {
        $app = Application::configure()->create();

        $app->singleton('kernel.consumer', HttpKernelContract::class);

        $this->assertSame($app->make(HttpKernelContract::class), $app->make('kernel.consumer'));
        $this->assertSame($app->make(HttpKernelContract::class), $app->makeTransient(HttpKernelContract::class));
    }

    public function testExceptionHandlerContractAndConcreteShareTheConfiguredInstance(): void
    {
        $configured = [];

        $app = Application::configure()
            ->withExceptions(function (Exceptions $exceptions) use (&$configured): void {
                $configured[] = $exceptions->handler;
            })
            ->create();

        $handler = $app->make(ExceptionHandler::class);

        $this->assertSame($handler, $app->make(Handler::class));
        $this->assertSame([$handler], $configured);

        $app->singleton('handler.consumer', ExceptionHandler::class);

        $this->assertSame($handler, $app->make('handler.consumer'));
    }

    public function testConfigureUsesTheApplicationBuilderDeclaredBySubclass(): void
    {
        $this->assertInstanceOf(CustomApplicationBuilderStub::class, CustomApplicationStub::configure());
        $this->assertNotInstanceOf(CustomApplicationBuilderStub::class, Application::configure());
    }

    public function testPrefersJsonResponsesIsFluent(): void
    {
        $builder = Application::configure();

        $this->assertSame($builder, $builder->prefersJsonResponses());
        $this->assertSame($builder, $builder->prefersJsonResponses(false));
    }

    public function testPrefersJsonResponsesRegistersMiddlewareWhenEnabled(): void
    {
        $app = Application::configure()->prefersJsonResponses()->create();

        $this->assertTrue($this->bootAndResolveKernel($app)->hasMiddleware(PrefersJsonResponses::class));
    }

    public function testPrefersJsonResponsesDefaultsToDisabled(): void
    {
        $app = Application::configure()->create();

        $this->assertFalse($this->bootAndResolveKernel($app)->hasMiddleware(PrefersJsonResponses::class));
    }

    public function testPrefersJsonResponsesIsIdempotentWhenCalledMultipleTimes(): void
    {
        $app = Application::configure()->prefersJsonResponses()->prefersJsonResponses()->create();

        $this->assertTrue($this->bootAndResolveKernel($app)->hasMiddleware(PrefersJsonResponses::class));
    }

    public function testPrefersJsonResponsesFalseDoesNotRegisterMiddleware(): void
    {
        $app = Application::configure()->prefersJsonResponses(false)->create();

        $this->assertFalse($this->bootAndResolveKernel($app)->hasMiddleware(PrefersJsonResponses::class));
    }

    /**
     * Boot the configured callbacks and resolve the HTTP kernel.
     */
    protected function bootAndResolveKernel(Application $app): HttpKernel
    {
        $app->singleton(HttpKernelContract::class, HttpKernel::class);

        // The builder registers its wiring inside booted callbacks. Invoking them
        // directly keeps this unit test from booting the full provider chain.
        $property = (new ReflectionClass(Application::class))->getProperty('bootedCallbacks');

        foreach ($property->getValue($app) as $callback) {
            $callback($app);
        }

        return $app->make(HttpKernelContract::class);
    }
}

class CustomHttpKernelStub extends HttpKernel
{
}

class CustomApplicationBuilderStub extends ApplicationBuilder
{
}

class CustomApplicationStub extends Application
{
    protected static string $applicationBuilder = CustomApplicationBuilderStub::class;
}
