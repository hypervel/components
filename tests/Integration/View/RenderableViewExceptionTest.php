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

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app['config']->set('view.paths', [__DIR__ . '/templates']);
    }
}

class RenderableException extends Exception
{
    public function render(Request $request): Response
    {
        return new Response('This is a renderable exception.');
    }
}
