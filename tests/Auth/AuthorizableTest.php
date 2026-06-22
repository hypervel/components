<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Tests\Auth\Fixtures\AuthorizableStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

class AuthorizableTest extends TestCase
{
    public function testCan(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with('foo', ['bar'])->once()->andReturnTrue();

        $this->assertTrue($user->can('foo', ['bar']));
    }

    public function testCanAny(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('any')->with(['foo'], ['bar'])->once()->andReturnTrue();

        $this->assertTrue($user->canAny(['foo'], ['bar']));
    }

    public function testCant(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with('foo', ['bar'])->once()->andReturnTrue();

        $this->assertFalse($user->cant('foo', ['bar']));
    }

    public function testCannot(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with('foo', ['bar'])->once()->andReturnTrue();

        $this->assertFalse($user->cannot('foo', ['bar']));
    }

    public function testCanAcceptsUnitEnumAbilities(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with(AuthorizableTestAbility::ManageUsers, ['bar'])->once()->andReturnTrue();

        $this->assertTrue($user->can(AuthorizableTestAbility::ManageUsers, ['bar']));
    }

    public function testCanAnyAcceptsUnitEnumAbilities(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('any')->with(AuthorizableTestAbility::ManageUsers, ['bar'])->once()->andReturnTrue();

        $this->assertTrue($user->canAny(AuthorizableTestAbility::ManageUsers, ['bar']));
    }

    public function testCantAcceptsUnitEnumAbilities(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with(AuthorizableTestAbility::ManageUsers, ['bar'])->once()->andReturnTrue();

        $this->assertFalse($user->cant(AuthorizableTestAbility::ManageUsers, ['bar']));
    }

    public function testCannotAcceptsUnitEnumAbilities(): void
    {
        $user = new AuthorizableStub;
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with(AuthorizableTestAbility::ManageUsers, ['bar'])->once()->andReturnTrue();

        $this->assertFalse($user->cannot(AuthorizableTestAbility::ManageUsers, ['bar']));
    }

    /**
     * @return Gate|MockInterface
     */
    private function mockGate(): Gate
    {
        $gate = m::mock(Gate::class);

        $container = new Container;
        $container->instance(Gate::class, $gate);
        Container::setInstance($container);

        return $gate;
    }
}

enum AuthorizableTestAbility
{
    case ManageUsers;
}
