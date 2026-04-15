<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteBindingTest;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Routing\Route;
use Hypervel\Routing\RouteBinding;
use Hypervel\Tests\Routing\RoutingTestCase;

class RouteBindingTest extends RoutingTestCase
{
    public function testItCanResolveTheExplicitModelForTheGivenRoute()
    {
        $container = Container::getInstance();

        $route = new Route('GET', '/users/{user}', function () {
        });

        $callback = RouteBinding::forModel($container, ExplicitRouteBindingUser::class);
        $this->assertInstanceOf(ExplicitRouteBindingUser::class, $callback(1, $route));
    }

    public function testItCannotResolveTheExplicitSoftDeletedModelForTheGivenRoute()
    {
        $container = Container::getInstance();

        $route = new Route('GET', '/users/{user}', function () {
        });

        $callback = RouteBinding::forModel($container, ExplicitRouteBindingSoftDeletableUser::class);

        $this->expectException(ModelNotFoundException::class);
        $callback(1, $route);
    }

    public function testItCanResolveTheExplicitSoftDeletedModelForTheGivenRouteWithTrashed()
    {
        $container = Container::getInstance();

        $route = (new Route('GET', '/users/{user}', function () {
        }))->withTrashed();

        $callback = RouteBinding::forModel($container, ExplicitRouteBindingSoftDeletableUser::class);
        $this->assertInstanceOf(ExplicitRouteBindingSoftDeletableUser::class, $callback(1, $route));
    }
}

class ExplicitRouteBindingUser extends Model
{
    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        return new static;
    }
}

class ExplicitRouteBindingSoftDeletableUser extends Model
{
    use SoftDeletes;

    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        return null;
    }

    public function resolveSoftDeletableRouteBinding(mixed $value, ?string $field = null): ?self
    {
        return new static;
    }
}
