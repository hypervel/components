<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteSignatureParametersTest;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Routing\RouteSignatureParameters;
use Hypervel\Tests\Routing\RoutingTestCase;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionException;
use ReflectionFunction;
use ReflectionParameter;
use ReflectionProperty;
use WeakMap;

class RouteSignatureParametersTest extends RoutingTestCase
{
    public function testItCanExtractTheRouteActionSignatureParameters(): void
    {
        $callable = function (SignatureParametersUser $user) {
            return $user;
        };

        $action = ['uses' => serialize(
            new SerializableClosure($callable)
        )];

        $parameters = RouteSignatureParameters::fromAction($action);

        $this->assertContainsOnlyInstancesOf(ReflectionParameter::class, $parameters);
        $this->assertSame('user', $parameters[0]->getName());
    }

    public function testItDoesNotReuseStaleClosureSignatureParametersWhenClosureObjectIdIsReused(): void
    {
        $closureWithNoParameters = function () {
            return 'ok';
        };
        $closureWithModelParameter = function (SignatureParametersUser $user) {
            return $user;
        };

        $staleParameters = (new ReflectionFunction($closureWithNoParameters))->getParameters();
        $this->seedRouteSignatureCacheWithStaleParameters(
            $closureWithNoParameters,
            $staleParameters,
        );

        $parameters = RouteSignatureParameters::fromAction(['uses' => $closureWithModelParameter]);

        $this->assertContainsOnlyInstancesOf(ReflectionParameter::class, $parameters);
        $this->assertCount(1, $parameters);
        $this->assertSame('user', $parameters[0]->getName());

        $reflectionProperty = new ReflectionProperty(RouteSignatureParameters::class, 'objectCache');
        $cache = $reflectionProperty->getValue();

        $this->assertInstanceOf(WeakMap::class, $cache);
        $this->assertCount(2, $cache);
        $this->assertSame('user', $cache[$closureWithModelParameter][0]->getName());
        $this->assertSame([], $cache[$closureWithNoParameters]);
    }

    public function testItExtractsParametersFromInvokableObject(): void
    {
        $invokable = new SignatureParametersInvoker;

        $parameters = RouteSignatureParameters::fromAction(['uses' => $invokable]);

        $this->assertCount(1, $parameters);
        $this->assertSame('user', $parameters[0]->getName());

        $reflectionProperty = new ReflectionProperty(RouteSignatureParameters::class, 'objectCache');
        $cache = $reflectionProperty->getValue();

        $this->assertInstanceOf(WeakMap::class, $cache);
        $this->assertSame('user', $cache[$invokable][0]->getName());
    }

    public function testItExtractsParametersFromExistingControllerMethod(): void
    {
        $parameters = RouteSignatureParameters::fromAction([
            'uses' => SignatureParametersController::class . '@show',
        ]);

        $this->assertCount(1, $parameters);
        $this->assertSame('user', $parameters[0]->getName());
    }

    public function testItReturnsEmptyForMissingMethodOnExistingClass(): void
    {
        $parameters = RouteSignatureParameters::fromAction([
            'uses' => SignatureParametersController::class . '@nonExistent',
        ]);

        $this->assertSame([], $parameters);
    }

    public function testItReturnsEmptyForMagicCallControllerMethod(): void
    {
        $parameters = RouteSignatureParameters::fromAction([
            'uses' => SignatureParametersMagicController::class . '@anything',
        ]);

        $this->assertSame([], $parameters);
    }

    public function testItThrowsForMissingControllerClass(): void
    {
        $this->expectException(ReflectionException::class);

        RouteSignatureParameters::fromAction([
            'uses' => 'Hypervel\Tests\Routing\RouteSignatureParametersTest\NonExistentController@show',
        ]);
    }

    protected function seedRouteSignatureCacheWithStaleParameters(
        Closure $staleClosure,
        array $parameters,
    ): void {
        $reflectionProperty = new ReflectionProperty(RouteSignatureParameters::class, 'objectCache');
        $cache = $reflectionProperty->getValue();

        if (! $cache instanceof WeakMap) {
            $cache = new WeakMap;
        }

        $cache[$staleClosure] = $parameters;
        $reflectionProperty->setValue(null, $cache);
    }
}

class SignatureParametersUser extends Model
{
}

class SignatureParametersController
{
    public function show(SignatureParametersUser $user): SignatureParametersUser
    {
        return $user;
    }
}

class SignatureParametersInvoker
{
    public function __invoke(SignatureParametersUser $user): SignatureParametersUser
    {
        return $user;
    }
}

class SignatureParametersMagicController
{
    public function __call(string $method, array $arguments): mixed
    {
        return null;
    }
}
