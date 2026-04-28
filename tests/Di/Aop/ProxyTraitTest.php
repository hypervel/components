<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Aop\Pipeline;
use Hypervel\Di\Aop\ProxyTrait;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Di\Fixtures\Aspect\GetNameAspect;
use Hypervel\Tests\Di\Fixtures\Aspect\GetParamsAspect;
use Hypervel\Tests\Di\Fixtures\Aspect\IncrAspect;
use Hypervel\Tests\Di\Fixtures\ProxyTraitObject;

class ProxyTraitTest extends TestCase
{
    public function testGetParamsMap()
    {
        $obj = new ProxyTraitObject;

        $this->assertEquals(['id' => null, 'str' => ''], $obj->get(null)['keys']);
        $this->assertEquals(['id', 'str'], $obj->get(null)['order']);
        $this->assertEquals('', $obj->get(null)['variadic']);

        $this->assertEquals(['id' => 1, 'str' => ''], $obj->get2()['keys']);
        $this->assertEquals(['id', 'str'], $obj->get2()['order']);
        $this->assertEquals('', $obj->get2()['variadic']);

        $this->assertEquals(['id' => null, 'str' => ''], $obj->get2(null)['keys']);
        $this->assertEquals(['id', 'str'], $obj->get2(null)['order']);
        $this->assertEquals('', $obj->get2(null)['variadic']);

        $this->assertEquals(['id' => 1, 'str' => '', 'num' => 1.0], $obj->get3()['keys']);
        $this->assertEquals(['id', 'str', 'num'], $obj->get3()['order']);
        $this->assertEquals('', $obj->get3()['variadic']);

        $this->assertEquals(['id' => 1, 'str' => 'hy', 'num' => 1.0], $obj->get3(1, 'hy')['keys']);
        $this->assertEquals(['id', 'str', 'num'], $obj->get3(1, 'hy')['order']);

        $this->assertEquals(['id' => 1, 'variadic' => []], $obj->get4(1)['keys']);
        $this->assertEquals(['id', 'variadic'], $obj->get4(1)['order']);
        $this->assertEquals('variadic', $obj->get4()['variadic']);

        $this->assertEquals(['id' => 1, 'variadic' => ['a' => 'a']], $obj->get4(a: 'a')['keys']);
        $this->assertEquals(['id' => null, 'variadic' => ['b', 'a' => 'a']], $obj->get4(null, 'b', a: 'a')['keys']);
    }

    public function testGetParamsMapOnTraitAlias()
    {
        $obj = new ProxyTraitObject;

        $this->assertEquals(['id' => null, 'str' => ''], $obj->getOnTrait(null)['keys']);
        $this->assertEquals(['id', 'str'], $obj->getOnTrait(null)['order']);
        $this->assertEquals('', $obj->getOnTrait(null)['variadic']);

        $this->assertEquals(['id' => 1, 'str' => ''], $obj->get2OnTrait()['keys']);
        $this->assertEquals(['id', 'str'], $obj->get2OnTrait()['order']);

        $this->assertEquals(['id' => null, 'str' => ''], $obj->get2OnTrait(null)['keys']);
        $this->assertEquals(['id', 'str'], $obj->get2OnTrait(null)['order']);

        $this->assertEquals(['id' => 1, 'str' => '', 'num' => 1.0], $obj->get3OnTrait()['keys']);
        $this->assertEquals(['id', 'str', 'num'], $obj->get3OnTrait()['order']);

        $this->assertEquals(['id' => 1, 'str' => 'hy', 'num' => 1.0], $obj->get3OnTrait(1, 'hy')['keys']);
        $this->assertEquals(['id', 'str', 'num'], $obj->get3OnTrait(1, 'hy')['order']);

        $this->assertEquals(['id' => 1, 'variadic' => []], $obj->get4OnTrait(1)['keys']);
        $this->assertEquals(['id', 'variadic'], $obj->get4OnTrait(1)['order']);
        $this->assertEquals('variadic', $obj->get4OnTrait(1)['variadic']);

        $this->assertEquals(['id' => 1, 'variadic' => ['a' => 'a']], $obj->get4OnTrait(a: 'a')['keys']);
        $this->assertEquals(['id' => null, 'variadic' => ['b', 'a' => 'a']], $obj->get4OnTrait(null, 'b', a: 'a')['keys']);
    }

    public function testProceedingJoinPointGetInstance()
    {
        $obj = new ProxyTraitObject;
        $this->assertSame('HypervelCloud', $obj->getName2());

        AspectCollector::set('classes', [
            GetNameAspect::class => [ProxyTraitObject::class],
        ]);

        $obj = new ProxyTraitObject;
        $this->assertSame('Hypervel', $obj->getName());
    }

    public function testProceedingJoinPointGetArguments()
    {
        AspectCollector::flushState();

        $obj = new ProxyTraitObject;
        $this->assertEquals(['id' => 1, 'variadic' => ['2', 'foo' => '3'], 'func_get_args' => [1, '2']], $obj->getParams(1, '2', foo: '3'));

        AspectCollector::set('classes', [
            GetParamsAspect::class => [ProxyTraitObject::class],
        ]);

        $obj = new ProxyTraitObject;
        $this->assertEquals([1, '2', 'foo' => '3'], $obj->getParams2(1, '2', foo: '3'));
    }

    public function testHandleAroundWithClassAspect()
    {
        AspectCollector::set('classes', [
            IncrAspect::class => [ProxyTraitObject::class],
        ]);

        $obj = new ProxyTraitObject;
        $this->assertSame(2, $obj->incr());
    }

    public function testMakePipelineReturnsFreshInstances()
    {
        $stub = new class {
            use ProxyTrait;

            public static function getPipeline(): Pipeline
            {
                return self::makePipeline();
            }
        };

        $first = $stub::getPipeline();
        $second = $stub::getPipeline();

        $this->assertInstanceOf(Pipeline::class, $first);
        $this->assertNotSame($first, $second);
    }
}
