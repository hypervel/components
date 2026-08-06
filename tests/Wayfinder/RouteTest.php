<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Hypervel\Routing\Route as BaseRoute;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Wayfinder\Route;
use Laravel\SerializableClosure\SerializableClosure;
use Throwable;

class RouteTest extends TestCase
{
    public function testSerializedClosuresStillResolveTheirControllerPath(): void
    {
        $route = $this->routeFor(serialize(SerializableClosure::unsigned(fn () => 'ok')));

        $this->assertSame('[serialized-closure]', $route->controllerPath());
    }

    public function testSerializedClosuresAllowOnlySerializableClosureClasses(): void
    {
        $captured = new InsecureWayfinderDeserializationStub;
        $route = $this->routeFor(serialize(SerializableClosure::unsigned(function () use ($captured) {
            return $captured;
        })));

        InsecureWayfinderDeserializationStub::$instantiated = false;

        try {
            $route->controllerPath();
        } catch (Throwable) {
        }

        $this->assertFalse(InsecureWayfinderDeserializationStub::$instantiated);
    }

    private function routeFor(string $serializedClosure): Route
    {
        return new Route(
            new BaseRoute(['GET'], 'foo', ['uses' => $serializedClosure]),
            new Collection,
            null,
        );
    }
}

class InsecureWayfinderDeserializationStub
{
    public static bool $instantiated = false;

    public function __wakeup(): void
    {
        self::$instantiated = true;
    }
}
