<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Vite;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class InteractsWithContainerTest extends TestCase
{
    public function testSwap()
    {
        $this->app->instance(InstanceStub::class, new InstanceStub());

        $this->assertSame('foo', $this->app->make(InstanceStub::class)->execute());

        $stub = m::mock(InstanceStub::class);
        $stub->shouldReceive('execute')
            ->once()
            ->andReturn('bar');

        $this->swap(InstanceStub::class, $stub);

        $this->assertSame('bar', $this->app->make(InstanceStub::class)->execute());
    }

    public function testMock()
    {
        $this->mock(InstanceStub::class)
            ->shouldReceive('execute')
            ->once()
            ->andReturn('bar');

        $this->assertSame('bar', $this->app->make(InstanceStub::class)->execute());

        $this->forgetMock(InstanceStub::class);
        $this->assertSame('foo', $this->app->make(InstanceStub::class)->execute());
    }

    public function testPartialMock()
    {
        $this->partialMock(InstanceStub::class, function (MockInterface $mock) {
            $mock->shouldReceive('partialExecute')->andReturn('mocked');
        });

        $this->assertSame('foo', $this->app->make(InstanceStub::class)->execute());
        $this->assertSame('mocked', $this->app->make(InstanceStub::class)->partialExecute());
    }

    public function testWithoutViteBindsEmptyHandlerAndReturnsInstance()
    {
        $instance = $this->withoutVite();

        $this->assertSame('', app(Vite::class)(['resources/js/app.js'])->toHtml());
        $this->assertSame($this, $instance);
    }

    public function testWithoutViteHandlesReactRefresh()
    {
        $instance = $this->withoutVite();

        $this->assertSame('', app(Vite::class)->reactRefresh()->toHtml());
        $this->assertSame($this, $instance);
    }

    public function testWithoutViteHandlesAsset()
    {
        $instance = $this->withoutVite();

        $this->assertSame('', app(Vite::class)->asset('path/to/asset.png'));
        $this->assertSame($this, $instance);
    }

    public function testWithViteRestoresOriginalHandlerAndReturnsInstance()
    {
        $handler = new Vite();
        $this->app->instance(Vite::class, $handler);

        $this->withoutVite();
        $instance = $this->withVite();

        $this->assertSame($handler, resolve(Vite::class));
        $this->assertSame($this, $instance);
    }

    public function testWithoutViteReturnsEmptyArrayForPreloadedAssets()
    {
        $instance = $this->withoutVite();

        $this->assertSame([], app(Vite::class)->preloadedAssets());
        $this->assertSame($this, $instance);
    }

    public function testForgetMock()
    {
        $this->mock(InstanceStub::class)
            ->shouldReceive('execute')
            ->once()
            ->andReturn('bar');

        $this->assertSame('bar', $this->app->make(InstanceStub::class)->execute());

        $this->forgetMock(InstanceStub::class);
        $this->assertSame('foo', $this->app->make(InstanceStub::class)->execute());
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
