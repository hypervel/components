<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Vite;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Mockery\MockInterface;

class InteractsWithContainerTest extends TestCase
{
    public function testSwap(): void
    {
        $this->app->instance(InstanceStub::class, new InstanceStub);

        $this->assertSame('foo', $this->app->make(InstanceStub::class)->execute());

        $stub = m::mock(InstanceStub::class);
        $stub->expects('execute')
            ->andReturn('bar');

        $this->swap(InstanceStub::class, $stub);

        $this->assertSame('bar', $this->app->make(InstanceStub::class)->execute());
    }

    public function testMock(): void
    {
        $this->mock(InstanceStub::class)
            ->expects('execute')
            ->andReturn('bar');

        $this->assertSame('bar', $this->app->make(InstanceStub::class)->execute());
    }

    public function testPartialMock(): void
    {
        $this->partialMock(InstanceStub::class, function (MockInterface $mock): void {
            $mock->shouldReceive('partialExecute')->andReturn('mocked');
        });

        $this->assertSame('foo', $this->app->make(InstanceStub::class)->execute());
        $this->assertSame('mocked', $this->app->make(InstanceStub::class)->partialExecute());
    }

    public function testWithoutViteBindsEmptyHandlerAndReturnsInstance(): void
    {
        $instance = $this->withoutVite();

        $this->assertSame('', app(Vite::class)(['resources/js/app.js'])->toHtml());
        $this->assertSame($this, $instance);
    }

    public function testWithoutViteHandlesReactRefresh(): void
    {
        $instance = $this->withoutVite();

        $this->assertSame('', app(Vite::class)->reactRefresh()->toHtml());
        $this->assertSame($this, $instance);
    }

    public function testWithoutViteHandlesAsset(): void
    {
        $instance = $this->withoutVite();

        $this->assertSame('', app(Vite::class)->asset('path/to/asset.png'));
        $this->assertSame($this, $instance);
    }

    public function testWithViteRestoresOriginalHandlerAndReturnsInstance(): void
    {
        $handler = new Vite;
        $this->app->instance(Vite::class, $handler);

        $this->withoutVite();
        $instance = $this->withVite();

        $this->assertSame($handler, resolve(Vite::class));
        $this->assertSame($this, $instance);
    }

    public function testWithoutViteReturnsEmptyArrayForPreloadedAssets(): void
    {
        $instance = $this->withoutVite();

        $this->assertSame([], app(Vite::class)->preloadedAssets());
        $this->assertSame($this, $instance);
    }

    // REMOVED: Mix helpers and tests; Hypervel uses Vite.

    public function testWithoutDefer(): void
    {
        $called = [];

        defer(function () use (&$called): void {
            $called[] = 1;
        });

        $this->assertSame([], $called);

        $instance = $this->withoutDefer();

        defer(function () use (&$called): void {
            $called[] = 2;
        });

        $this->assertSame([2], $called);
        $this->assertSame($this, $instance);

        $this->withDefer();

        $this->assertSame([2], $called);

        $this->app->make(DeferredCallbackCollection::class)->invoke();

        $this->assertSame([2, 1], $called);
    }

    public function testForgetMock(): void
    {
        $this->mock(InstanceStub::class)
            ->expects('execute')
            ->andReturn('bar');

        $this->assertSame('bar', $this->app->make(InstanceStub::class)->execute());

        $this->forgetMock(InstanceStub::class);
        $this->assertSame('foo', $this->app->make(InstanceStub::class)->execute());
    }
}

class InstanceStub
{
    /**
     * Execute the stub.
     */
    public function execute(): string
    {
        return 'foo';
    }

    /**
     * Execute the partially mocked method.
     */
    public function partialExecute(): string
    {
        return 'partial';
    }
}
