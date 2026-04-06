<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Vite;
use Hypervel\Support\Defer\DeferredCallbackCollection;
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
        $this->app->instance(InstanceStub::class, new InstanceStub);

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
        $handler = new Vite;
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

    public function testWithoutDefer()
    {
        $called = [];

        defer(function () use (&$called) {
            $called[] = 1;
        });

        $this->assertSame([], $called);

        $instance = $this->withoutDefer();

        defer(function () use (&$called) {
            $called[] = 2;
        });

        $this->assertSame([2], $called);
        $this->assertSame($this, $instance);

        $this->withDefer();

        $this->assertSame([2], $called);

        $this->app->make(DeferredCallbackCollection::class)->invoke();

        $this->assertSame([2, 1], $called);
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
