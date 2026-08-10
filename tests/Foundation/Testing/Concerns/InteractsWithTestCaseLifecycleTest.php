<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Pool\ConnectionInterface as PoolConnectionInterface;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting as ParallelTestingService;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;

class InteractsWithTestCaseLifecycleTest extends TestCase
{
    public function testLazyDatabaseTraitIsBootedOnceThroughRefreshDatabaseDependency(): void
    {
        $testCase = new FoundationLazyDatabaseTraitsTestCaseFixture('testPlaceholder');

        $testCase->bootDatabaseTraits();

        $this->assertSame(1, $testCase->refreshDatabaseCalls());
    }

    public function testFoundationTeardownAttemptsEveryPhaseAndPreservesTheEarliestFailure(): void
    {
        $steps = [];
        $callbackException = new RuntimeException('callback failed');
        $databaseException = new RuntimeException('database cleanup failed');
        $poolException = new RuntimeException('pool cleanup failed');
        $parallelException = new RuntimeException('parallel cleanup failed');
        $applicationException = new RuntimeException('application cleanup failed');

        $staticProperties = [];

        foreach (['connections', 'pooledConnections', 'containerId', 'rebindingRegistered'] as $property) {
            $reflection = new ReflectionProperty(DatabaseConnectionResolver::class, $property);
            $staticProperties[$property] = $reflection->getValue();
        }

        $parallelTesting = $this->app->make(ParallelTestingService::class);

        try {
            $pooledConnection = m::mock(PoolConnectionInterface::class);
            $pooledConnection->shouldReceive('discard')->once()->andReturnUsing(
                function () use (&$steps, $databaseException): never {
                    $steps[] = 'database';

                    throw $databaseException;
                }
            );

            (new ReflectionProperty(DatabaseConnectionResolver::class, 'pooledConnections'))
                ->setValue(null, ['default' => $pooledConnection]);

            $poolFactory = m::mock(PoolFactory::class);
            $poolFactory->shouldReceive('flushAll')->once()->andReturnUsing(
                function () use (&$steps, $poolException): never {
                    $steps[] = 'pool';

                    throw $poolException;
                }
            );

            $app = m::mock(ApplicationContract::class);
            $app->shouldReceive('resolved')->once()->with(PoolFactory::class)->andReturnTrue();
            $app->shouldReceive('make')->once()->with(PoolFactory::class)->andReturn($poolFactory);
            $app->shouldReceive('flush')->once()->andReturnUsing(
                function () use (&$steps, $applicationException): never {
                    $steps[] = 'application';

                    throw $applicationException;
                }
            );

            $parallelTestingMock = m::mock(ParallelTestingService::class);
            $parallelTestingMock->shouldReceive('callTearDownTestCaseCallbacks')->once()->andReturnUsing(
                function () use (&$steps, $parallelException): never {
                    $steps[] = 'parallel';

                    throw $parallelException;
                }
            );
            ParallelTesting::swap($parallelTestingMock);

            $testCase = new FoundationLifecycleTestCaseFixture('testPlaceholder');
            $testCase->useApplication($app);
            $testCase->registerAfterApplicationCreatedCallback(static function (): void {
            });
            $testCase->registerBeforeApplicationDestroyedCallback(
                function () use (&$steps, $callbackException): never {
                    $steps[] = 'callback:first';

                    throw $callbackException;
                }
            );
            $testCase->registerBeforeApplicationDestroyedCallback(function () use (&$steps): void {
                $steps[] = 'callback:second';
            });
            $testCase->markSetupAsRun();

            try {
                $testCase->tearDownEnvironment();
                $this->fail('Expected teardown to rethrow the first callback failure.');
            } catch (RuntimeException $exception) {
                $this->assertSame($callbackException, $exception);
            }

            $this->assertSame([
                'callback:first',
                'callback:second',
                'database',
                'pool',
                'parallel',
                'application',
            ], $steps);
            $this->assertSame([
                'app' => null,
                'afterCallbacks' => [],
                'beforeCallbacks' => [],
                'callbackException' => null,
                'setUpHasRun' => false,
            ], $testCase->lifecycleState());
        } finally {
            ParallelTesting::swap($parallelTesting);

            foreach ($staticProperties as $property => $value) {
                (new ReflectionProperty(DatabaseConnectionResolver::class, $property))->setValue(null, $value);
            }
        }
    }

    public function testFoundationTeardownRunsDestructionCallbacksWithoutAnApplication(): void
    {
        $steps = [];
        $callbackException = new RuntimeException('callback failed');
        $testCase = new FoundationLifecycleTestCaseFixture('testPlaceholder');
        $testCase->registerBeforeApplicationDestroyedCallback(
            function () use (&$steps, $callbackException): never {
                $steps[] = 'callback:first';

                throw $callbackException;
            }
        );
        $testCase->registerBeforeApplicationDestroyedCallback(function () use (&$steps): void {
            $steps[] = 'callback:second';
        });
        $testCase->markSetupAsRun();

        try {
            $testCase->tearDownEnvironment();
            $this->fail('Expected teardown to rethrow the first callback failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackException, $exception);
        }

        $this->assertSame(['callback:first', 'callback:second'], $steps);
        $this->assertSame([
            'app' => null,
            'afterCallbacks' => [],
            'beforeCallbacks' => [],
            'callbackException' => null,
            'setUpHasRun' => false,
        ], $testCase->lifecycleState());
    }
}

class FoundationLifecycleTestCaseFixture extends FoundationTestCase
{
    public function testPlaceholder(): void
    {
    }

    public function useApplication(ApplicationContract $app): void
    {
        $this->app = $app;
    }

    public function registerAfterApplicationCreatedCallback(callable $callback): void
    {
        $this->afterApplicationCreatedCallbacks[] = $callback;
    }

    public function registerBeforeApplicationDestroyedCallback(callable $callback): void
    {
        $this->beforeApplicationDestroyed($callback);
    }

    public function markSetupAsRun(): void
    {
        $this->setUpHasRun = true;
    }

    public function tearDownEnvironment(): void
    {
        $this->tearDownTheTestEnvironment();
    }

    public function lifecycleState(): array
    {
        return [
            'app' => $this->app,
            'afterCallbacks' => $this->afterApplicationCreatedCallbacks,
            'beforeCallbacks' => $this->beforeApplicationDestroyedCallbacks,
            'callbackException' => $this->callbackException,
            'setUpHasRun' => $this->setUpHasRun,
        ];
    }
}

class FoundationLazyDatabaseTraitsTestCaseFixture extends FoundationTestCase
{
    use LazilyRefreshDatabase;

    protected int $refreshDatabaseCalls = 0;

    public function testPlaceholder(): void
    {
    }

    public function refreshDatabase(): void
    {
        ++$this->refreshDatabaseCalls;
    }

    public function bootDatabaseTraits(): void
    {
        $this->setUpDatabaseTraits(array_flip(class_uses_recursive(static::class)));
    }

    public function refreshDatabaseCalls(): int
    {
        return $this->refreshDatabaseCalls;
    }
}
