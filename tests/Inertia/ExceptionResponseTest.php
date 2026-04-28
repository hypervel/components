<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia;

use Hypervel\Contracts\Http\Kernel;
use Hypervel\Inertia\ExceptionResponse;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Middleware;
use Hypervel\Session\Middleware\StartSession;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Inertia\Fixtures\HttpExceptionMiddleware;

class ExceptionResponseTest extends TestCase
{
    public function testExceptionsAreNotInterceptedWithoutHandler(): void
    {
        Route::middleware([StartSession::class, Middleware::class])->get('/', function () {
            abort(500);
        });

        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertStatus(500);
        $this->assertFalse($response->headers->has('X-Inertia'));
    }

    public function testExceptionsCanRenderInertiaPages(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            return $response->render('Error', [
                'status' => $response->statusCode(),
                'message' => $response->exception->getMessage(),
            ]);
        });

        Route::middleware([StartSession::class, Middleware::class])->get('/', function () {
            abort(500, 'Something went wrong');
        });

        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertStatus(500);
        $response->assertJson([
            'component' => 'Error',
            'props' => [
                'status' => 500,
                'message' => 'Something went wrong',
            ],
        ]);
    }

    public function testExceptionsCanReturnRedirects(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            if ($response->statusCode() === 419) {
                return redirect()->to('/login');
            }

            return $response->render('Error', ['status' => $response->statusCode()]);
        });

        Route::middleware([StartSession::class, Middleware::class])->get('/', function () {
            abort(419);
        });

        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertRedirect('/login');
    }

    public function testExceptionsCanFallThroughToDefault(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            if ($response->statusCode() === 500) {
                return null;
            }

            return $response->render('Error', ['status' => $response->statusCode()]);
        });

        Route::middleware([StartSession::class, Middleware::class])->get('/', function () {
            abort(500);
        });

        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertStatus(500);
        $this->assertFalse($response->headers->has('X-Inertia'));
    }

    public function testExceptionsWithSharedData(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', HttpExceptionMiddleware::class);

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            return $response->render('Error', ['status' => $response->statusCode()])
                ->withSharedData();
        });

        $response = $this->get('/non-existent-page', ['X-Inertia' => 'true']);

        $response->assertStatus(404);
        $response->assertJson([
            'component' => 'Error',
            'props' => [
                'status' => 404,
                'appName' => 'My App',
            ],
        ]);
    }

    public function testExceptionsWithSharedDataFromExplicitMiddleware(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            return $response->render('Error', ['status' => $response->statusCode()])
                ->usingMiddleware(HttpExceptionMiddleware::class)
                ->withSharedData();
        });

        Route::middleware([StartSession::class, Middleware::class])->get('/', function () {
            abort(500);
        });

        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertStatus(500);
        $response->assertJson([
            'component' => 'Error',
            'props' => [
                'status' => 500,
                'appName' => 'My App',
            ],
        ]);
    }

    public function testExceptionsWithoutSharedData(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', HttpExceptionMiddleware::class);

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            return $response->render('Error', ['status' => $response->statusCode()]);
        });

        $response = $this->get('/non-existent-page', ['X-Inertia' => 'true']);

        $response->assertStatus(404);
        $page = $response->json();
        $this->assertSame('Error', $page['component']);
        $this->assertSame(404, $page['props']['status']);
        $this->assertArrayNotHasKey('appName', $page['props']);
    }

    public function testExceptionsWithCustomRootView(): void
    {
        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            return $response->render('Error', ['status' => $response->statusCode()])
                ->rootView('custom');
        });

        Route::middleware([StartSession::class, Middleware::class])->get('/', function () {
            abort(500);
        });

        $response = $this->get('/', ['X-Inertia' => 'true']);

        $response->assertStatus(500);
        $response->assertJson(['component' => 'Error']);
    }

    public function testExceptionsOutsideMiddlewareAreHandled(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', Middleware::class);

        Inertia::handleExceptionsUsing(function (ExceptionResponse $response) {
            return $response->render('Error', ['status' => $response->statusCode()]);
        });

        $response = $this->get('/non-existent-page', ['X-Inertia' => 'true']);

        $response->assertStatus(404);
        $response->assertJson([
            'component' => 'Error',
            'props' => [
                'status' => 404,
            ],
        ]);
    }
}
