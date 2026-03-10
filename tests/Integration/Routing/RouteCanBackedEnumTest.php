<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\RouteCanBackedEnumTest;

use Hypervel\Auth\GenericUser;
use Hypervel\Support\Facades\Gate;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Integration\Routing\Fixtures\AbilityBackedEnum;
use Hypervel\Tests\Integration\Routing\RoutingTestCase;

/**
 * @internal
 * @coversNothing
 */
class RouteCanBackedEnumTest extends RoutingTestCase
{
    public function testSimpleRouteWithStringBackedEnumCanAbilityGuestForbiddenThroughTheFramework()
    {
        $gate = Gate::define(AbilityBackedEnum::NotAccessRoute, fn (?GenericUser $user) => false);
        $this->assertArrayHasKey('not-access-route', $gate->abilities());

        $route = Route::get('/', function () {
            return 'Hello World';
        })->can(AbilityBackedEnum::NotAccessRoute);
        $this->assertEquals(['can:not-access-route'], $route->middleware());

        $response = $this->get('/');
        $response->assertForbidden();
    }

    public function testSimpleRouteWithStringBackedEnumCanAbilityGuestAllowedThroughTheFramework()
    {
        $gate = Gate::define(AbilityBackedEnum::AccessRoute, fn (?GenericUser $user) => true);
        $this->assertArrayHasKey('access-route', $gate->abilities());

        $route = Route::get('/', function () {
            return 'Hello World';
        })->can(AbilityBackedEnum::AccessRoute);
        $this->assertEquals(['can:access-route'], $route->middleware());

        $response = $this->get('/');
        $response->assertOk();
        $response->assertContent('Hello World');
    }
}
