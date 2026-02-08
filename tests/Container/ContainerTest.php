<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use Closure;
use Hyperf\Di\Exception\InvalidDefinitionException;
use Hyperf\Di\Exception\NotFoundException;
use Hypervel\Container\Container;
use Hypervel\Container\DefinitionSource;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ContainerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        Container::setInstance(
            m::mock(Container::class)
        );
    }

    public function testContainerSingleton()
    {
        $container = Container::setInstance(
            $this->getContainer()
        );

        $this->assertSame($container, Container::getInstance());

        Container::setInstance(
            m::mock(Container::class)
        );

        $container2 = Container::getInstance();

        $this->assertInstanceOf(Container::class, $container2);
        $this->assertNotSame($container, $container2);
    }

    public function testClosureResolution()
    {
        $container = $this->getContainer();
        $container->bind('name', function () {
            return 'Taylor';
        });
        $this->assertSame('Taylor', $container->make('name'));
    }

    public function testBindIfDoesntRegisterIfServiceAlreadyRegistered()
    {
        $container = $this->getContainer();
        $container->bind('name', function () {
            return 'Taylor';
        });
        $container->bindIf('name', function () {
            return 'Dayle';
        });

        $this->assertSame('Taylor', $container->make('name'));
    }

    public function testBindIfDoesRegisterIfServiceNotRegisteredYet()
    {
        $container = $this->getContainer();
        $container->bind('surname', function () {
            return 'Taylor';
        });
        $container->bindIf('name', function () {
            return 'Dayle';
        });

        $this->assertSame('Dayle', $container->make('name'));
    }

    public function testSingletonIfDoesntRegisterIfBindingAlreadyRegistered()
    {
        $container = $this->getContainer();
        $container->bind('class', function () {
            return new stdClass();
        });
        $firstInstantiation = $container->get('class');
        $container->bindIf('class', function () {
            return new ContainerConcreteStub();
        });
        $secondInstantiation = $container->get('class');
        $this->assertSame($firstInstantiation, $secondInstantiation);
    }

    public function testSingletonIfDoesRegisterIfBindingNotRegisteredYet()
    {
        $container = $this->getContainer();
        $container->bind('class', function () {
            return new stdClass();
        });
        $container->bindIf('otherClass', function () {
            return new ContainerConcreteStub();
        });
        $firstInstantiation = $container->get('otherClass');
        $secondInstantiation = $container->get('otherClass');
        $this->assertSame($firstInstantiation, $secondInstantiation);
    }

    public function testSharedClosureResolution()
    {
        $container = $this->getContainer();
        $container->bind('class', function () {
            return new stdClass();
        });
        $firstInstantiation = $container->get('class');
        $secondInstantiation = $container->get('class');
        $this->assertSame($firstInstantiation, $secondInstantiation);
    }

    public function testAutoConcreteResolution()
    {
        $container = $this->getContainer();
        $this->assertInstanceOf(ContainerConcreteStub::class, $container->make(ContainerConcreteStub::class));
    }

    public function testSharedConcreteResolution()
    {
        $container = $this->getContainer();
        $container->bind(ContainerConcreteStub::class);

        $var1 = $container->get(ContainerConcreteStub::class);
        $var2 = $container->get(ContainerConcreteStub::class);
        $this->assertSame($var1, $var2);
    }

    public function testAbstractToConcreteResolution()
    {
        $container = $this->getContainer();
        $container->bind(IContainerContractStub::class, ContainerImplementationStub::class);
        $class = $container->make(ContainerDependentStub::class);
        $this->assertInstanceOf(ContainerImplementationStub::class, $class->impl);
    }

    public function testNestedDependencyResolution()
    {
        $container = $this->getContainer();
        $container->bind(IContainerContractStub::class, ContainerImplementationStub::class);
        $class = $container->make(ContainerNestedDependentStub::class);
        $this->assertInstanceOf(ContainerDependentStub::class, $class->inner);
        $this->assertInstanceOf(ContainerImplementationStub::class, $class->inner->impl);
    }

    public function testContainerIsPassedToResolvers()
    {
        $container = $this->getContainer();
        $container->bind('something', function ($c) {
            return $c;
        });
        $c = $container->make('something');
        $this->assertSame($c, $container);
    }

    public function testArrayAccess()
    {
        $container = $this->getContainer();
        $this->assertFalse(isset($container['something']));
        $container['something'] = function () {
            return 'foo';
        };
        $this->assertTrue(isset($container['something']));
        $this->assertNotEmpty($container['something']);
        $this->assertSame('foo', $container['something']);
        unset($container['something']);
        $this->assertFalse(isset($container['something']));

        // test offsetSet when it's not instanceof Closure
        $container = $this->getContainer();
        $container['something'] = 'text';
        $this->assertFalse(isset($container['something']));
    }

    public function testAliases()
    {
        $container = $this->getContainer();
        $container['foo'] = function () {
            return 'bar';
        };
        $container->alias('foo', 'baz');
        $container->alias('baz', 'bat');
        $this->assertSame('bar', $container->make('foo'));
        $this->assertSame('bar', $container->make('baz'));
        $this->assertSame('bar', $container->make('bat'));
    }

    public function testAliasesWithArrayOfParameters()
    {
        $container = $this->getContainer();
        $container->bind('foo', function ($app, $config) {
            return $config;
        });
        $container->alias('foo', 'baz');
        $this->assertEquals([1, 2, 3], $container->make('baz', [1, 2, 3]));
    }

    public function testBindingsCanBeOverridden()
    {
        $container = $this->getContainer();
        $container['foo'] = function () {
            return 'bar';
        };
        $container['foo'] = function () {
            return 'baz';
        };
        $this->assertSame('baz', $container['foo']);
    }

    public function testBindingAnInstanceReturnsTheInstance()
    {
        $container = $this->getContainer();

        $bound = new stdClass();
        $resolved = $container->instance('foo', $bound);

        $this->assertInstanceOf(Closure::class, $resolved);
        $this->assertEquals($bound, $resolved());
    }

    public function testBindingAnInstanceAsShared()
    {
        $container = $this->getContainer();
        $bound = new stdClass();
        $container->instance('foo', $bound);
        $object = $container->get('foo');
        $this->assertSame($bound, $object);
    }

    public function testResolutionOfDefaultParameters()
    {
        $container = $this->getContainer();
        $instance = $container->make(ContainerDefaultValueStub::class);
        $this->assertInstanceOf(ContainerConcreteStub::class, $instance->stub);
        $this->assertSame('taylor', $instance->default);
    }

    public function testBound()
    {
        $container = $this->getContainer();
        $container->bind(ContainerConcreteStub::class, function () {});
        $this->assertTrue($container->bound(ContainerConcreteStub::class));
        $this->assertFalse($container->bound(IContainerContractStub::class));

        $container = $this->getContainer();
        $container->bind(IContainerContractStub::class, ContainerConcreteStub::class);
        $this->assertTrue($container->bound(IContainerContractStub::class));
        $this->assertFalse($container->bound(ContainerConcreteStub::class));
    }

    public function testUnsetUnbinddInstances()
    {
        $container = $this->getContainer();
        $container->instance('object', new stdClass());

        // keep original behaviour in hyperf
        $container->unbind('object');
        $this->assertTrue($container->bound('object'));
    }

    public function testUnsetRemoveBoundInstances()
    {
        $container = $this->getContainer();
        $container->instance('object', new stdClass());

        // extended remove function
        unset($container['object']);
        $this->assertFalse($container->bound('object'));
    }

    public function testBoundInstanceAndAliasCheckViaArrayAccess()
    {
        $container = $this->getContainer();
        $container->instance('object', new stdClass());
        $container->alias('object', 'alias');

        $this->assertTrue(isset($container['object']));
        $this->assertTrue(isset($container['alias']));
    }

    public function testReboundListeners()
    {
        unset($_SERVER['__test.rebind']);

        $container = $this->getContainer();
        $container->bind('foo', function () {});
        $container->rebinding('foo', function () {
            $_SERVER['__test.rebind'] = true;
        });
        $container->bind('foo', function () {});

        $this->assertTrue($_SERVER['__test.rebind']);
    }

    public function testReboundListenersOnInstances()
    {
        unset($_SERVER['__test.rebind']);

        $container = $this->getContainer();
        $container->instance('foo', function () {});
        $container->rebinding('foo', function () {
            $_SERVER['__test.rebind'] = true;
        });
        $container->instance('foo', function () {});

        $this->assertTrue($_SERVER['__test.rebind']);
    }

    public function testReboundListenersOnInstancesOnlyFiresIfWasAlreadyBound()
    {
        $_SERVER['__test.rebind'] = false;

        $container = $this->getContainer();
        $container->rebinding('foo', function () {
            $_SERVER['__test.rebind'] = true;
        });
        $container->instance('foo', function () {});

        $this->assertFalse($_SERVER['__test.rebind']);
    }

    public function testInternalClassWithDefaultParameters()
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('Parameter $first of __construct() has no value defined or guessable');

        $container = $this->getContainer();
        $container->make(ContainerMixedPrimitiveStub::class, []);
    }

    public function testBindingResolutionExceptionMessage()
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('Entry "Hypervel\Tests\Container\IContainerContractStub" cannot be resolved: the class is not instantiable');

        $container = $this->getContainer();
        $container->make(IContainerContractStub::class, []);
    }

    public function testBindingResolutionExceptionMessageIncludesBuildStack()
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('Entry "Hypervel\Tests\Container\ContainerDependentStub" cannot be resolved: Entry "Hypervel\Tests\Container\IContainerContractStub" cannot be resolved: the class is not instantiable');

        $container = $this->getContainer();
        $container->make(ContainerDependentStub::class, []);
    }

    public function testBindingResolutionExceptionMessageWhenClassDoesNotExist()
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('No entry or class found for \'Foo\Bar\Baz\DummyClass\'');

        $container = $this->getContainer();
        $container->make('Foo\Bar\Baz\DummyClass');
    }

    public function testForgetInstanceForgetsInstance()
    {
        $container = $this->getContainer();
        $containerConcreteStub = new ContainerConcreteStub();
        $container->instance(ContainerConcreteStub::class, $containerConcreteStub);
        $container->get(ContainerConcreteStub::class);
        $this->assertTrue($container->resolved(ContainerConcreteStub::class));
        $container->forgetInstance(ContainerConcreteStub::class);
        $this->assertFalse($container->resolved(ContainerConcreteStub::class));
    }

    public function testForgetInstancesForgetsAllInstances()
    {
        $container = $this->getContainer();
        $containerConcreteStub1 = new ContainerConcreteStub();
        $containerConcreteStub2 = new ContainerConcreteStub();
        $containerConcreteStub3 = new ContainerConcreteStub();
        $container->instance('Instance1', $containerConcreteStub1);
        $container->instance('Instance2', $containerConcreteStub2);
        $container->instance('Instance3', $containerConcreteStub3);
        $container->get('Instance1');
        $container->get('Instance2');
        $container->get('Instance3');
        $this->assertTrue($container->resolved('Instance1'));
        $this->assertTrue($container->resolved('Instance2'));
        $this->assertTrue($container->resolved('Instance3'));
        $container->forgetInstances();
        $this->assertFalse($container->resolved('Instance1'));
        $this->assertFalse($container->resolved('Instance2'));
        $this->assertFalse($container->resolved('Instance3'));
    }

    public function testContainerFlushFlushesAllBindingsAliasesAndResolvedInstances()
    {
        $container = $this->getContainer();
        $container->bind('ConcreteStub', function () {
            return new ContainerConcreteStub();
        });
        $container->alias('ConcreteStub', 'ContainerConcreteStub');
        $container->get('ConcreteStub');
        $this->assertTrue($container->resolved('ConcreteStub'));
        $this->assertTrue($container->isAlias('ContainerConcreteStub'));
        $this->assertArrayHasKey('ConcreteStub', $container->getBindings());
        $container->flush();
        $this->assertFalse($container->resolved('ConcreteStub'));
        $this->assertFalse($container->isAlias('ContainerConcreteStub'));
        $this->assertArrayHasKey('ConcreteStub', $container->getBindings());
        $this->assertFalse($container->resolved('ConcreteStub'));
    }

    public function testResolvedResolvesAliasToBindingNameBeforeChecking()
    {
        $container = $this->getContainer();
        $container->bind('ConcreteStub', function () {
            return new ContainerConcreteStub();
        });
        $container->alias('ConcreteStub', 'foo');

        $this->assertFalse($container->resolved('ConcreteStub'));
        $this->assertFalse($container->resolved('foo'));

        $container->get('ConcreteStub');

        $this->assertTrue($container->resolved('ConcreteStub'));
        $this->assertTrue($container->resolved('foo'));
    }

    public function testGetAlias()
    {
        $container = $this->getContainer();
        $container->alias('ConcreteStub', 'foo');
        $this->assertSame('ConcreteStub', $container->getAlias('foo'));
    }

    public function testGetAliasRecursive()
    {
        $container = $this->getContainer();
        $container->alias('ConcreteStub', 'foo');
        $container->alias('foo', 'bar');
        $container->alias('bar', 'baz');
        $this->assertSame('ConcreteStub', $container->getAlias('baz'));
        $this->assertTrue($container->isAlias('baz'));
        $this->assertTrue($container->isAlias('bar'));
        $this->assertTrue($container->isAlias('foo'));
    }

    public function testItThrowsExceptionWhenAbstractIsSameAsAlias()
    {
        $this->expectException('LogicException');
        $this->expectExceptionMessage('[name] is aliased to itself.');

        $container = $this->getContainer();
        $container->alias('name', 'name');
    }

    public function testContainerGetFactory()
    {
        $container = $this->getContainer();
        $container->bind('name', function () {
            return 'Taylor';
        });

        $factory = $container->factory('name');
        $this->assertEquals($container->make('name'), $factory());
    }

    public function testMakeWithMethodIsAnAliasForMakeMethod()
    {
        $mock = $this->getMockBuilder(Container::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['make'])
            ->getMock();

        $mock->expects($this->once())
            ->method('make')
            ->with(ContainerDefaultValueStub::class, ['default' => 'laurence'])
            ->willReturn(new stdClass());

        $result = $mock->makeWith(ContainerDefaultValueStub::class, ['default' => 'laurence']);

        $this->assertInstanceOf(stdClass::class, $result);
    }

    public function testResolvingWithArrayOfParameters()
    {
        $container = $this->getContainer();
        $instance = $container->make(ContainerDefaultValueStub::class, ['default' => 'adam']);
        $this->assertSame('adam', $instance->default);

        $instance = $container->make(ContainerDefaultValueStub::class);
        $this->assertSame('taylor', $instance->default);

        $container->bind('foo', function ($app, $config) {
            return $config;
        });

        $this->assertEquals([1, 2, 3], $container->make('foo', [1, 2, 3]));
    }

    public function testResolvingWithArrayOfMixedParameters()
    {
        $container = $this->getContainer();
        $instance = $container->make(ContainerMixedPrimitiveStub::class, ['first' => 1, 'last' => 2, 'third' => 3]);
        $this->assertSame(1, $instance->first);
        $this->assertInstanceOf(ContainerConcreteStub::class, $instance->stub);
        $this->assertSame(2, $instance->last);
        $this->assertFalse(isset($instance->third));
    }

    public function testResolvingWithUsingAnInterface()
    {
        $container = $this->getContainer();
        $container->bind(IContainerContractStub::class, ContainerInjectVariableStubWithInterfaceImplementation::class);
        $instance = $container->make(IContainerContractStub::class, ['something' => 'laurence']);
        $this->assertSame('laurence', $instance->something);
    }

    public function testNestedParameterOverride()
    {
        $container = $this->getContainer();
        $container->bind('foo', function ($app, $config) {
            return $app->make('bar', ['name' => 'Taylor']);
        });
        $container->bind('bar', function ($app, $config) {
            return $config;
        });

        $this->assertEquals(['name' => 'Taylor'], $container->make('foo', ['something']));
    }

    public function testNestedParametersAreResetForFreshMake()
    {
        $container = $this->getContainer();

        $container->bind('foo', function ($app, $config) {
            return $app->make('bar');
        });

        $container->bind('bar', function ($app, $config) {
            return $config;
        });

        $this->assertEquals([], $container->make('foo', ['something']));
    }

    public function testSingletonBindingsNotRespectedWithMakeParameters()
    {
        $container = $this->getContainer();

        $container->bind('foo', function ($app, $config) {
            return $config;
        });

        $this->assertEquals(['name' => 'taylor'], $container->make('foo', ['name' => 'taylor']));
        $this->assertEquals(['name' => 'abigail'], $container->make('foo', ['name' => 'abigail']));
    }

    public function testContainerKnowsEntry()
    {
        $container = $this->getContainer();
        $container->bind(IContainerContractStub::class, ContainerImplementationStub::class);
        $this->assertTrue($container->has(IContainerContractStub::class));
    }

    public function testContainerCanBindAnyWord()
    {
        $container = $this->getContainer();
        $container->bind('Taylor', stdClass::class);
        $this->assertInstanceOf(stdClass::class, $container->get('Taylor'));
    }

    public function testContainerCanDynamicallySetService()
    {
        $container = $this->getContainer();
        $this->assertFalse(isset($container['name']));
        $container['name'] = function () {
            return 'Taylor';
        };
        $this->assertTrue(isset($container['name']));
        $this->assertSame('Taylor', $container['name']);
    }

    public function testUnknownEntryThrowsException()
    {
        $this->expectException(NotFoundException::class);

        $container = $this->getContainer();
        $container->get('Taylor');
    }

    public function testBoundEntriesThrowsContainerExceptionWhenNotResolvable()
    {
        $this->expectException(NotFoundException::class);

        $container = $this->getContainer();
        $container->bind('Taylor', IContainerContractStub::class);

        $container->get('Taylor');
    }

    public function testContainerCanResolveClasses()
    {
        $container = $this->getContainer();
        $class = $container->get(ContainerConcreteStub::class);

        $this->assertInstanceOf(ContainerConcreteStub::class, $class);
    }

    protected function getContainer(array $dependencies = [])
    {
        return new Container(
            new DefinitionSource($dependencies)
        );
    }
}

