<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Tests\Foundation\Testing\ApplicationTestCase;
use Mockery as m;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithContainerTest extends ApplicationTestCase
{
    public function testSwap()
    {
        $this->app->instance(InstanceStub::class, new InstanceStub());

        $this->assertSame('foo', $this->app->get(InstanceStub::class)->execute());

        $stub = m::mock(InstanceStub::class);
        $stub->shouldReceive('execute')
            ->once()
            ->andReturn('bar');

        $this->swap(InstanceStub::class, $stub);

        $this->assertSame('bar', $this->app->get(InstanceStub::class)->execute());
    }

    public function testMock()
    {
        $this->mock(InstanceStub::class)
            ->shouldReceive('execute')
            ->once()
            ->andReturn('bar');

        $this->assertSame('bar', $this->app->get(InstanceStub::class)->execute());

        $this->forgetMock(InstanceStub::class);
        $this->assertSame('foo', $this->app->get(InstanceStub::class)->execute());
    }

    public function testPartialMock()
    {
        $this->partialMock(InstanceStub::class, function (MockInterface $mock) {
            $mock->shouldReceive('partialExecute')->andReturn('mocked');
        });

        $this->assertSame('foo', $this->app->get(InstanceStub::class)->execute());
        $this->assertSame('mocked', $this->app->get(InstanceStub::class)->partialExecute());
    }
}

class InstanceStub
{
    public function execute()
    {
        return 'foo';
    }

    public function partialExecute()
    {
        return 'partial';
    }
}
