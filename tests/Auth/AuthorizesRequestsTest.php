<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Access\Response;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Auth\Fixtures\AuthorizesRequestsStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class AuthorizesRequestsTest extends TestCase
{
    public function testAuthorize()
    {
        $response = m::mock(Response::class);

        $gate = $this->mockGate();

        $gate->shouldReceive('authorize')->with('foo', ['bar'])->once()->andReturn($response);

        $this->assertEquals($response, (new AuthorizesRequestsStub())->authorize('foo', ['bar']));
    }

    public function testAuthorizeMayBeGuessedPassingModelInstance()
    {
        $model = new class extends Model {};
        $response = m::mock(Response::class);

        $gate = $this->mockGate();

        $gate->shouldReceive('authorize')->with(__FUNCTION__, $model)->once()->andReturn($response);

        $this->assertEquals($response, (new AuthorizesRequestsStub())->authorize($model));
    }

    public function testAuthorizeMayBeGuessedPassingClassName()
    {
        $class = Model::class;
        $response = m::mock(Response::class);

        $gate = $this->mockGate();

        $gate->shouldReceive('authorize')->with(__FUNCTION__, $class)->once()->andReturn($response);

        $this->assertEquals($response, (new AuthorizesRequestsStub())->authorize($class));
    }

    public function testAuthorizeMayBeGuessedAndNormalized()
    {
        $model = new class extends Model {};
        $response = m::mock(Response::class);

        $gate = $this->mockGate();

        $gate->shouldReceive('authorize')->with('create', $model)->once()->andReturn($response);

        $this->assertEquals($response, (new class extends AuthorizesRequestsStub {
            public function store($model)
            {
                return $this->authorize($model);
            }
        })->store($model));
    }

    public function testAuthorizeForUserDelegatesToGateForUser()
    {
        $response = m::mock(Response::class);
        $user = new stdClass();

        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('authorize')->with('foo', ['bar'])->once()->andReturn($response);

        $this->assertEquals($response, (new AuthorizesRequestsStub())->authorizeForUser($user, 'foo', ['bar']));
    }

    /**
     * @return Gate|MockInterface
     */
    private function mockGate(): Gate
    {
        $gate = m::mock(Gate::class);

        $container = new Container();
        $container->instance(Gate::class, $gate);
        Container::setInstance($container);

        return $gate;
    }
}
