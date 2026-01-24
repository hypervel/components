<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Access;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ContainerInterface;
use Hypervel\Contracts\Auth\Access\Gate;
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

        /** @var ContainerInterface|MockInterface */
        $container = Mockery::mock(ContainerInterface::class);

        $container->shouldReceive('get')->with(Gate::class)->andReturn($gate);

        ApplicationContext::setContainer($container);

        return $gate;
    }
}
