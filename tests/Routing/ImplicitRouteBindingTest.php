<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\ImplicitRouteBindingTest;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Hypervel\Routing\ImplicitRouteBinding;
use Hypervel\Routing\Route;
use Hypervel\Tests\Routing\Fixtures\CategoryBackedEnum;
use Hypervel\Tests\Routing\Fixtures\CategoryEnum;
use Hypervel\Tests\Routing\RoutingTestCase;
use ReflectionProperty;
use WeakMap;

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
        $route->setParameter('user', new ImplicitRouteBindingUser);

        $route->prepareForSerialization();

        $container = Container::getInstance();

        ImplicitRouteBinding::resolveForRoute($container, $route);
    }

    public function testItDoesNotReuseStaleImplicitBindingSignatureParametersWhenClosureObjectIdIsReused()
    {
        $container = Container::getInstance();

        $closureWithNoParameters = function () {
            return 'ok';
        };
        $closureWithEnumParameter = function (CategoryBackedEnum $category) {
            return $category->value;
        };

        $staleSignature = [
            [],
            [],
        ];
        $this->seedImplicitBindingSignatureCache(
            $closureWithNoParameters,
            $closureWithEnumParameter,
            $staleSignature,
        );

        $route = new Route('GET', '/test/{category}', ['uses' => $closureWithEnumParameter]);
        $route->bind(Request::create('/test/fruits'));

        ImplicitRouteBinding::resolveForRoute($container, $route);

        $this->assertInstanceOf(CategoryBackedEnum::class, $route->parameter('category'));
        $this->assertSame('fruits', $route->parameter('category')->value);

        if (property_exists(ImplicitRouteBinding::class, 'closureSignatureCache')) {
            $reflectionProperty = new ReflectionProperty(ImplicitRouteBinding::class, 'closureSignatureCache');
            $cache = $reflectionProperty->getValue();

            $this->assertInstanceOf(WeakMap::class, $cache);
            $this->assertCount(2, $cache);
            $this->assertSame([[], []], $cache[$closureWithNoParameters]);
            $this->assertNotEmpty($cache[$closureWithEnumParameter][1]);
            $this->assertSame('category', $cache[$closureWithEnumParameter][1][0]->getName());
        }
    }

    protected function seedImplicitBindingSignatureCache(
        Closure $staleClosure,
        Closure $targetClosure,
        array $signature,
    ): void {
        if (property_exists(ImplicitRouteBinding::class, 'closureSignatureCache')) {
            $reflectionProperty = new ReflectionProperty(ImplicitRouteBinding::class, 'closureSignatureCache');
            $cache = $reflectionProperty->getValue();

            if (! $cache instanceof WeakMap) {
                $cache = new WeakMap;
            }

            $cache[$staleClosure] = $signature;
            $reflectionProperty->setValue(null, $cache);

            return;
        }

        $reflectionProperty = new ReflectionProperty(ImplicitRouteBinding::class, 'signatureCache');
        $cache = $reflectionProperty->getValue();
        $cache[(string) spl_object_id($targetClosure)] = $signature;
        $reflectionProperty->setValue(null, $cache);
    }
}

class ImplicitRouteBindingUser extends Model
{
}
