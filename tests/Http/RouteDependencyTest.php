<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hyperf\Contract\NormalizerInterface;
use Hyperf\Di\ClosureDefinitionCollectorInterface;
use Hyperf\Di\MethodDefinitionCollectorInterface;
use Hyperf\Di\ReflectionType;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\DispatchedRoute;
use Hypervel\Http\RouteDependency;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @link https://www.php.net/manual/en/function.gd-info.php
 * @internal
 * @coversNothing
 */
class RouteDependencyTest extends TestCase
{
    public function testAfterResolvingWithInvalidClass()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Class 'invalid' does not exist");

        $routeDependency = $this->getRouteDependency();
        $routeDependency->afterResolving('invalid', function () {
            return true;
        });
    }

    public function testAfterResolving()
    {
        $routeDependency = $this->getRouteDependency();
        $routeDependency->afterResolving(TestResolvingClass::class, function () {
            return true;
        });

        $callbacks = $routeDependency->getAfterResolvingCallbacks(new BarClass());
        $this->assertSame(0, count($callbacks));

        $callbacks = $routeDependency->getAfterResolvingCallbacks(new FooClass());
        $this->assertSame(1, count($callbacks));
        $this->assertTrue($callbacks[0]());
    }

    public function testFireAfterResolvingCallbacks()
    {
        $routeDependency = $this->getRouteDependency();
        $routeDependency->afterResolving(TestResolvingClass::class, function ($foo) {
            $foo->modified = true;
        });

        $routeDependency->fireAfterResolvingCallbacks(
            ['string', $foo = new FooClass()],
            m::mock(DispatchedRoute::class)
        );

        $this->assertTrue($foo->modified);
    }

    public function testGetMethodParameters()
    {
        $methodDefinitionCollector = m::mock(MethodDefinitionCollectorInterface::class);
        $methodDefinitionCollector->shouldReceive('getParameters')
            ->with('controller', 'action')
            ->once()
            ->andReturn([
                new ReflectionType('service', false),
                new ReflectionType('foo', true),
                new ReflectionType('bar', false, ['name' => 'metaValue']),
            ]);

        $normalizer = m::mock(NormalizerInterface::class);
        $normalizer->shouldReceive('denormalize')
            ->with('bar', 'bar')
            ->once()
            ->andReturn('bar');

        $container = m::mock(Container::class);
        $container->shouldReceive('has')
            ->with('service')
            ->once()
            ->andReturn(true);
        $container->shouldReceive('get')
            ->with('service')
            ->once()
            ->andReturn('service');
        $container->shouldReceive('has')
            ->with('foo')
            ->once()
            ->andReturn(false);

        $routeDependency = new RouteDependency(
            $container,
            $normalizer,
            $methodDefinitionCollector,
            m::mock(ClosureDefinitionCollectorInterface::class)
        );

        $parameters = $routeDependency->getMethodParameters('controller', 'action', [
            'metaValue' => 'bar',
        ]);

        $this->assertSame(['service', null, 'bar'], $parameters);
    }

    public function testGetClosureParameters()
    {
        $closureDefinitionCollector = m::mock(ClosureDefinitionCollectorInterface::class);
        $closureDefinitionCollector->shouldReceive('getParameters')
            ->with($closure = fn () => true)
            ->once()
            ->andReturn([
                new ReflectionType('service', false),
                new ReflectionType('foo', true),
                new ReflectionType('bar', false, ['name' => 'metaValue']),
            ]);

        $normalizer = m::mock(NormalizerInterface::class);
        $normalizer->shouldReceive('denormalize')
            ->with('bar', 'bar')
            ->once()
            ->andReturn('bar');

        $container = m::mock(Container::class);
        $container->shouldReceive('has')
            ->with('service')
            ->once()
            ->andReturn(true);
        $container->shouldReceive('get')
            ->with('service')
            ->once()
            ->andReturn('service');
        $container->shouldReceive('has')
            ->with('foo')
            ->once()
            ->andReturn(false);

        $routeDependency = new RouteDependency(
            $container,
            $normalizer,
            m::mock(MethodDefinitionCollectorInterface::class),
            $closureDefinitionCollector
        );

        $parameters = $routeDependency->getClosureParameters($closure, [
            'metaValue' => 'bar',
        ]);

        $this->assertSame(['service', null, 'bar'], $parameters);
    }

    protected function getRouteDependency(): RouteDependency
    {
        return new RouteDependency(
            m::mock(Container::class),
            m::mock(NormalizerInterface::class),
            m::mock(MethodDefinitionCollectorInterface::class),
            m::mock(ClosureDefinitionCollectorInterface::class)
        );
    }
}

class TestResolvingClass
{
}

class FooClass extends TestResolvingClass
{
    public bool $modified = false;
}

class BarClass
{
}
