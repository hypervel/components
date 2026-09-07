<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Closure;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Queue\Events\JobProcessing;
use Hypervel\Routing\Events\RouteMatched;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;
use Hypervel\Sentry\SentryServiceProvider;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Hypervel\WebSocketServer\Events\ConnectionClosing;
use Hypervel\WebSocketServer\Events\ConnectionOpening;
use Hypervel\WebSocketServer\Events\MessageReceived;
use ReflectionFunction;
use ReflectionProperty;
use Sentry\SentrySdk;
use Sentry\State\RuntimeContext;
use Sentry\State\RuntimeContextStorageInterface;

class ServiceProviderWithoutDsnTest extends TestCase
{
    protected InMemoryRuntimeContextStorage $runtimeContextStorage;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->runtimeContextStorage = new InMemoryRuntimeContextStorage;
        SentrySdk::init();
        SentrySdk::setRuntimeContextStorage($this->runtimeContextStorage);
        SentrySdk::startContext();

        $app->make('config')->set('sentry.dsn', null);
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            SentryServiceProvider::class,
        ];
    }

    public function testIsBound(): void
    {
        $this->assertTrue(app()->bound('sentry'));
    }

    public function testDsnIsNotSet(): void
    {
        $this->assertNull(app('sentry')->getClient()->getOptions()->getDsn());
    }

    public function testDidNotRegisterEvents(): void
    {
        $this->assertFalse(app('events')->hasListeners(RouteMatched::class));

        foreach ([
            JobProcessing::class,
            ScheduledTaskStarting::class,
            ConnectionOpening::class,
            MessageReceived::class,
            ConnectionClosing::class,
        ] as $event) {
            $this->assertNull($this->findSentryBoundaryListener($event));
        }
    }

    public function testDidNotRegisterAopOrCoroutinePropagation(): void
    {
        $callbacks = (new ReflectionProperty(Coroutine::class, 'afterCreatedCallbacks'))->getValue();

        $this->assertSame([], AspectCollector::getRule(GuzzleHttpClientAspect::class));
        $this->assertSame([], $callbacks);
    }

    public function testClearsPreviouslyRegisteredRuntimeContextStorage(): void
    {
        $this->assertNull($this->runtimeContextStorage->get());

        SentrySdk::startContext();

        try {
            $this->assertNull($this->runtimeContextStorage->get());
        } finally {
            SentrySdk::endContext();
        }
    }

    public function testArtisanCommandsAreRegistered(): void
    {
        $this->assertArrayHasKey('sentry:test', Artisan::all());
        $this->assertArrayHasKey('sentry:publish', Artisan::all());
    }

    private function findSentryBoundaryListener(string $event): ?Closure
    {
        $listeners = app('events')->getRawListeners()[$event] ?? [];

        foreach ($listeners as $listener) {
            if ($listener instanceof Closure
                && (new ReflectionFunction($listener))->getClosureThis() instanceof SentryServiceProvider) {
                return $listener;
            }
        }

        return null;
    }
}

class InMemoryRuntimeContextStorage implements RuntimeContextStorageInterface
{
    protected ?RuntimeContext $runtimeContext = null;

    /**
     * Return the stored runtime context.
     */
    public function get(): ?RuntimeContext
    {
        return $this->runtimeContext;
    }

    /**
     * Store the runtime context.
     */
    public function set(RuntimeContext $runtimeContext): void
    {
        $this->runtimeContext = $runtimeContext;
    }

    /**
     * Remove the stored runtime context.
     */
    public function remove(): ?RuntimeContext
    {
        $runtimeContext = $this->runtimeContext;
        $this->runtimeContext = null;

        return $runtimeContext;
    }
}
