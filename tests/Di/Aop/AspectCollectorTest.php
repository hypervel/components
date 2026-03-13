<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AspectCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        AspectCollector::flushState();

        parent::tearDown();
    }

    public function testHasAspectsReturnsFalseWhenEmpty()
    {
        $this->assertFalse(AspectCollector::hasAspects());
    }

    public function testSetAroundRegistersAspect()
    {
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo::bar'], 5);

        $this->assertTrue(AspectCollector::hasAspects());
        $this->assertSame([
            'priority' => 5,
            'classes' => ['App\Foo::bar'],
        ], AspectCollector::getRule('App\Aspect\FooAspect'));
    }

    public function testSetAroundDefaultsPriorityToZero()
    {
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo']);

        $this->assertSame(0, AspectCollector::getPriority('App\Aspect\FooAspect'));
    }

    public function testSetAroundMergesClassesOnDuplicateRegistration()
    {
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo::bar'], 5);
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Baz::qux'], 5);

        $this->assertSame(
            ['App\Foo::bar', 'App\Baz::qux'],
            AspectCollector::getRule('App\Aspect\FooAspect')['classes']
        );
    }

    public function testGetPriorityReturnsZeroForUnregisteredAspect()
    {
        $this->assertSame(0, AspectCollector::getPriority('NonExistent'));
    }

    public function testGetRuleReturnsEmptyForUnregisteredAspect()
    {
        $this->assertSame([], AspectCollector::getRule('NonExistent'));
    }

    public function testForgetAspectRemovesSpecificAspect()
    {
        AspectCollector::setAround('Aspect1', ['Class1']);
        AspectCollector::setAround('Aspect2', ['Class2']);

        AspectCollector::forgetAspect('Aspect1');

        $this->assertSame([], AspectCollector::getRule('Aspect1'));
        $this->assertNotEmpty(AspectCollector::getRule('Aspect2'));
        $this->assertTrue(AspectCollector::hasAspects());
    }

    public function testFlushStateRemovesAllAspects()
    {
        AspectCollector::setAround('Aspect1', ['Class1']);
        AspectCollector::setAround('Aspect2', ['Class2']);

        AspectCollector::flushState();

        $this->assertFalse(AspectCollector::hasAspects());
        $this->assertSame([], AspectCollector::getRules());
    }

    public function testGetReturnsContainerData()
    {
        AspectCollector::setAround('Aspect1', ['Class1', 'Class2']);

        $this->assertSame(['Class1', 'Class2'], AspectCollector::get('classes.Aspect1'));
    }

    public function testGetReturnsDefaultWhenKeyNotFound()
    {
        $this->assertSame('default', AspectCollector::get('nonexistent', 'default'));
    }

    public function testListReturnsAllContainerData()
    {
        AspectCollector::setAround('Aspect1', ['Class1']);

        $list = AspectCollector::list();
        $this->assertArrayHasKey('classes', $list);
        $this->assertArrayHasKey('Aspect1', $list['classes']);
    }

    public function testGetRulesReturnsAllRules()
    {
        AspectCollector::setAround('Aspect1', ['Class1'], 5);
        AspectCollector::setAround('Aspect2', ['Class2'], 10);

        $rules = AspectCollector::getRules();

        $this->assertCount(2, $rules);
        $this->assertSame(5, $rules['Aspect1']['priority']);
        $this->assertSame(10, $rules['Aspect2']['priority']);
    }
}
