<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Middleware\HandleCorsTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Middleware\HandleCors;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

class HandleCorsTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cors', [
            'paths' => ['api/*'],
            'supports_credentials' => false,
            'allowed_origins' => ['http://localhost'],
            'allowed_origins_patterns' => [],
            'allowed_headers' => ['X-Custom-1', 'X-Custom-2'],
            'allowed_methods' => ['GET', 'POST'],
            'exposed_headers' => [],
            'max_age' => 0,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('api/ping', fn () => 'PONG')->middleware(HandleCors::class);
        Route::get('api/ping', fn () => 'PONG')->middleware(HandleCors::class);
        Route::post('web/ping', fn () => 'PONG');
    }

    public function testPreflightForMatchedPathReturnsCorsHeaders()
    {
        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertStatus(204);
        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testActualRequestForMatchedPathReceivesCorsHeaders()
    {
        $response = $this->call('POST', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        $response->assertOk();
        $response->assertSeeText('PONG');
        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testRequestForUnmatchedPathReceivesNoCorsHeaders()
    {
        $response = $this->call('POST', 'web/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        $response->assertOk();
        $response->assertSeeText('PONG');
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
