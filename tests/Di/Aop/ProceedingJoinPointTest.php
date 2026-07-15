<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Tests\TestCase;

class ProceedingJoinPointTest extends TestCase
{
    public function testProcessOriginalMethod(): void
    {
        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'incr',
            ['keys' => []]
        );

        $this->assertSame(1, $obj->processOriginalMethod());
    }

    public function testGetArguments(): void
    {
        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'incr',
            ['keys' => []]
        );
        $this->assertSame([], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => []], 'variadic' => 'variadic']
        );
        $this->assertSame([1], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => [2, 'foo' => 3]], 'variadic' => 'variadic']
        );
        $this->assertSame([1, 2, 'foo' => 3], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => [2, 'foo' => 3]], 'variadic' => '']
        );
        $this->assertSame([1, [2, 'foo' => 3]], $obj->getArguments());
    }

    public function testGetInstance(): void
    {
        $object = new ProceedingJoinPointTarget('TestName');

        $joinPoint = new ProceedingJoinPoint(
            $object->getName(...),
            ProceedingJoinPointTarget::class,
            'getName',
            ['keys' => []]
        );

        $this->assertSame($object, $joinPoint->getInstance());
    }

    public function testGetInstanceReturnsNullForStaticClosure(): void
    {
        $joinPoint = new ProceedingJoinPoint(
            static fn () => 'value',
            ProceedingJoinPointTarget::class,
            'staticMethod',
            ['keys' => []]
        );

        $this->assertNull($joinPoint->getInstance());
    }
}

class ProceedingJoinPointTarget
{
    public function __construct(public string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }
}
