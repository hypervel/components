<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Attribute;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Pool\ConnectionInterface as PoolConnectionInterface;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Foundation\Testing\TestCase as FoundationTestCase;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testbench\Attributes\Define;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Concerns\HandlesAttributes;
use Hypervel\Testbench\Concerns\InteractsWithTestCase;
use Hypervel\Testbench\PHPUnit\AttributeParser;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting as ParallelTestingService;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;

#[WithConfig('testing.class_level', 'class_value')]
class InteractsWithTestCaseTest extends TestCase
{
    public function testUsesTestingConcernReturnsTrueForUsedTrait(): void
    {
        $this->assertTrue(static::usesTestingConcern(HandlesAttributes::class));
        $this->assertTrue(static::usesTestingConcern(InteractsWithTestCase::class));
    }

    public function testUsesTestingConcernReturnsFalseForUnusedTrait(): void
    {
        $this->assertFalse(static::usesTestingConcern('NonExistentTrait'));
    }

    public function testCachedUsesForTestCaseReturnsTraits(): void
    {
        $uses = static::cachedUsesForTestCase();

        $this->assertIsArray($uses);
        $this->assertArrayHasKey(HandlesAttributes::class, $uses);
        $this->assertArrayHasKey(InteractsWithTestCase::class, $uses);
    }

    public function testLazyDatabaseTraitIsBootedOnceThroughRefreshDatabaseDependency(): void
    {
        $testCase = new FoundationLazyDatabaseTraitsTestCaseFixture('testPlaceholder');

        $testCase->bootDatabaseTraits();

        $this->assertSame(1, $testCase->refreshDatabaseCalls());
    }

    public function testResolvePhpUnitAttributesReturnsCollection(): void
    {
        $attributes = $this->resolvePhpUnitAttributes();

        $this->assertInstanceOf(Collection::class, $attributes);
    }

    #[WithConfig('testing.method_level', 'method_value')]
    public function testResolvePhpUnitAttributesMergesClassAndMethodAttributes(): void
    {
        $attributes = $this->resolvePhpUnitAttributes();

        // Should have WithConfig from both class and method level
        $this->assertTrue($attributes->has(WithConfig::class));

        $withConfigInstances = $attributes->get(WithConfig::class);
        $this->assertCount(2, $withConfigInstances);
    }

    public function testClassLevelAttributeIsApplied(): void
    {
        // The WithConfig attribute at class level should be applied
        $this->assertSame('class_value', $this->app->make('config')->get('testing.class_level'));
    }

    public function testUsesTestingFeatureAddsAttribute(): void
    {
        // Add a testing feature programmatically at method level so it doesn't
        // persist to other tests in this class
        static::usesTestingFeature(
            new WithConfig('testing.programmatic', 'added'),
            Attribute::TARGET_METHOD
        );

        // Re-resolve attributes to include the programmatically added one
        $attributes = $this->resolvePhpUnitAttributes();

        $this->assertTrue($attributes->has(WithConfig::class));
    }

    public function testDefineMetaAttributeIsResolvedByAttributeParser(): void
    {
        // Test that AttributeParser resolves #[Define('env', 'method')] to DefineEnvironment
        $attributes = AttributeParser::forMethod(
            DefineMetaAttributeTestCase::class,
            'testWithDefineAttribute'
        );

        // Should have one attribute, resolved from Define to DefineEnvironment
        $this->assertCount(1, $attributes);
        $this->assertSame(DefineEnvironment::class, $attributes[0]['key']);
        $this->assertInstanceOf(DefineEnvironment::class, $attributes[0]['instance']);
        $this->assertSame('setupDefineEnv', $attributes[0]['instance']->method);
    }

    #[Define('env', 'setupDefineEnvForExecution')]
    public function testDefineMetaAttributeIsExecutedThroughLifecycle(): void
    {
        // The #[Define('env', 'setupDefineEnvForExecution')] attribute should have been
        // resolved to DefineEnvironment and executed during setUp, calling our method
        $this->assertSame(
            'define_env_executed',
            $this->app->make('config')->get('testing.define_meta_attribute')
        );
    }

    protected function setupDefineEnvForExecution($app): void
    {
        $app->make('config')->set('testing.define_meta_attribute', 'define_env_executed');
    }

    public function testResolvePhpUnitAttributesReturnsCollectionOfCollections(): void
    {
        $attributes = $this->resolvePhpUnitAttributes();

        $this->assertInstanceOf(Collection::class, $attributes);

        // Each value should be a Collection, not an array
        $attributes->each(function ($value, $key) {
            $this->assertInstanceOf(
                Collection::class,
                $value,
                "Value for key {$key} should be a Collection, not " . gettype($value)
            );
        });
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

/**
 * Test fixture for Define meta-attribute parsing.
 */
class DefineMetaAttributeTestCase extends TestCase
{
    #[Define('env', 'setupDefineEnv')]
    public function testWithDefineAttribute(): void
    {
        // This method exists just to have the attribute parsed
    }

    protected function setupDefineEnv($app): void
    {
        // Method that would be called
    }
}
