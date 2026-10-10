<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AspectManager;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Di\Aop\ProxyDispatcher;
use Hypervel\Tests\Di\Fixtures\Aspect\NoProcessAspect;
use Hypervel\Tests\TestCase;
use RuntimeException;
use ValueError;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class ProxyDispatcherTest extends TestCase
{
    public function testDispatchesTheOriginalMethodWithoutMatchingAspects(): void
    {
        $target = new ProxyDispatcherTarget('original');

        $result = ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'combine',
            $this->arguments(['value' => 'first', 'rest' => ['second', 'named' => 'third']]),
            $target->combine(...),
            $target
        );

        $this->assertSame(['first', 'second', 'named' => 'third'], $result);
        $this->assertNotNull(AspectManager::get(ProxyDispatcherTarget::class, 'combine'));
    }

    public function testPublishesAndReusesTheCompletePrioritizedAspectChain(): void
    {
        AspectCollector::setAround(DispatcherIncrementAspect::class, [
            ProxyDispatcherTarget::class . '::number',
            ProxyDispatcherTarget::class . '::number',
        ], 20);
        AspectCollector::setAround(DispatcherDoubleAspect::class, [
            ProxyDispatcherTarget::class . '::number',
        ], 10);

        $target = new ProxyDispatcherTarget;
        $arguments = $this->arguments(['value' => 2]);

        $this->assertSame(5, ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'number',
            $arguments,
            $target->number(...),
            $target
        ));
        $chain = AspectManager::get(ProxyDispatcherTarget::class, 'number');

        AspectCollector::flushState();

        $this->assertSame(5, ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'number',
            $arguments,
            $target->number(...),
            $target
        ));
        $this->assertSame($chain, AspectManager::get(ProxyDispatcherTarget::class, 'number'));
    }

    public function testAspectMayShortCircuitWithoutImplementingAroundInterface(): void
    {
        AspectCollector::setAround(NoProcessAspect::class, [ProxyDispatcherTarget::class . '::number']);

        $this->assertTrue(ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'number',
            [],
            static fn (): never => throw new RuntimeException('The original method must not run.'),
            null
        ));
    }

    public function testCachedChainResolvesReboundAspectsFromTheCurrentContainer(): void
    {
        AspectCollector::setAround(DispatcherCallbackAspect::class, [ProxyDispatcherTarget::class . '::number']);
        $target = new ProxyDispatcherTarget;
        $dispatch = fn (): mixed => ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'number',
            $this->arguments(['value' => 2]),
            $target->number(...),
            $target
        );

        Container::getInstance()->instance(DispatcherCallbackAspect::class, new DispatcherCallbackAspect(
            static fn (ProceedingJoinPoint $point): mixed => $point->process() + 1
        ));
        $this->assertSame(3, $dispatch());

        Container::getInstance()->instance(DispatcherCallbackAspect::class, new DispatcherCallbackAspect(
            static fn (ProceedingJoinPoint $point): mixed => $point->process() + 2
        ));
        $this->assertSame(4, $dispatch());

        Container::setInstance(new Container);
        Container::getInstance()->instance(DispatcherCallbackAspect::class, new DispatcherCallbackAspect(
            static fn (ProceedingJoinPoint $point): mixed => $point->process() + 3
        ));
        $this->assertSame(5, $dispatch());
    }

    public function testCachedChainKeepsConcurrentInvocationStateSeparate(): void
    {
        AspectCollector::setAround(DispatcherScopedAspect::class, [ProxyDispatcherTarget::class . '::name']);
        Container::getInstance()->scoped(DispatcherScopedAspect::class);

        $dispatch = function (string $name): mixed {
            $target = new ProxyDispatcherTarget($name);

            return ProxyDispatcher::dispatch(
                ProxyDispatcherTarget::class,
                'name',
                $this->arguments([]),
                $target->name(...),
                $target
            );
        };

        $this->assertSame([
            ['first', 'first', 'first'],
            ['second', 'second', 'second'],
        ], parallel([
            fn (): mixed => $dispatch('first'),
            fn (): mixed => $dispatch('second'),
        ]));
    }

    public function testNestedDispatchDoesNotReplaceTheOuterJoinPoint(): void
    {
        AspectCollector::setAround(DispatcherCallbackAspect::class, [ProxyDispatcherTarget::class . '::number']);
        $target = new ProxyDispatcherTarget;
        Container::getInstance()->instance(DispatcherCallbackAspect::class, new DispatcherCallbackAspect(
            function (ProceedingJoinPoint $point) use ($target): int {
                if ($point->arguments['keys']['value'] === 2) {
                    return $point->process();
                }

                $inner = ProxyDispatcher::dispatch(
                    ProxyDispatcherTarget::class,
                    'number',
                    $this->arguments(['value' => 2]),
                    $target->number(...),
                    $target
                );

                return $inner + $point->process();
            }
        ));

        $this->assertSame(5, ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'number',
            $this->arguments(['value' => 3]),
            $target->number(...),
            $target
        ));
    }

    public function testOriginalMethodBypassAndExceptionsPreserveTheCachedChain(): void
    {
        AspectCollector::setAround(DispatcherCallbackAspect::class, [ProxyDispatcherTarget::class . '::number'], 20);
        AspectCollector::setAround(DispatcherIncrementAspect::class, [ProxyDispatcherTarget::class . '::number'], 10);
        $exception = new RuntimeException('Original failure');
        Container::getInstance()->instance(DispatcherCallbackAspect::class, new DispatcherCallbackAspect(
            static fn (ProceedingJoinPoint $point): mixed => $point->arguments['keys']['value'] === 2
                ? $point->processOriginalMethod()
                : $point->process()
        ));

        try {
            ProxyDispatcher::dispatch(
                ProxyDispatcherTarget::class,
                'number',
                $this->arguments(['value' => 1]),
                static fn (int $value): never => throw $exception,
                null
            );
            $this->fail('The original exception must propagate.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $target = new ProxyDispatcherTarget;
        $this->assertSame(2, ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'number',
            $this->arguments(['value' => 2]),
            $target->number(...),
            $target
        ));
        $this->assertSame(4, ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'number',
            $this->arguments(['value' => 3]),
            $target->number(...),
            $target
        ));
    }

    public function testShortCircuitedInvocationIsNotRetainedByTheCachedChain(): void
    {
        AspectCollector::setAround(DispatcherCallbackAspect::class, [ProxyDispatcherTarget::class . '::name']);
        $pointReference = null;
        Container::getInstance()->instance(DispatcherCallbackAspect::class, new DispatcherCallbackAspect(
            static function (ProceedingJoinPoint $point) use (&$pointReference): bool {
                $pointReference = WeakReference::create($point);

                return true;
            }
        ));
        $target = new ProxyDispatcherTarget;
        $targetReference = WeakReference::create($target);

        $this->assertTrue(ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'name',
            $this->arguments([]),
            $target->name(...),
            $target
        ));
        unset($target);

        $this->assertNotNull($pointReference);
        $this->assertNull($pointReference->get());
        $this->assertNull($targetReference->get());
    }

    public function testReconstructsVisibleArguments(): void
    {
        $this->assertSame(
            ['changed', 'second', 'third'],
            ProxyDispatcher::resolveArguments(3, ['changed', 'second'], ['third', 'named' => 'ignored'])
        );
        $this->assertSame(
            ['changed'],
            ProxyDispatcher::resolveArguments(1, ['changed', 'second'], ['third'])
        );
        $this->assertSame(
            'second',
            ProxyDispatcher::resolveArgument(2, ['first', 'second'], [], 1)
        );
    }

    public function testRejectsAnInvalidVisibleArgumentPosition(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessageIs(
            'func_get_arg(): Argument #1 ($position) must be less than the number of the arguments passed '
            . 'to the currently executed function'
        );

        ProxyDispatcher::resolveArgument(1, ['first'], [], 1);
    }

    public function testCapturesOnlyOriginalPositionalVariadicsByValue(): void
    {
        $arguments = ['first', 'second', 'named' => 'third'];
        $captured = ProxyDispatcher::captureVariadicArguments($arguments, 1, false);

        $arguments[0] = 'changed';

        $this->assertSame(['first'], $captured);
    }

    public function testCapturesOriginalPositionalVariadicsByReference(): void
    {
        $first = 'first';
        $second = 'second';
        $arguments = [&$first, &$second, 'named' => 'third'];
        $captured = ProxyDispatcher::captureVariadicArguments($arguments, 2, true);

        $captured[0] = 'changed';
        $captured[1] = 'updated';

        $this->assertSame(['changed', 'updated'], [$first, $second]);
    }

    /**
     * Build the argument structure consumed by ProceedingJoinPoint.
     *
     * @param array<string, mixed> $values
     */
    private function arguments(array $values): array
    {
        return [
            'order' => array_keys($values),
            'keys' => $values,
            'variadic' => array_key_exists('rest', $values) ? 'rest' : '',
        ];
    }
}

class ProxyDispatcherTarget
{
    public function __construct(public string $value = '')
    {
    }

    public function combine(string $value, string ...$rest): array
    {
        return [$value, ...$rest];
    }

    public function number(int $value): int
    {
        return $value;
    }

    public function name(): string
    {
        return $this->value;
    }
}

class DispatcherIncrementAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->process() + 1;
    }
}

class DispatcherDoubleAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->process() * 2;
    }
}

class DispatcherCallbackAspect extends AbstractAspect
{
    /**
     * Create an aspect using the supplied test callback.
     */
    public function __construct(private Closure $callback)
    {
    }

    /**
     * Invoke the test's aspect callback.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return ($this->callback)($proceedingJoinPoint);
    }
}

class DispatcherScopedAspect extends AbstractAspect
{
    private string $value;

    /**
     * Read invocation state after another coroutine has entered the chain.
     */
    public function process(ProceedingJoinPoint $proceedingJoinPoint): array
    {
        $this->value = $proceedingJoinPoint->getInstance()->value;
        usleep(1000);

        return [$this->value, $proceedingJoinPoint->getInstance()->value, $proceedingJoinPoint->process()];
    }
}
