<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Tests\Di\Stub\ProxyTraitObject;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ProceedingJoinPointTest extends TestCase
{
    public function testProcessOriginalMethod()
    {
        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProxyTraitObject::class,
            'incr',
            ['keys' => []]
        );

        $this->assertSame(1, $obj->processOriginalMethod());
    }

    public function testGetArguments()
    {
        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProxyTraitObject::class,
            'incr',
            ['keys' => []]
        );
        $this->assertSame([], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProxyTraitObject::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => []], 'variadic' => 'variadic']
        );
        $this->assertSame([1], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProxyTraitObject::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => [2, 'foo' => 3]], 'variadic' => 'variadic']
        );
        $this->assertSame([1, 2, 'foo' => 3], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProxyTraitObject::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => [2, 'foo' => 3]], 'variadic' => '']
        );
        $this->assertSame([1, [2, 'foo' => 3]], $obj->getArguments());
    }

    public function testGetInstance()
    {
        $object = new ProxyTraitObject('TestName');

        $joinPoint = new ProceedingJoinPoint(
            $object->getName(...),
            ProxyTraitObject::class,
            'getName',
            ['keys' => []]
        );

        $this->assertSame($object, $joinPoint->getInstance());
    }

    public function testGetInstanceReturnsNullForStaticClosure()
    {
        $joinPoint = new ProceedingJoinPoint(
            static fn () => 'value',
            ProxyTraitObject::class,
            'staticMethod',
            ['keys' => []]
        );

        $this->assertNull($joinPoint->getInstance());
    }
}
