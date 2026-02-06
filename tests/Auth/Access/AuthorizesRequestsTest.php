<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Access;

use Hypervel\Auth\Access\Response;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Auth\Stub\AuthorizesRequestsStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

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

    /**
     * @return Gate|MockInterface
     */
    private function mockGate(): Gate
    {
        $gate = m::mock(Gate::class);

        /** @var Container|MockInterface */
        $container = m::mock(Container::class);

        $container->shouldReceive('get')->with(Gate::class)->andReturn($gate);

        ApplicationContext::setContainer($container);

        return $gate;
    }
}
