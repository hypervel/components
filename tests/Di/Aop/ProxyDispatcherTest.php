<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\AspectManager;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Di\Aop\ProxyDispatcher;
use Hypervel\Tests\TestCase;
use ValueError;

class ProxyDispatcherTest extends TestCase
{
    public function testDispatchesTheOriginalMethodWithoutMatchingAspects(): void
    {
        $target = new ProxyDispatcherTarget('original');

        $result = ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'combine',
            $this->arguments(['value' => 'first', 'rest' => ['second', 'named' => 'third']]),
            $target->combine(...)
        );

        $this->assertSame(['first', 'second', 'named' => 'third'], $result);
        $this->assertSame([], AspectManager::get(ProxyDispatcherTarget::class, 'combine'));
    }

    public function testPublishesAndReusesTheCompletePrioritizedAspectList(): void
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
            $target->number(...)
        ));
        $this->assertSame(
            [DispatcherIncrementAspect::class, DispatcherDoubleAspect::class],
            AspectManager::get(ProxyDispatcherTarget::class, 'number')
        );

        AspectCollector::flushState();

        $this->assertSame(5, ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'number',
            $arguments,
            $target->number(...)
        ));
    }

    public function testExposesTheBoundInstanceToAspects(): void
    {
        AspectCollector::setAround(DispatcherInstanceAspect::class, [
            ProxyDispatcherTarget::class . '::name',
        ]);

        $target = new ProxyDispatcherTarget('bound');

        $this->assertSame('bound', ProxyDispatcher::dispatch(
            ProxyDispatcherTarget::class,
            'name',
            $this->arguments([]),
            $target->name(...)
        ));
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
        $this->expectExceptionMessage(
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

class DispatcherInstanceAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->getInstance()?->value;
    }
}
