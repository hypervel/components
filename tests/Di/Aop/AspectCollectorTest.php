<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\Aop;

use Hypervel\Di\Aop\AspectCollector;
use Hypervel\Di\Exceptions\InvalidDefinitionException;
use Hypervel\Tests\Di\Fixtures\Aspect\TestAspect;
use Hypervel\Tests\TestCase;

class AspectCollectorTest extends TestCase
{
    public function testHasAspectsReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse(AspectCollector::hasAspects());
    }

    public function testSetAroundRegistersAspect(): void
    {
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo::bar'], 5);

        $this->assertTrue(AspectCollector::hasAspects());
        $this->assertSame([
            'priority' => 5,
            'classes' => ['App\Foo::bar'],
        ], AspectCollector::getRule('App\Aspect\FooAspect'));
    }

    public function testSetAroundDefaultsPriorityToZero(): void
    {
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo']);

        $this->assertSame(0, AspectCollector::getPriority('App\Aspect\FooAspect'));
    }

    public function testSetAroundMergesClassesOnDuplicateRegistration(): void
    {
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo::bar'], 5);
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Baz::qux'], 5);

        $this->assertSame(
            ['App\Foo::bar', 'App\Baz::qux'],
            AspectCollector::getRule('App\Aspect\FooAspect')['classes']
        );
    }

    public function testSetAroundDeduplicatesClassesOnInitialRegistration(): void
    {
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo::bar', 'App\Foo::bar'], 5);

        $this->assertSame(
            ['App\Foo::bar'],
            AspectCollector::getRule('App\Aspect\FooAspect')['classes']
        );
    }

    public function testSetAroundDeduplicatesClassesOnRepeatedRegistration(): void
    {
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo::bar'], 5);
        AspectCollector::setAround('App\Aspect\FooAspect', ['App\Foo::bar'], 5);

        $this->assertSame(
            ['App\Foo::bar'],
            AspectCollector::getRule('App\Aspect\FooAspect')['classes']
        );
    }

    public function testGetPriorityReturnsZeroForUnregisteredAspect(): void
    {
        $this->assertSame(0, AspectCollector::getPriority('NonExistent'));
    }

    public function testSealedCollectorRejectsRegistration(): void
    {
        AspectCollector::seal();

        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessageIsOrContains('register()');

        AspectCollector::register(TestAspect::class);
    }

    public function testSealedCollectorRejectsRuleChanges(): void
    {
        AspectCollector::setAround(TestAspect::class, ['App\Foo::bar'], 5);
        $rules = AspectCollector::getRules();
        AspectCollector::seal();

        try {
            AspectCollector::setAround(TestAspect::class, ['App\Baz::qux'], 10);
            $this->fail('Sealed aspect rules must reject changes.');
        } catch (InvalidDefinitionException $exception) {
            $this->assertStringContainsString(TestAspect::class, $exception->getMessage());
            $this->assertSame($rules, AspectCollector::getRules());
        }
    }

    public function testUnsealPreservesRulesAndAllowsRegistration(): void
    {
        AspectCollector::setAround('FirstAspect', ['App\Foo::bar']);
        AspectCollector::seal();
        AspectCollector::unseal();
        AspectCollector::setAround('SecondAspect', ['App\Foo::bar']);

        $this->assertSame(['FirstAspect', 'SecondAspect'], array_keys(AspectCollector::getRules()));
    }

    public function testFlushStateReopensRegistration(): void
    {
        AspectCollector::setAround('FirstAspect', ['App\Foo::bar']);
        AspectCollector::seal();
        AspectCollector::flushState();
        AspectCollector::register(TestAspect::class);

        $this->assertSame([TestAspect::class], array_keys(AspectCollector::getRules()));
    }

    public function testGetRuleReturnsEmptyForUnregisteredAspect(): void
    {
        $this->assertSame([], AspectCollector::getRule('NonExistent'));
    }

    public function testForgetAspectRemovesSpecificAspect(): void
    {
        AspectCollector::setAround('Aspect1', ['Class1']);
        AspectCollector::setAround('Aspect2', ['Class2']);

        AspectCollector::forgetAspect('Aspect1');

        $this->assertSame([], AspectCollector::getRule('Aspect1'));
        $this->assertNotEmpty(AspectCollector::getRule('Aspect2'));
        $this->assertTrue(AspectCollector::hasAspects());
    }

    public function testFlushStateRemovesAllAspects(): void
    {
        AspectCollector::setAround('Aspect1', ['Class1']);
        AspectCollector::setAround('Aspect2', ['Class2']);

        AspectCollector::flushState();

        $this->assertFalse(AspectCollector::hasAspects());
        $this->assertSame([], AspectCollector::getRules());
    }

    public function testGetClassRulesReturnsClassRules(): void
    {
        AspectCollector::setAround('Aspect1', ['Class1', 'Class2']);

        $this->assertSame(['Class1', 'Class2'], AspectCollector::getClassRules()['Aspect1']);
    }

    public function testGetRulesReturnsAllRules(): void
    {
        AspectCollector::setAround('Aspect1', ['Class1'], 5);
        AspectCollector::setAround('Aspect2', ['Class2'], 10);

        $rules = AspectCollector::getRules();

        $this->assertCount(2, $rules);
        $this->assertSame(5, $rules['Aspect1']['priority']);
        $this->assertSame(10, $rules['Aspect2']['priority']);
    }
}
