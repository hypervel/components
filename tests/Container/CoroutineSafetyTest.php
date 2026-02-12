<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

/**
 * @internal
 * @coversNothing
 */
class CoroutineSafetyTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        Context::destroyAll();

        parent::tearDown();
    }

    public function testScopedInstancesAreIsolatedPerCoroutine(): void
    {
        $container = new Container();
        $container->scoped(CoroutineCounter::class);

        $results = parallel([
            'co1' => function () use ($container) {
                $counter = $container->make(CoroutineCounter::class);
                $counter->increment();
                usleep(100);

                return $counter->getValue();
            },
            'co2' => function () use ($container) {
                $counter = $container->make(CoroutineCounter::class);
                $counter->increment();
                $counter->increment();

                return $counter->getValue();
            },
        ]);

        $this->assertSame(1, $results['co1']);
        $this->assertSame(2, $results['co2']);
    }

    public function testScopedInstanceReturnsSameInstanceWithinCoroutine(): void
    {
        $container = new Container();
        $container->scoped(CoroutineCounter::class);

        $results = parallel([
            'co1' => function () use ($container) {
                $first = $container->make(CoroutineCounter::class);
                $second = $container->make(CoroutineCounter::class);

                return $first === $second;
            },
        ]);

        $this->assertTrue($results['co1']);
    }

    public function testForgetScopedInstancesCleansUpForNextRequest(): void
    {
        $container = new Container();
        $container->scoped(CoroutineRequestState::class);

        $results = parallel([
            'result' => function () use ($container) {
                $state = $container->make(CoroutineRequestState::class);
                $state->value = 'request-1';
                $beforeCleanup = $state->value;

                $container->forgetScopedInstances();

                $state2 = $container->make(CoroutineRequestState::class);
                $afterCleanup = $state2->value;

                return ['before' => $beforeCleanup, 'after' => $afterCleanup];
            },
        ]);

        $this->assertSame('request-1', $results['result']['before']);
        $this->assertNull($results['result']['after']);
    }

    public function testForgetScopedInstancesInOneCoroutineDoesNotAffectAnother(): void
    {
        $container = new Container();
        $container->scoped(CoroutineRequestState::class);

        $results = parallel([
            'co1' => function () use ($container) {
                $state = $container->make(CoroutineRequestState::class);
                $state->value = 'co1-data';
                usleep(200);

                // After co2 has already cleaned up, co1's data should still be there
                return $container->make(CoroutineRequestState::class)->value;
            },
            'co2' => function () use ($container) {
                $state = $container->make(CoroutineRequestState::class);
                $state->value = 'co2-data';
                $container->forgetScopedInstances();
                usleep(50);

                return $container->make(CoroutineRequestState::class)->value;
            },
        ]);

        $this->assertSame('co1-data', $results['co1']);
        $this->assertNull($results['co2']);
    }

    public function testBuildStackIsIsolatedPerCoroutine(): void
    {
        $container = new Container();

        $container->bind(CoroutineSlowService::class, function ($container) {
            usleep(100);

            return new CoroutineSlowService();
        });

        $container->when(CoroutineConsumerA::class)
            ->needs(CoroutineDependencyInterface::class)
            ->give(CoroutineImplementationA::class);

        $container->when(CoroutineConsumerB::class)
            ->needs(CoroutineDependencyInterface::class)
            ->give(CoroutineImplementationB::class);

        $results = parallel([
            'co1' => function () use ($container) {
                $consumer = $container->make(CoroutineConsumerA::class);

                return $consumer->dependency::class;
            },
            'co2' => function () use ($container) {
                $consumer = $container->make(CoroutineConsumerB::class);

                return $consumer->dependency::class;
            },
        ]);

        $this->assertSame(CoroutineImplementationA::class, $results['co1']);
        $this->assertSame(CoroutineImplementationB::class, $results['co2']);
    }

    public function testParameterOverridesAreIsolatedPerCoroutine(): void
    {
        $container = new Container();

        // Bind CoroutineSlowDependency with a factory that yields.
        // CoroutineConfigurableService takes (CoroutineSlowDependency, string $config).
        // The slow dependency is resolved FIRST, yielding before the $config
        // parameter override is read from Context. If the override stack were
        // shared, co2's overrides would corrupt co1's $config lookup.
        $container->bind(CoroutineSlowDependency::class, function () {
            usleep(100);

            return new CoroutineSlowDependency();
        });

        $container->bind(CoroutineConfigurableService::class);

        $results = parallel([
            'co1' => function () use ($container) {
                $service = $container->make(CoroutineConfigurableService::class, ['config' => 'value-a']);

                return $service->config;
            },
            'co2' => function () use ($container) {
                $service = $container->make(CoroutineConfigurableService::class, ['config' => 'value-b']);

                return $service->config;
            },
        ]);

        $this->assertSame('value-a', $results['co1']);
        $this->assertSame('value-b', $results['co2']);
    }

    public function testYieldingFactoryClosureDoesNotCorruptOtherCoroutines(): void
    {
        $container = new Container();

        $container->bind(CoroutineSlowService::class, function ($container) {
            $dep = $container->make(CoroutineFastDependency::class);
            usleep(100);

            return new CoroutineSlowService($dep);
        });

        $container->bind(CoroutineFastService::class, function ($container) {
            return new CoroutineFastService($container->make(CoroutineFastDependency::class));
        });

        $results = parallel([
            'slow' => function () use ($container) {
                return $container->make(CoroutineSlowService::class);
            },
            'fast' => function () use ($container) {
                return $container->make(CoroutineFastService::class);
            },
        ]);

        $this->assertInstanceOf(CoroutineSlowService::class, $results['slow']);
        $this->assertInstanceOf(CoroutineFastService::class, $results['fast']);
    }

    public function testExceptionDuringResolutionDoesNotCorruptParameterOverrideStack(): void
    {
        $container = new Container();

        $container->bind('failing-service', function () {
            throw new RuntimeException('Service creation failed');
        });

        try {
            $container->make('failing-service', ['param' => 'value']);
        } catch (RuntimeException) {
            // Expected
        }

        // The parameter override stack should be clean — subsequent resolution should work
        $container->bind('working-service', function ($app, $params) {
            return $params;
        });

        $result = $container->make('working-service');
        $this->assertSame([], $result);
    }

    public function testExceptionDuringBuildDoesNotCorruptBuildStack(): void
    {
        $container = new Container();

        try {
            $container->make(CoroutineUnresolvableDependencyStub::class);
        } catch (BindingResolutionException) {
            // Expected
        }

        // BuildStack should be clean — subsequent resolution should not show
        // the failed class in build stack error messages
        try {
            $container->make(CoroutineDependencyInterface::class);
        } catch (BindingResolutionException $e) {
            $this->assertStringNotContainsString(
                CoroutineUnresolvableDependencyStub::class,
                $e->getMessage()
            );
            $this->assertSame(
                'Target [Hypervel\\Tests\\Container\\CoroutineDependencyInterface] is not instantiable.',
                $e->getMessage()
            );
        }
    }
}