class CircularAStub
{
    public function __construct(CircularBStub $b)
    {
    }
}

class CircularBStub
{
    public function __construct(CircularCStub $c)
    {
    }
}

class CircularCStub
{
    public function __construct(CircularAStub $a)
    {
    }
}

class ContainerConcreteStub
{
}

interface IContainerContractStub
{
}

class ContainerImplementationStub implements IContainerContractStub
{
}

class ContainerImplementationStubTwo implements IContainerContractStub
{
}

class ContainerDependentStub
{
    public $impl;

    public function __construct(IContainerContractStub $impl)
    {
        $this->impl = $impl;
    }
}

class ContainerNestedDependentStub
{
    public $inner;

    public function __construct(ContainerDependentStub $inner)
    {
        $this->inner = $inner;
    }
}

class ContainerDefaultValueStub
{
    public $stub;

    public $default;

    public function __construct(ContainerConcreteStub $stub, $default = 'taylor')
    {
        $this->stub = $stub;
        $this->default = $default;
    }
}

class ContainerMixedPrimitiveStub
{
    public $first;

    public $last;

    public $stub;

    public function __construct($first, ContainerConcreteStub $stub, $last)
    {
        $this->stub = $stub;
        $this->last = $last;
        $this->first = $first;
    }
}

class ContainerInjectVariableStub
{
    public $something;

    public function __construct(ContainerConcreteStub $concrete, $something)
    {
        $this->something = $something;
    }
}

class ContainerInjectVariableStubWithInterfaceImplementation implements IContainerContractStub
{
    public $something;

    public function __construct(ContainerConcreteStub $concrete, $something)
    {
        $this->something = $something;
    }
}

class ContainerContextualBindingCallTarget
{
    public function __construct()
    {
    }

    public function work(IContainerContractStub $stub)
    {
        return $stub;
    }
}
