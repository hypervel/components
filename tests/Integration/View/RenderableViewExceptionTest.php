<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\View;

use Exception;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\View;
use Hypervel\Testbench\TestCase;

class RenderableViewExceptionTest extends TestCase
{
    public function testRenderMethodOfExceptionThrownInViewGetsHandled(): void
    {
        Route::get('/', function () {
            return View::make('renderable-exception');
        });

        $response = $this->get('/');

        $response->assertSee('This is a renderable exception.');
    }

    public function testResponseRenderedByExceptionThrownInViewGetsHandled(): void
    {
        Route::get('/', function () {
            return View::make('renderable-exception', [
                'exception' => new ResponseRenderableException,
            ]);
        });

        $response = $this->get('/');

        $response->assertStatus(418);
        $response->assertHeader('X-View-Exception', 'handled');
        $response->assertSee('This is a response renderable exception.');
    }

    /**
     * Configure the exception view fixture.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('view.paths', [__DIR__ . '/Fixtures/templates']);
    }
}

class RenderableException extends Exception
{
    /**
     * Render the exception as text.
     */
    public function render(Request $request): string
    {
        return 'This is a renderable exception.';
    }
}

class ResponseRenderableException extends Exception
{
    /**
     * Render the exception as a response.
     */
    public function render(Request $request): Response
    {
        return new Response(
            'This is a response renderable exception.',
            418,
            ['X-View-Exception' => 'handled']
        );
    }
}
