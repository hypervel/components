<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Container\Container;
use Hypervel\Di\Aop\Pipeline;
use Hypervel\Di\Aop\ProceedingJoinPoint;
use Hypervel\Tests\Di\Stub\Aspect\NoProcessAspect;
use Hypervel\Tests\TestCase;
use WeakReference;

/**
 * @internal
 * @coversNothing
 */
class PipelineTest extends TestCase
{
    public function testPipelineExecutesAspectAndReturnsResult()
    {
        $container = Container::getInstance();
        $pipeline = new Pipeline($container);

        $point = new ProceedingJoinPoint(function () {
        }, 'Foo', 'call', []);

        $result = $pipeline->via('process')->through([
            NoProcessAspect::class,
        ])->send($point)->then(function () {
        });

        $this->assertTrue($result);
    }

    public function testPipelineDoesNotCreateCircularReference()
    {
        $container = Container::getInstance();
        $pipeline = new Pipeline($container);

        $point = new ProceedingJoinPoint(function () {
        }, 'Foo', 'call', []);

        $pipeline->via('process')->through([
            NoProcessAspect::class,
        ])->send($point)->then(function () {
        });

        $weakReference = WeakReference::create($pipeline);
        unset($pipeline);
        $this->assertNull($weakReference->get());
    }
}
