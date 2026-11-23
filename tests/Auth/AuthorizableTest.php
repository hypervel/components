<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Auth\Access\Response;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Tests\Auth\Fixtures\AuthorizableStub;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JMac\Testing\OverriddenDouble;

class AuthorizableTest extends TestCase
{
    use VerifiesDoubles;

    public function testAuthorize(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();
        $response = Response::allow();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('authorize')->with('foo', ['bar'])->returns($response);

        $this->assertSame($response, $user->authorize('foo', ['bar']));
    }

    public function testCan(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('check')->with('foo', ['bar'])->returns(true);

        $this->assertTrue($user->can('foo', ['bar']));
    }

    public function testCanAny(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('any')->with(['foo'], ['bar'])->returns(true);

        $this->assertTrue($user->canAny(['foo'], ['bar']));
    }

    public function testCant(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('check')->with('foo', ['bar'])->returns(true);

        $this->assertFalse($user->cant('foo', ['bar']));
    }

    public function testCannot(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('check')->with('foo', ['bar'])->returns(true);

        $this->assertFalse($user->cannot('foo', ['bar']));
    }

    public function testCanAcceptsUnitEnumAbilities(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('check')->with(AuthorizableTestAbility::ManageUsers, ['bar'])->returns(true);

        $this->assertTrue($user->can(AuthorizableTestAbility::ManageUsers, ['bar']));
    }

    public function testCanAnyAcceptsUnitEnumAbilities(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('any')->with(AuthorizableTestAbility::ManageUsers, ['bar'])->returns(true);

        $this->assertTrue($user->canAny(AuthorizableTestAbility::ManageUsers, ['bar']));
    }

    public function testCantAcceptsUnitEnumAbilities(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('check')->with(AuthorizableTestAbility::ManageUsers, ['bar'])->returns(true);

        $this->assertFalse($user->cant(AuthorizableTestAbility::ManageUsers, ['bar']));
    }

    public function testCannotAcceptsUnitEnumAbilities(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->expects('forUser')->with(Argument::same($user));
        $gate->expects('check')->with(AuthorizableTestAbility::ManageUsers, ['bar'])->returns(true);

        $this->assertFalse($user->cannot(AuthorizableTestAbility::ManageUsers, ['bar']));
    }

    /**
     * Create and bind an authorization gate double.
     */
    private function mockGate(): OverriddenDouble
    {
        $gate = Double::for(Gate::class, override: true)->strict();

        $container = new Container;
        $container->instance(Gate::class, $gate->instance());
        Container::setInstance($container);

        return $gate;
    }
}

enum AuthorizableTestAbility
{
    case ManageUsers;
}
