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
            ['keys' => []],
            null
        );

        $this->assertSame(1, $obj->processOriginalMethod());
    }

    public function testGetArguments(): void
    {
        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'incr',
            ['keys' => []],
            null
        );
        $this->assertSame([], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => []], 'variadic' => 'variadic'],
            null
        );
        $this->assertSame([1], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => [2, 'foo' => 3]], 'variadic' => 'variadic'],
            null
        );
        $this->assertSame([1, 2, 'foo' => 3], $obj->getArguments());

        $obj = new ProceedingJoinPoint(
            fn () => 1,
            ProceedingJoinPointTarget::class,
            'get4',
            ['order' => ['id', 'variadic'], 'keys' => ['id' => 1, 'variadic' => [2, 'foo' => 3]], 'variadic' => ''],
            null
        );
        $this->assertSame([1, [2, 'foo' => 3]], $obj->getArguments());
    }
}

class ProceedingJoinPointTarget
{
}
