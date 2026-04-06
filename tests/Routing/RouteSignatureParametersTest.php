<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteSignatureParametersTest;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Routing\RouteSignatureParameters;
use Hypervel\Tests\Routing\RoutingTestCase;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionFunction;
use ReflectionParameter;
use ReflectionProperty;
use WeakMap;

/**
 * @internal
 * @coversNothing
 */
class RouteSignatureParametersTest extends RoutingTestCase
{
    protected function tearDown(): void
    {
        RouteSignatureParameters::flushCache();

        parent::tearDown();
    }

    public function testItCanExtractTheRouteActionSignatureParameters()
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

    public function testItDoesNotReuseStaleClosureSignatureParametersWhenClosureObjectIdIsReused()
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
            $closureWithModelParameter,
            $staleParameters,
        );

        $parameters = RouteSignatureParameters::fromAction(['uses' => $closureWithModelParameter]);

        $this->assertContainsOnlyInstancesOf(ReflectionParameter::class, $parameters);
        $this->assertCount(1, $parameters);
        $this->assertSame('user', $parameters[0]->getName());

        if (property_exists(RouteSignatureParameters::class, 'closureCache')) {
            $reflectionProperty = new ReflectionProperty(RouteSignatureParameters::class, 'closureCache');
            $cache = $reflectionProperty->getValue();

            $this->assertInstanceOf(WeakMap::class, $cache);
            $this->assertCount(2, $cache);
            $this->assertSame('user', $cache[$closureWithModelParameter][0]->getName());
            $this->assertSame([], $cache[$closureWithNoParameters]);
        }
    }

    protected function seedRouteSignatureCacheWithStaleParameters(
        Closure $staleClosure,
        Closure $targetClosure,
        array $parameters,
    ): void {
        if (property_exists(RouteSignatureParameters::class, 'closureCache')) {
            $reflectionProperty = new ReflectionProperty(RouteSignatureParameters::class, 'closureCache');
            $cache = $reflectionProperty->getValue();

            if (! $cache instanceof WeakMap) {
                $cache = new WeakMap;
            }

            $cache[$staleClosure] = $parameters;
            $reflectionProperty->setValue(null, $cache);

            return;
        }

        $reflectionProperty = new ReflectionProperty(RouteSignatureParameters::class, 'cache');
        $cache = $reflectionProperty->getValue();
        $cache['closure_' . spl_object_id($targetClosure)] = $parameters;
        $reflectionProperty->setValue(null, $cache);
    }
}

class SignatureParametersUser extends Model
{
}
