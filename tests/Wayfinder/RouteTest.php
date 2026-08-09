<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder;

use Hypervel\Contracts\Routing\UrlRoutable;
use Hypervel\Routing\Route as BaseRoute;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Wayfinder\Route;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionMethod;
use stdClass;
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

    public function testControllerPathsOnlyBecomeRelativeInsideTheApplicationBoundary(): void
    {
        $route = $this->routeFor(serialize(SerializableClosure::unsigned(fn () => 'ok')));
        $relativePath = new ReflectionMethod(Route::class, 'relativePath');
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $inside = $base . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controller.php';
        $outside = dirname($base) . DIRECTORY_SEPARATOR . 'external' . DIRECTORY_SEPARATOR . 'Controller.php';
        $sibling = $base . '-backup' . DIRECTORY_SEPARATOR . 'Controller.php';

        $this->assertSame('app/Http/Controller.php', $relativePath->invoke($route, $inside));
        $this->assertSame(str_replace(DIRECTORY_SEPARATOR, '/', $outside), $relativePath->invoke($route, $outside));
        $this->assertSame(str_replace(DIRECTORY_SEPARATOR, '/', $sibling), $relativePath->invoke($route, $sibling));
    }

    public function testBindingAwareDefaultsNormalizeRoutableAndEnumValues(): void
    {
        $default = new WayfinderUrlRoutableStub;
        $default->slug = WayfinderRouteDefault::Team;

        $route = new Route(
            new BaseRoute(['GET'], 'teams/{team:slug}', [WayfinderRouteDefaultsController::class, 'show']),
            new Collection(['team:slug' => $default]),
            null,
        );

        $parameter = $route->parameters()->sole();

        $this->assertTrue($parameter->optional);
        $this->assertFalse($parameter->routeOptional);
        $this->assertSame('engineering', $parameter->default);
        $this->assertSame("'/teams/{team?}'", $route->uri());
    }

    public function testBindingAwareDefaultsRejectPlainKeysAndUnsupportedValues(): void
    {
        $baseRoute = new BaseRoute(['GET'], 'teams/{team:slug}', [WayfinderRouteDefaultsController::class, 'show']);

        $plainDefault = new Route($baseRoute, new Collection(['team' => 'engineering']), null);
        $unsupportedDefault = new Route($baseRoute, new Collection(['team:slug' => new stdClass]), null);

        foreach ([$plainDefault, $unsupportedDefault] as $route) {
            $parameter = $route->parameters()->sole();

            $this->assertFalse($parameter->optional);
            $this->assertFalse($parameter->routeOptional);
            $this->assertNull($parameter->default);
            $this->assertSame("'/teams/{team}'", $route->uri());
        }
    }

    public function testUnboundDefaultsNormalizeEnumsAndRoutableKeys(): void
    {
        $member = new WayfinderUrlRoutableStub;
        $member->routeKey = 42;

        $route = new Route(
            new BaseRoute(['GET'], 'status/{status}/members/{member}', fn () => null),
            new Collection([
                'status' => WayfinderRouteDefault::Active,
                'member' => $member,
            ]),
            null,
        );

        [$status, $member] = $route->parameters()->all();

        $this->assertSame('active', $status->default);
        $this->assertSame(42, $member->default);
        $this->assertSame("'/status/{status?}/members/{member?}'", $route->uri());
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

class WayfinderRouteDefaultsController
{
    public function show(WayfinderUrlRoutableStub $team): void
    {
    }
}

class WayfinderUrlRoutableStub implements UrlRoutable
{
    public int|string $routeKey = 1;

    public string|WayfinderRouteDefault $slug = 'engineering';

    public function getRouteKey(): int|string
    {
        return $this->routeKey;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding(mixed $value, ?string $field = null): mixed
    {
        return null;
    }

    public function resolveChildRouteBinding(string $childType, mixed $value, ?string $field): mixed
    {
        return null;
    }
}

enum WayfinderRouteDefault: string
{
    case Active = 'active';
    case Team = 'engineering';
}

class InsecureWayfinderDeserializationStub
{
    public static bool $instantiated = false;

    public function __wakeup(): void
    {
        self::$instantiated = true;
    }
}
