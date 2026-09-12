<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Mail\Mailable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Reflector;
use Hypervel\Support\Testing\Fakes\BusFake;
use Hypervel\Support\Testing\Fakes\MailFake;
use Hypervel\Support\Testing\Fakes\PendingMailFake;
use Hypervel\Tests\Support\Fixtures\ChildClass;
use Hypervel\Tests\Support\Fixtures\NumAttr;
use Hypervel\Tests\Support\Fixtures\ParentClass;
use Hypervel\Tests\Support\Fixtures\ParentOnlyAttr;
use Hypervel\Tests\Support\Fixtures\StrAttr;
use Hypervel\Tests\Support\Fixtures\UnusedAttr;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class SupportReflectorTest extends TestCase
{
    public function testGetClassName(): void
    {
        $method = (new ReflectionClass(PendingMailFake::class))->getMethod('send');

        $this->assertSame(Mailable::class, Reflector::getParameterClassName($method->getParameters()[0]));
    }

    public function testEmptyClassName(): void
    {
        $method = (new ReflectionClass(MailFake::class))->getMethod('assertSent');

        $this->assertNull(Reflector::getParameterClassName($method->getParameters()[0]));
    }

    public function testStringTypeName(): void
    {
        $method = (new ReflectionClass(BusFake::class))->getMethod('dispatchedAfterResponse');

        $this->assertNull(Reflector::getParameterClassName($method->getParameters()[0]));
    }

    public function testSelfClassName(): void
    {
        $method = (new ReflectionClass(Model::class))->getMethod('newPivot');

        $this->assertSame(Model::class, Reflector::getParameterClassName($method->getParameters()[0]));
    }

    public function testParentClassName(): void
    {
        $method = (new ReflectionClass(B::class))->getMethod('f');

        $this->assertSame(A::class, Reflector::getParameterClassName($method->getParameters()[0]));
    }

    public function testParameterSubclassOfInterface(): void
    {
        $method = (new ReflectionClass(TestClassWithInterfaceSubclassParameter::class))->getMethod('f');

        $this->assertTrue(Reflector::isParameterSubclassOf($method->getParameters()[0], IA::class));
    }

    public function testUnionTypeName(): void
    {
        $method = (new ReflectionClass(C::class))->getMethod('f');

        $this->assertNull(Reflector::getParameterClassName($method->getParameters()[0]));
    }

    public function testIsCallable(): void
    {
        $this->assertTrue(Reflector::isCallable(function () {
        }));
        $this->assertTrue(Reflector::isCallable([B::class, 'f']));
        $this->assertFalse(Reflector::isCallable([TestClassWithCall::class, 'f']));
        $this->assertTrue(Reflector::isCallable([new TestClassWithCall, 'f']));
        $this->assertTrue(Reflector::isCallable([TestClassWithCallStatic::class, 'f']));
        $this->assertFalse(Reflector::isCallable([new TestClassWithCallStatic, 'f']));
        $this->assertFalse(Reflector::isCallable([new TestClassWithCallStatic]));
        $this->assertFalse(Reflector::isCallable(['TotallyMissingClass', 'foo']));
        $this->assertTrue(Reflector::isCallable(['TotallyMissingClass', 'foo'], true));
    }

    public function testIsCallableRejectsMalformedCallableArrays(): void
    {
        $this->assertFalse(Reflector::isCallable([true, 'f']));
        $this->assertFalse(Reflector::isCallable([123, 'f']));
        $this->assertFalse(Reflector::isCallable([[], 'f']));
        $this->assertFalse(Reflector::isCallable([true, 'f'], true));
        $this->assertFalse(Reflector::isCallable([B::class, 'f', 'extra']));
        $this->assertFalse(Reflector::isCallable(['class' => B::class, 'method' => 'f']));
    }

    public function testGetClassAttributes(): void
    {
        $this->assertSame([], Reflector::getClassAttributes(ChildClass::class, UnusedAttr::class)->toArray());

        $this->assertSame(
            [ChildClass::class => [], ParentClass::class => []],
            Reflector::getClassAttributes(ChildClass::class, UnusedAttr::class, true)->toArray()
        );

        $this->assertSame(
            ['quick', 'brown', 'fox'],
            Reflector::getClassAttributes(ChildClass::class, StrAttr::class)->map->string->all()
        );

        $this->assertSame(
            ['quick', 'brown', 'fox', 'lazy', 'dog'],
            Reflector::getClassAttributes(ChildClass::class, StrAttr::class, true)->flatten()->map->string->all()
        );

        $this->assertSame(7, Reflector::getClassAttributes(ChildClass::class, NumAttr::class)->sum->number);
        $this->assertSame(12, Reflector::getClassAttributes(ChildClass::class, NumAttr::class, true)->flatten()->sum->number);
        $this->assertSame(5, Reflector::getClassAttributes(ParentClass::class, NumAttr::class)->sum->number);
        $this->assertSame(5, Reflector::getClassAttributes(ParentClass::class, NumAttr::class, true)->flatten()->sum->number);

        $this->assertSame(
            [ChildClass::class, ParentClass::class],
            Reflector::getClassAttributes(ChildClass::class, StrAttr::class, true)->keys()->all()
        );

        $this->assertContainsOnlyInstancesOf(
            StrAttr::class,
            Reflector::getClassAttributes(ChildClass::class, StrAttr::class)->all()
        );

        $this->assertContainsOnlyInstancesOf(
            StrAttr::class,
            Reflector::getClassAttributes(ChildClass::class, StrAttr::class, true)->flatten()->all()
        );
    }

    public function testGetClassAttribute(): void
    {
        $this->assertNull(Reflector::getClassAttribute(ChildClass::class, UnusedAttr::class));
        $this->assertNull(Reflector::getClassAttribute(ChildClass::class, UnusedAttr::class, true));
        $this->assertNull(Reflector::getClassAttribute(ChildClass::class, ParentOnlyAttr::class));
        $this->assertInstanceOf(ParentOnlyAttr::class, Reflector::getClassAttribute(ChildClass::class, ParentOnlyAttr::class, true));
        $this->assertInstanceOf(StrAttr::class, Reflector::getClassAttribute(ChildClass::class, StrAttr::class));
        $this->assertInstanceOf(StrAttr::class, Reflector::getClassAttribute(ChildClass::class, StrAttr::class, true));
        $this->assertSame('quick', Reflector::getClassAttribute(ChildClass::class, StrAttr::class)->string);
        $this->assertSame('quick', Reflector::getClassAttribute(ChildClass::class, StrAttr::class, true)->string);
        $this->assertSame('lazy', Reflector::getClassAttribute(ParentClass::class, StrAttr::class)->string);
    }
}

class A
{
}

class B extends A
{
    public function f(parent $x)
    {
    }
}

class C
{
    public function f(A|Model $x)
    {
    }
}

class TestClassWithCall
{
    public function __call($method, $parameters)
    {
    }
}

class TestClassWithCallStatic
{
    public static function __callStatic($method, $parameters)
    {
    }
}

interface IA
{
}

interface IB extends IA
{
}

class TestClassWithInterfaceSubclassParameter
{
    public function f(IB $x)
    {
    }
}
