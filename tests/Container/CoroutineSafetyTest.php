<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use Hypervel\Container\Container;
use Hypervel\Container\SharedResolution;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Contracts\Container\CircularDependencyException;
use Hypervel\Tests\TestCase;
use RuntimeException;
use stdClass;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Throwable;

use function Hypervel\Coroutine\parallel;

class CoroutineSafetyTest extends TestCase
{
    public function testScopedInstancesAreIsolatedPerCoroutine(): void
    {
        $container = new Container;
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
        $container = new Container;
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
        $container = new Container;
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
        $container = new Container;
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
        $container = new Container;

        $container->bind(CoroutineSlowService::class, function ($container) {
            usleep(100);

            return new CoroutineSlowService;
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
        $container = new Container;

        // Bind CoroutineSlowDependency with a factory that yields.
        // CoroutineConfigurableService takes (CoroutineSlowDependency, string $config).
        // The slow dependency is resolved FIRST, yielding before the $config
        // parameter override is read from Context. If the override stack were
        // shared, co2's overrides would corrupt co1's $config lookup.
        $container->bind(CoroutineSlowDependency::class, function () {
            usleep(100);

            return new CoroutineSlowDependency;
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
        $container = new Container;

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
        $container = new Container;

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
        $container = new Container;

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
                'Target [Hypervel\Tests\Container\CoroutineDependencyInterface] is not instantiable.',
                $e->getMessage()
            );
        }
    }

    public function testConcurrentSingletonWaitsForResolvingCallbacks(): void
    {
        $container = new CoroutineInspectingContainer;
        $callbackEntered = new Channel(1);
        $releaseCallback = new Channel(1);
        $waiterEntered = new Channel(1);
        $container->waiterEntered = $waiterEntered;
        $constructions = 0;

        $container->singleton('service', function () use (&$constructions) {
            $service = new stdClass;
            $service->status = 'constructed';
            ++$constructions;

            return $service;
        });
        $container->resolving('service', function ($service) use ($callbackEntered, $releaseCallback): void {
            $callbackEntered->push(true);
            $releaseCallback->pop();
            $service->status = 'ready';
        });

        $results = parallel([
            'owner' => fn () => $container->make('service'),
            'waiter' => function () use ($callbackEntered, $container) {
                $callbackEntered->pop();

                return $container->make('service');
            },
            'release' => function () use ($waiterEntered, $releaseCallback): bool {
                $waiterObserved = $waiterEntered->pop(1);
                $releaseCallback->push(true);

                return $waiterObserved;
            },
        ]);

        $this->assertTrue($results['release']);
        $this->assertSame(1, $constructions);
        $this->assertSame($results['owner'], $results['waiter']);
        $this->assertSame('ready', $results['owner']->status);
    }

    public function testConcurrentAutoSingletonConstructionConverges(): void
    {
        $container = new CoroutineInspectingContainer;
        $dependencyEntered = new Channel(1);
        $releaseDependency = new Channel(2);
        $waiterEntered = new Channel(1);
        $container->waiterEntered = $waiterEntered;

        $container->bind(CoroutineCoordinatedDependency::class, function () use ($dependencyEntered, $releaseDependency) {
            $dependencyEntered->push(true);
            $releaseDependency->pop();

            return new CoroutineCoordinatedDependency;
        });
        CoroutineCoordinatedService::$constructions = 0;

        try {
            $results = parallel([
                'owner' => fn () => $container->make(CoroutineCoordinatedService::class),
                'waiter' => function () use ($dependencyEntered, $container) {
                    $dependencyEntered->pop();

                    return $container->make(CoroutineCoordinatedService::class);
                },
                'release' => function () use ($waiterEntered, $releaseDependency): bool {
                    $waiterObserved = $waiterEntered->pop(1);
                    $releaseDependency->push(true);
                    $releaseDependency->push(true);

                    return $waiterObserved;
                },
            ]);
            $constructions = CoroutineCoordinatedService::$constructions;
        } finally {
            CoroutineCoordinatedService::$constructions = 0;
        }

        $this->assertTrue($results['release']);
        $this->assertSame(1, $constructions);
        $this->assertSame($results['owner'], $results['waiter']);
    }

    public function testConcurrentFailureFansOutAndAllowsRetry(): void
    {
        $container = new CoroutineInspectingContainer;
        $callbackEntered = new Channel(1);
        $releaseCallback = new Channel(1);
        $waiterEntered = new Channel(1);
        $container->waiterEntered = $waiterEntered;
        $constructions = 0;
        $shouldFail = true;

        $container->singleton('service', function () use (&$constructions) {
            $service = new stdClass;
            $service->construction = ++$constructions;

            return $service;
        });
        $container->resolving('service', function () use (&$shouldFail, $callbackEntered, $releaseCallback): void {
            if (! $shouldFail) {
                return;
            }

            $callbackEntered->push(true);
            $releaseCallback->pop();

            throw new RuntimeException('callback failed');
        });

        $results = parallel([
            'owner' => function () use ($container) {
                try {
                    return $container->make('service');
                } catch (RuntimeException $exception) {
                    return $exception;
                }
            },
            'waiter' => function () use ($callbackEntered, $container) {
                $callbackEntered->pop();

                try {
                    return $container->make('service');
                } catch (RuntimeException $exception) {
                    return $exception;
                }
            },
            'release' => function () use ($waiterEntered, $releaseCallback): bool {
                $waiterObserved = $waiterEntered->pop(1);
                $releaseCallback->push(true);

                return $waiterObserved;
            },
        ]);

        $this->assertTrue($results['release']);
        $this->assertInstanceOf(RuntimeException::class, $results['owner']);
        $this->assertSame($results['owner'], $results['waiter']);
        $this->assertSame(1, $constructions);

        $shouldFail = false;
        $retried = $container->make('service');

        $this->assertSame(2, $retried->construction);
        $this->assertSame($retried, $container->make('service'));
    }

    public function testDescendantCannotWaitOnAncestorOwnedResolution(): void
    {
        $container = new Container;
        $descendantResult = new Channel(1);

        $container->singleton('service', function () use ($container, $descendantResult) {
            $childId = SwooleCoroutine::create(function () use ($container, $descendantResult): void {
                try {
                    $container->make('service');
                } catch (CanceledException) {
                    return;
                } catch (Throwable $exception) {
                    $descendantResult->push($exception);
                }
            });

            try {
                $exception = $descendantResult->pop(1);

                if ($exception === false) {
                    $this->fail('The descendant remained blocked on its ancestor-owned resolution.');
                }

                $this->assertInstanceOf(CircularDependencyException::class, $exception);
            } finally {
                if (SwooleCoroutine::exists($childId)) {
                    SwooleCoroutine::cancel($childId, true);
                }
            }

            return new stdClass;
        });

        $this->assertInstanceOf(stdClass::class, $container->make('service'));
    }

    public function testCoordinatorWaitCycleIsRejected(): void
    {
        $container = new Container;
        $firstEntered = new Channel(1);
        $secondEntered = new Channel(1);
        $resultChannel = new Channel(2);

        $container->singleton('first', function () use ($container, $firstEntered, $secondEntered) {
            $firstEntered->push(true);
            $secondEntered->pop();

            return $container->make('second');
        });
        $container->singleton('second', function () use ($container, $firstEntered, $secondEntered) {
            $secondEntered->push(true);
            $firstEntered->pop();

            return $container->make('first');
        });

        $firstId = SwooleCoroutine::create(
            function () use ($container, $resultChannel): void {
                try {
                    $result = $container->make('first');
                } catch (CanceledException) {
                    return;
                } catch (Throwable $exception) {
                    $result = $exception;
                }

                $resultChannel->push(['first', $result]);
            },
        );
        $secondId = SwooleCoroutine::create(
            function () use ($container, $resultChannel): void {
                try {
                    $result = $container->make('second');
                } catch (CanceledException) {
                    return;
                } catch (Throwable $exception) {
                    $result = $exception;
                }

                $resultChannel->push(['second', $result]);
            },
        );

        try {
            $firstResult = $resultChannel->pop(1);
            $secondResult = $resultChannel->pop(1);
        } finally {
            foreach ([$firstId, $secondId] as $childId) {
                if (SwooleCoroutine::exists($childId)) {
                    SwooleCoroutine::cancel($childId, true);
                }
            }
        }

        $this->assertIsArray($firstResult, 'The first coordinator result timed out.');
        $this->assertIsArray($secondResult, 'The second coordinator result timed out.');

        $results = [
            $firstResult[0] => $firstResult[1],
            $secondResult[0] => $secondResult[1],
        ];

        $this->assertInstanceOf(CircularDependencyException::class, $results['first']);
        $this->assertSame($results['first'], $results['second']);
    }

    public function testCanceledWaiterRemovesCoordinatorEdge(): void
    {
        $container = new CoroutineInspectingContainer;
        $ownerEntered = new Channel(1);
        $releaseOwner = new Channel(1);
        $ownerFinished = new Channel(1);
        $waiterFinished = new Channel(1);

        $container->singleton('service', function () use ($ownerEntered, $releaseOwner) {
            $ownerEntered->push(true);
            $releaseOwner->pop();

            return new stdClass;
        });

        SwooleCoroutine::create(function () use ($container, $ownerFinished): void {
            $ownerFinished->push($container->make('service'));
        });
        $ownerEntered->pop();

        $waiterId = SwooleCoroutine::create(function () use ($container, $waiterFinished): void {
            try {
                $container->make('service');
            } catch (CanceledException $exception) {
                $waiterFinished->push($exception);
            }
        });

        try {
            $deadline = microtime(true) + 1;

            while ($container->waitCount() !== 1 && microtime(true) < $deadline) {
                usleep(100);
            }

            $this->assertSame(1, $container->waitCount());
            $this->assertTrue(SwooleCoroutine::cancel($waiterId, true));
            $this->assertInstanceOf(CanceledException::class, $waiterFinished->pop(1));
            $this->assertSame(0, $container->waitCount());
        } finally {
            $releaseOwner->push(true);
        }

        $this->assertInstanceOf(stdClass::class, $ownerFinished->pop(1));
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

class CoroutineInspectingContainer extends Container
{
    public ?Channel $waiterEntered = null;

    protected function awaitSharedResolution(string $abstract, SharedResolution $resolution): mixed
    {
        $this->waiterEntered?->push(true);

        return parent::awaitSharedResolution($abstract, $resolution);
    }

    public function waitCount(): int
    {
        return count($this->sharedResolutionWaits);
    }
}

class CoroutineCoordinatedDependency
{
}

class CoroutineCoordinatedService
{
    public static int $constructions = 0;

    public function __construct(public readonly CoroutineCoordinatedDependency $dependency)
    {
        ++self::$constructions;
    }
}
