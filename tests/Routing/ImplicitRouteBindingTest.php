<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\ImplicitRouteBindingTest;

use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Hypervel\Routing\ImplicitRouteBinding;
use Hypervel\Routing\Route;
use Hypervel\Tests\Routing\Fixtures\CategoryBackedEnum;
use Hypervel\Tests\Routing\Fixtures\CategoryEnum;
use Hypervel\Tests\Routing\RoutingTestCase;

/**
 * @internal
 * @coversNothing
 */
class ImplicitRouteBindingTest extends RoutingTestCase
{
    public function testItCanResolveTheImplicitBackedEnumRouteBindingsForTheGivenRoute()
    {
        $action = ['uses' => function (CategoryBackedEnum $category) {
            return $category->value;
        }];

        $route = new Route('GET', '/test/{category}', $action);
        $route->bind(Request::create('/test/fruits'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertSame('fruits', $route->parameter('category')->value);
    }

    public function testItCanResolveTheImplicitBackedEnumRouteBindingsForTheGivenRouteWithOptionalParameter()
    {
        $action = ['uses' => function (?CategoryBackedEnum $category = null) {
            return $category->value;
        }];

        $route = new Route('GET', '/test/{category?}', $action);
        $route->bind(Request::create('/test/fruits'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertSame('fruits', $route->parameter('category')->value);
    }

    public function testItHandlesOptionalImplicitBackedEnumRouteBindingsForTheGivenRouteWithOptionalParameter()
    {
        $action = ['uses' => function (?CategoryBackedEnum $category = null) {
            return $category->value;
        }];

        $route = (new Route('GET', '/test/{category?}', $action))->defaults('category', null);
        $route->bind(Request::create('/test'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertNull($route->parameter('category'));
    }

    public function testItDoesNotResolveImplicitNonBackedEnumRouteBindingsForTheGivenRoute()
    {
        $action = ['uses' => function (CategoryEnum $category) {
            return $category->value;
        }];

        $route = new Route('GET', '/test/{category}', $action);
        $route->bind(Request::create('/test/fruits'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertIsString($route->parameter('category'));
        $this->assertSame('fruits', $route->parameter('category'));
    }

    public function testImplicitBackedEnumInternalException()
    {
        $action = ['uses' => function (CategoryBackedEnum $category) {
            return $category->value;
        }];

        $route = new Route('GET', '/test/{category}', $action);
        $route->bind(Request::create('/test/cars'));

        $route->prepareForSerialization();

        $container = Container::getInstance();

        $this->expectException(BackedEnumCaseNotFoundException::class);
        $this->expectExceptionMessage(sprintf(
            'Case [%s] not found on Backed Enum [%s].',
            'cars',
            CategoryBackedEnum::class,
        ));

        ImplicitRouteBinding::resolveForRoute($container, $route);
    }

    public function testItCanResolveTheImplicitModelRouteBindingsForTheGivenRoute()
    {
        $this->expectNotToPerformAssertions();

        $action = ['uses' => function (ImplicitRouteBindingUser $user) {
            return $user;
        }];

        $route = new Route('GET', '/test/{user}', $action);
        $route->bind(Request::create('/test/1'));
        $route->setParameter('user', new ImplicitRouteBindingUser());

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);
    }
}

class ImplicitRouteBindingUser extends Model
{
}
