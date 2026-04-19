<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteActionTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Routing\RouteAction;
use Hypervel\Tests\Routing\RoutingTestCase;
use Laravel\SerializableClosure\SerializableClosure;

class RouteActionTest extends RoutingTestCase
{
    public function testItCanDetectASerializedClosure()
    {
        $callable = function (RouteActionUser $user) {
            return $user;
        };

        $action = ['uses' => serialize(
            new SerializableClosure($callable)
        )];

        $this->assertTrue(RouteAction::containsSerializedClosure($action));

        $action = ['uses' => 'FooController@index'];

        $this->assertFalse(RouteAction::containsSerializedClosure($action));
    }
}

class RouteActionUser extends Model
{
}
