<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\RouteSignatureParametersTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Routing\RouteSignatureParameters;
use Hypervel\Tests\Routing\RoutingTestCase;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionParameter;

/**
 * @internal
 * @coversNothing
 */
class RouteSignatureParametersTest extends RoutingTestCase
{
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
}

class SignatureParametersUser extends Model
{
}
