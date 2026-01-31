<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Access;

use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Contracts\Container\Container;
use Hypervel\Tests\Auth\Stub\AuthorizableStub;
use Hypervel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class AuthorizableTest extends TestCase
{
    public function testCan()
    {
        $user = new AuthorizableStub();
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with('foo', ['bar'])->once()->andReturnTrue();

        $this->assertTrue($user->can('foo', ['bar']));
    }

    public function testCanAny()
    {
        $user = new AuthorizableStub();
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('any')->with(['foo'], ['bar'])->once()->andReturnTrue();

        $this->assertTrue($user->canAny(['foo'], ['bar']));
    }

    public function testCant()
    {
        $user = new AuthorizableStub();
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with('foo', ['bar'])->once()->andReturnTrue();

        $this->assertFalse($user->cant('foo', ['bar']));
    }

    public function testCannot()
    {
        $user = new AuthorizableStub();
        $gate = $this->mockGate();

        $gate->shouldReceive('forUser')->with($user)->once()->andReturnSelf();
        $gate->shouldReceive('check')->with('foo', ['bar'])->once()->andReturnTrue();

        $this->assertFalse($user->cannot('foo', ['bar']));
    }

    /**
     * @return Gate|MockInterface
     */
    private function mockGate(): Gate
    {
        $gate = Mockery::mock(Gate::class);

        /** @var Container|MockInterface */
        $container = Mockery::mock(Container::class);

        $container->shouldReceive('get')->with(Gate::class)->andReturn($gate);

        ApplicationContext::setContainer($container);

        return $gate;
    }
}
