<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Exceptions;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\ExceptionRenderer;
use Hypervel\Foundation\Exceptions\Renderer\Listener;
use Hypervel\Foundation\Exceptions\Renderer\Renderer;
use Hypervel\Foundation\Providers\FoundationServiceProvider;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;
use Throwable;

class RendererTest extends TestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('failed', fn () => throw new RuntimeException('Bad route!'));
        $router->get('failed-with-previous', function () {
            throw new RuntimeException(
                'First exception',
                previous: new RuntimeException(
                    'Second exception',
                    previous: new RuntimeException(
                        'Third exception'
                    )
                )
            );
        });
    }

    #[WithConfig('app.debug', true)]
    public function testItCanRenderExceptionPage()
    {
        $this->assertTrue($this->app->bound(Renderer::class));

        $this->get('/failed')
            ->assertInternalServerError()
            ->assertSee('RuntimeException')
            ->assertSee('Bad route!');
    }

    #[WithConfig('app.debug', false)]
    public function testItCanRenderExceptionPageUsingSymfonyIfRendererIsNotDefined()
    {
        config(['app.debug' => true]);

        $this->assertFalse($this->app->bound(Renderer::class));

        $this->get('/failed')
            ->assertInternalServerError()
            ->assertSee('RuntimeException')
            ->assertSee('Bad route!');
    }

    #[WithConfig('app.debug', true)]
    public function testItCanRenderExceptionPageWithRendererWhenDebugEnabled()
    {
        $this->app->singleton(ExceptionRenderer::class, function () {
            return new class implements ExceptionRenderer {
                public function render(Throwable $throwable): string
                {
                    return 'Custom Exception Renderer: ' . $throwable->getMessage();
                }
            };
        });

        $this->assertTrue($this->app->bound(ExceptionRenderer::class));

        $this->get('/failed')
            ->assertInternalServerError()
            ->assertSee('Custom Exception Renderer: Bad route!');
    }

    #[WithConfig('app.debug', false)]
    public function testItDoesNotRenderExceptionPageWithRendererWhenDebugDisabled()
    {
        $this->app->singleton(ExceptionRenderer::class, function () {
            return new class implements ExceptionRenderer {
                public function render(Throwable $throwable): string
                {
                    return 'Custom Exception Renderer: ' . $throwable->getMessage();
                }
            };
        });

        $this->assertTrue($this->app->bound(ExceptionRenderer::class));

        $this->get('/failed')
            ->assertInternalServerError()
            ->assertDontSee('Custom Exception Renderer: Bad route!');
    }

    #[WithConfig('app.debug', false)]
    public function testItDoesNotRegisterListenersWhenDebugDisabled()
    {
        $this->app->forgetInstance(ExceptionRenderer::class);
        $this->assertFalse($this->app->bound(ExceptionRenderer::class));

        $listener = m::mock(Listener::class);
        $listener->shouldReceive('registerListeners')->never();

        $this->app->instance(Listener::class, $listener);
        $this->app->instance(Dispatcher::class, m::mock(Dispatcher::class));

        $provider = $this->app->getProvider(FoundationServiceProvider::class);
        $provider->boot();
    }

    #[WithConfig('app.debug', true)]
    public function testItDoesNotRegisterListenersWhenRendererBound()
    {
        $this->app->singleton(ExceptionRenderer::class, function () {
            return new class implements ExceptionRenderer {
                public function render(Throwable $throwable): string
                {
                    return 'Custom Exception Renderer: ' . $throwable->getMessage();
                }
            };
        });

        $this->assertTrue($this->app->bound(ExceptionRenderer::class));

        $listener = m::mock(Listener::class);
        $listener->shouldReceive('registerListeners')->never();

        $this->app->instance(Listener::class, $listener);
        $this->app->instance(Dispatcher::class, m::mock(Dispatcher::class));

        $provider = $this->app->getProvider(FoundationServiceProvider::class);
        $provider->boot();
    }

    #[WithConfig('app.debug', true)]
    public function testItRegistersListenersWhenRendererNotBound()
    {
        $this->app->forgetInstance(ExceptionRenderer::class);
        $this->assertFalse($this->app->bound(ExceptionRenderer::class));

        $listener = m::mock(Listener::class);
        $listener->shouldReceive('registerListeners')->once();

        $this->app->instance(Listener::class, $listener);
        $this->app->instance(Dispatcher::class, m::mock(Dispatcher::class));

        $provider = $this->app->getProvider(FoundationServiceProvider::class);
        $provider->boot();
    }

    #[WithConfig('app.debug', true)]
    public function testItRendersPreviousExceptions()
    {
        $this->assertTrue($this->app->bound(Renderer::class));

        $this->get('/failed-with-previous')
            ->assertInternalServerError()
            ->assertSeeInOrder([
                'RuntimeException',
                'First exception',
                'Previous exceptions',
                'Second exception',
                'Third exception',
            ]);
    }

    // REMOVED: testItExcludesDecorativeAsciiArtInNonBrowserContexts - Laravel ASCII art component was removed

    #[WithConfig('app.debug', true)]
    public function testItFallsBackToSymfonyWhenRendererThrows()
    {
        // Replace the Renderer with one that always throws
        $this->app->singleton(Renderer::class, function () {
            return new class {
                public function render(): never
                {
                    throw new RuntimeException('Renderer broke');
                }
            };
        });

        // The Handler's catch block falls back to Symfony's HtmlErrorRenderer,
        // rendering the Renderer's own exception (not the original route exception)
        $this->get('/failed')
            ->assertInternalServerError()
            ->assertSee('Renderer broke');
    }
}
