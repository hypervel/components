<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Di\Aop\AbstractAspect;
use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAop;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Di\Fixtures\ProxyTraitObject;
use LogicException;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithAopTest extends TestCase
{
    use InteractsWithAop;

    public function testCallWithAspectsRunsMatchingAspect()
    {
        AspectCollector::setAround(UppercaseGreetingAspect::class, [InteractsWithAopTarget::class . '::greet']);

        $result = $this->callWithAspects(new InteractsWithAopTarget, 'greet', ['name' => 'hypervel']);

        $this->assertSame('HELLO HYPERVEL', $result);
    }

    public function testCallWithAspectsReturnsOriginalResultWhenNoAspectMatches()
    {
        $result = $this->callWithAspects(new InteractsWithAopTarget, 'greet', ['name' => 'hypervel']);

        $this->assertSame('hello hypervel', $result);
    }

    public function testCallWithAspectsRespectsAspectPriority()
    {
        AspectCollector::setAround(OuterSequenceAspect::class, [InteractsWithAopTarget::class . '::sequence'], 20);
        AspectCollector::setAround(InnerSequenceAspect::class, [InteractsWithAopTarget::class . '::sequence'], 10);

        $result = $this->callWithAspects(new InteractsWithAopTarget, 'sequence');

        $this->assertSame('outer(inner(core))', $result);
    }

    public function testCallWithAspectsPassesBoundInstanceToAspect()
    {
        AspectCollector::setAround(UsesInstanceNameAspect::class, [InteractsWithAopTarget::class . '::instanceName']);

        $result = $this->callWithAspects(new InteractsWithAopTarget(name: 'Hypervel'), 'instanceName');

        $this->assertSame('Hypervel', $result);
    }

    public function testCallWithAspectsFillsOmittedOptionalParametersFromDefaults()
    {
        AspectCollector::setAround(CaptureArgumentsAspect::class, [InteractsWithAopTarget::class . '::withDefaults']);

        $result = $this->callWithAspects(new InteractsWithAopTarget, 'withDefaults');

        $this->assertSame([1, 'default'], $result);
    }

    public function testCallWithAspectsHandlesVariadicParameters()
    {
        AspectCollector::setAround(CaptureArgumentsAspect::class, [InteractsWithAopTarget::class . '::withVariadic']);

        $result = $this->callWithAspects(new InteractsWithAopTarget, 'withVariadic', [
            'id' => 2,
            'values' => ['alpha', 'beta'],
        ]);

        $this->assertSame([2, 'alpha', 'beta'], $result);
    }

    public function testCallWithAspectsSupportsExactMethodRules()
    {
        AspectCollector::setAround(AppendSuffixAspect::class, [InteractsWithAopTarget::class . '::intercepted']);

        $target = new InteractsWithAopTarget;

        $this->assertSame('intercepted [aspect]', $this->callWithAspects($target, 'intercepted'));
        $this->assertSame('untouched', $this->callWithAspects($target, 'untouched'));
    }

    public function testCallWithAspectsSupportsNonPublicMethods()
    {
        AspectCollector::setAround(AppendSuffixAspect::class, [InteractsWithAopTarget::class . '::hiddenValue']);

        $result = $this->callWithAspects(new InteractsWithAopTarget, 'hiddenValue');

        $this->assertSame('secret [aspect]', $result);
    }

    public function testCallWithAspectsThrowsForAlreadyProxiedInstances()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('already proxied by AOP');

        $this->callWithAspects(new ProxyTraitObject, 'get');
    }

    public function testIsAopProxiedDetectsGeneratedProxyTraitUsage()
    {
        $this->assertFalse($this->isAopProxied(new InteractsWithAopTarget));
        $this->assertTrue($this->isAopProxied(new ProxyTraitObject));
    }

    public function testCallWithAspectsPreservesPromiseReturnValues()
    {
        AspectCollector::setAround(PassThroughAspect::class, [InteractsWithAopTarget::class . '::promiseResult']);

        $promise = $this->callWithAspects(new InteractsWithAopTarget, 'promiseResult');

        $this->assertInstanceOf(PromiseInterface::class, $promise);
        $this->assertSame('done', $promise->wait());
    }
}

class InteractsWithAopTarget
{
    public function __construct(public string $name = 'Hypervel')
    {
    }

    public function greet(string $name): string
    {
        return "hello {$name}";
    }

    public function sequence(): string
    {
        return 'core';
    }

    public function instanceName(): string
    {
        return 'unknown';
    }

    public function withDefaults(int $count = 1, string $label = 'default'): array
    {
        return [$count, $label];
    }

    public function withVariadic(int $id = 1, string ...$values): array
    {
        return array_merge([$id], $values);
    }

    public function intercepted(): string
    {
        return 'intercepted';
    }

    public function untouched(): string
    {
        return 'untouched';
    }

    public function promiseResult(): PromiseInterface
    {
        return Create::promiseFor('done');
    }

    private function hiddenValue(): string
    {
        return 'secret';
    }
}

class UppercaseGreetingAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return strtoupper($proceedingJoinPoint->process());
    }
}

class OuterSequenceAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return 'outer(' . $proceedingJoinPoint->process() . ')';
    }
}

class InnerSequenceAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return 'inner(' . $proceedingJoinPoint->process() . ')';
    }
}

class UsesInstanceNameAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->getInstance()->name;
    }
}

class CaptureArgumentsAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->getArguments();
    }
}

class AppendSuffixAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->process() . ' [aspect]';
    }
}

class PassThroughAspect extends AbstractAspect
{
    public function process(ProceedingJoinPoint $proceedingJoinPoint): mixed
    {
        return $proceedingJoinPoint->process();
    }
}
