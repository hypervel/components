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
use LogicException;

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

    public function testItUsesAFreshModelForEachExplicitRouteBinding(): void
    {
        $container = Container::getInstance();
        $route = new Route('GET', '/users/{user}', function () {
        });
        $callback = RouteBinding::forModel($container, FreshExplicitRouteBindingUser::class);

        $first = $callback(1, $route);
        $second = $callback(2, $route);

        $this->assertSame(1, $first->getKey());
        $this->assertSame(2, $second->getKey());
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

class FreshExplicitRouteBindingUser extends Model
{
    private bool $resolved = false;

    public function resolveRouteBinding(mixed $value, ?string $field = null): ?self
    {
        if ($this->resolved) {
            throw new LogicException('The route binding model was reused.');
        }

        $this->resolved = true;

        return (new static)->setAttribute($this->getRouteKeyName(), $value);
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
