<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\FoundationAuthorizesRequestsTraitTest;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Gate;
use Hypervel\Auth\Access\Response;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Foundation\Auth\Access\AuthorizesRequests;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class FoundationAuthorizesRequestsTraitTest extends TestCase
{
    public function testBasicGateCheck()
    {
        unset($_SERVER['_test.authorizes.trait']);

        $gate = $this->getBasicGate();

        $gate->define('baz', function () {
            $_SERVER['_test.authorizes.trait'] = true;

            return true;
        });

        $response = (new AuthorizeTraitClass)->authorize('baz');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($_SERVER['_test.authorizes.trait']);
    }

    public function testAcceptsBackedEnumAsAbility()
    {
        unset($_SERVER['_test.authorizes.trait.enum']);

        $gate = $this->getBasicGate();

        $gate->define('baz', function () {
            $_SERVER['_test.authorizes.trait.enum'] = true;

            return true;
        });

        $response = (new AuthorizeTraitClass)->authorize(Ability::BAZ);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($_SERVER['_test.authorizes.trait.enum']);
    }

    public function testExceptionIsThrownIfGateCheckFails()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $gate = $this->getBasicGate();

        $gate->define('baz', function () {
            return false;
        });

        (new AuthorizeTraitClass)->authorize('baz');
    }

    public function testPoliciesMayBeCalled()
    {
        unset($_SERVER['_test.authorizes.trait.policy']);

        $gate = $this->getBasicGate();

        $gate->policy(AuthorizesRequestTestClass::class, AuthorizesRequestTestPolicy::class);

        $response = (new AuthorizeTraitClass)->authorize('update', new AuthorizesRequestTestClass);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($_SERVER['_test.authorizes.trait.policy']);
    }

    public function testPolicyMethodMayBeGuessedPassingModelInstance()
    {
        unset($_SERVER['_test.authorizes.trait.policy']);

        $gate = $this->getBasicGate();

        $gate->policy(AuthorizesRequestTestClass::class, AuthorizesRequestTestPolicy::class);

        $response = (new AuthorizeTraitClass)->authorize(new AuthorizesRequestTestClass);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($_SERVER['_test.authorizes.trait.policy']);
    }

    public function testPolicyMethodMayBeGuessedPassingClassName()
    {
        unset($_SERVER['_test.authorizes.trait.policy']);

        $gate = $this->getBasicGate();

        $gate->policy('\\' . AuthorizesRequestTestClass::class, AuthorizesRequestTestPolicy::class);

        $response = (new AuthorizeTraitClass)->authorize('\\' . AuthorizesRequestTestClass::class);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($_SERVER['_test.authorizes.trait.policy']);
    }

    public function testPolicyMethodMayBeGuessedAndNormalized()
    {
        unset($_SERVER['_test.authorizes.trait.policy']);

        $gate = $this->getBasicGate();

        $gate->policy(AuthorizesRequestTestClass::class, AuthorizesRequestTestPolicy::class);

        (new AuthorizeTraitClass)->store(new AuthorizesRequestTestClass);

        $this->assertTrue($_SERVER['_test.authorizes.trait.policy']);
    }

    public function getBasicGate(): Gate
    {
        $container = Container::setInstance(new Container);

        $gate = new Gate($container, function () {
            return (object) ['id' => 1];
        });

        $container->instance(GateContract::class, $gate);

        return $gate;
    }
}

class AuthorizesRequestTestClass
{
}

class AuthorizesRequestTestPolicy
{
    public function create(): bool
    {
        $_SERVER['_test.authorizes.trait.policy'] = true;

        return true;
    }

    public function update(): bool
    {
        $_SERVER['_test.authorizes.trait.policy'] = true;

        return true;
    }

    public function testPolicyMethodMayBeGuessedPassingModelInstance(): bool
    {
        $_SERVER['_test.authorizes.trait.policy'] = true;

        return true;
    }

    public function testPolicyMethodMayBeGuessedPassingClassName(): bool
    {
        $_SERVER['_test.authorizes.trait.policy'] = true;

        return true;
    }
}

class AuthorizeTraitClass
{
    use AuthorizesRequests;

    public function store(object $object): void
    {
        $this->authorize($object);
    }
}

enum Ability: string
{
    case BAZ = 'baz';
}
