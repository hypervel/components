<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Access\Response;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\Auth\Fixtures\AuthorizesRequestsStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JMac\Testing\OverriddenDouble;
use stdClass;

class AuthorizesRequestsTest extends TestCase
{
    use VerifiesDoubles;

    public function testAuthorize(): void
    {
        $response = Response::allow();

        $gate = $this->mockGate();

        $gate->expects('authorize')->with('foo', ['bar'])->returns($response);

        $this->assertSame($response, (new AuthorizesRequestsStub)->authorize('foo', ['bar']));
    }

    public function testAuthorizeMayBeGuessedPassingModelInstance(): void
    {
        $model = new class extends Model {};
        $response = Response::allow();

        $gate = $this->mockGate();

        $gate->expects('authorize')->with(__FUNCTION__, Argument::same($model))->returns($response);

        $this->assertSame($response, (new AuthorizesRequestsStub)->authorize($model));
    }

    public function testAuthorizeMayBeGuessedPassingClassName(): void
    {
        $class = Model::class;
        $response = Response::allow();

        $gate = $this->mockGate();

        $gate->expects('authorize')->with(__FUNCTION__, $class)->returns($response);

        $this->assertSame($response, (new AuthorizesRequestsStub)->authorize($class));
    }

    public function testAuthorizeMayBeGuessedAndNormalized(): void
    {
        $model = new class extends Model {};
        $response = Response::allow();

        $gate = $this->mockGate();

        $gate->expects('authorize')->with('create', Argument::same($model))->returns($response);

        $this->assertSame($response, (new class extends AuthorizesRequestsStub {
            public function store($model)
            {
                return $this->authorize($model);
            }
        })->store($model));
    }

    public function testAuthorizeForUserDelegatesToGateForUser(): void
    {
        $response = Response::allow();
        $user = new stdClass;

        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('authorize')->with('foo', ['bar'])->returns($response);

        $this->assertSame($response, (new AuthorizesRequestsStub)->authorizeForUser($user, 'foo', ['bar']));
    }

    /**
     * Create and bind an authorization gate double.
     */
    private function mockGate(): OverriddenDouble
    {
        $gate = Double::for(Gate::class, override: true);

        $container = new Container;
        $container->instance(Gate::class, $gate->instance());
        Container::setInstance($container);

        return $gate;
    }
}
