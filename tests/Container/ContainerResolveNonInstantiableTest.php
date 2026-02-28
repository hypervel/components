<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ContainerResolveNonInstantiableTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::destroyAll();

        parent::tearDown();
    }

    public function testResolvingNonInstantiableWithDefaultRemovesWiths()
    {
        $container = new Container();
        $object = $container->make(ParentClass::class, ['i' => 42]);

        $this->assertSame(42, $object->i);
    }

    public function testResolvingNonInstantiableWithVariadicRemovesWiths()
    {
        $container = new Container();
        $parent = $container->make(VariadicParentClass::class, ['i' => 42]);

        $this->assertCount(0, $parent->child->objects);
        $this->assertSame(42, $parent->i);
    }

    public function testResolveVariadicPrimitive()
    {
        $container = new Container();
        $parent = $container->make(VariadicPrimitive::class);

        $this->assertSame($parent->params, []);
    }

    public function testTraitResolutionGivesNotInstantiableError(): void
    {
        $container = new Container();

        $this->expectException(BindingResolutionException::class);
        $this->expectExceptionMessage('Target [' . NonInstantiableTrait::class . '] is not instantiable.');

        $container->build(NonInstantiableTrait::class);
    }
}

interface TestInterface
{
}

class ParentClass
{
    /**
     * @var int
     */
    public $i;

    public function __construct(?TestInterface $testObject = null, int $i = 0)
    {
        $this->i = $i;
    }
}

class VariadicParentClass
{
    /**
     * @var \Hypervel\Tests\Container\ChildClass
     */
    public $child;

    /**
     * @var int
     */
    public $i;

    public function __construct(ChildClass $child, int $i = 0)
    {
        $this->child = $child;
        $this->i = $i;
    }
}

class ChildClass
{
    /**
     * @var array
     */
    public $objects;

    public function __construct(TestInterface ...$objects)
    {
        $this->objects = $objects;
    }
}

class VariadicPrimitive
{
    /**
     * @var array
     */
    public $params;

    public function __construct(...$params)
    {
        $this->params = $params;
    }
}

trait NonInstantiableTrait
{
}