// --- Stub classes for coroutine safety tests ---

class CoroutineCounter
{
    private int $count = 0;

    public function increment(): void
    {
        ++$this->count;
    }

    public function getValue(): int
    {
        return $this->count;
    }
}

class CoroutineRequestState
{
    public ?string $value = null;
}

interface CoroutineDependencyInterface
{
}

class CoroutineImplementationA implements CoroutineDependencyInterface
{
}

class CoroutineImplementationB implements CoroutineDependencyInterface
{
}

class CoroutineConsumerA
{
    public function __construct(
        public readonly CoroutineSlowService $slowService,
        public readonly CoroutineDependencyInterface $dependency,
    ) {
    }
}

class CoroutineConsumerB
{
    public function __construct(
        public readonly CoroutineSlowService $slowService,
        public readonly CoroutineDependencyInterface $dependency,
    ) {
    }
}

class CoroutineSlowDependency
{
}

class CoroutineConfigurableService
{
    public function __construct(
        public readonly CoroutineSlowDependency $slowDependency,
        public readonly string $config,
    ) {
    }
}

class CoroutineFastDependency
{
}

class CoroutineSlowService
{
    public function __construct(
        public readonly ?CoroutineFastDependency $dependency = null,
    ) {
    }
}

class CoroutineFastService
{
    public function __construct(
        public readonly CoroutineFastDependency $dependency,
    ) {
    }
}

class CoroutineUnresolvableDependencyStub
{
    public function __construct(string $unresolvable)
    {
    }
}
