<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Http\Middleware\HandleCors;
use Hypervel\Http\Request;
use Hypervel\Router\Router;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HandleCorsTest extends TestCase
{
    use RunTestsInCoroutine;

    public function setUp(): void
    {
        parent::setUp();

        $config = $this->app->make('config');

        $config->set('cors', [
            'paths' => ['api/*'],
            'supports_credentials' => false,
            'allowed_origins' => ['http://localhost'],
            'allowed_headers' => ['X-Custom-1', 'X-Custom-2'],
            'allowed_methods' => ['GET', 'POST'],
            'exposed_headers' => [],
            'max_age' => 0,
        ]);

        $this->setGlobalMiddleware([
            HandleCors::class,
        ]);

        $this->registerRoutes();
    }

    public function testShouldReturnHeaderAssessControlAllowOriginWhenDontHaveHttpOriginOnRequest()
    {
        $crawler = $this->options('api/ping', [], [
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://localhost', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(204, $crawler->getStatusCode());
    }

    public function testOptionsAllowOriginAllowed()
    {
        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://localhost', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(204, $crawler->getStatusCode());
    }

    public function testAllowAllOrigins()
    {
        $this->app->make('config')->set('cors.allowed_origins', ['*']);

        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://laravel.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('*', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(204, $crawler->getStatusCode());
    }

    public function testAllowAllOriginsWildcard()
    {
        $this->app->make('config')->set('cors.allowed_origins', ['*.laravel.com']);

        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://test.laravel.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://test.laravel.com', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(204, $crawler->getStatusCode());
    }

    public function testOriginsWildcardIncludesNestedSubdomains()
    {
        $this->app->make('config')->set('cors.allowed_origins', ['*.laravel.com']);

        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://api.service.test.laravel.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://api.service.test.laravel.com', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(204, $crawler->getStatusCode());
    }

    public function testAllowAllOriginsWildcardNoMatch()
    {
        $this->app->make('config')->set('cors.allowed_origins', ['*.laravel.com']);

        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://test.symfony.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertEquals(null, $crawler->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testOptionsAllowOriginAllowedNonExistingRoute()
    {
        $crawler = $this->options('api/pang', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://localhost', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(204, $crawler->getStatusCode());
    }

    public function testOptionsAllowOriginNotAllowed()
    {
        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://otherhost',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://localhost', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAllowMethodAllowed()
    {
        $crawler = $this->post('web/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
        ]);
        $this->assertEquals(null, $crawler->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertEquals(200, $crawler->getStatusCode());

        $this->assertSame('PONG', $crawler->getContent());
    }

    public function testAllowMethodNotAllowed()
    {
        $crawler = $this->post('web/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'PUT',
        ]);
        $this->assertEquals(null, $crawler->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertEquals(200, $crawler->getStatusCode());
    }

    public function testAllowHeaderAllowedOptions()
    {
        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-custom-1, x-custom-2',
        ]);
        $this->assertSame('x-custom-1, x-custom-2', $crawler->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertEquals(204, $crawler->getStatusCode());

        $this->assertSame('', $crawler->getContent());
    }

    public function testAllowHeaderAllowedWildcardOptions()
    {
        $this->app->make('config')->set('cors.allowed_headers', ['*']);

        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-custom-3',
        ]);
        $this->assertSame('x-custom-3', $crawler->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertEquals(204, $crawler->getStatusCode());

        $this->assertSame('', $crawler->getContent());
    }

    public function testAllowHeaderNotAllowedOptions()
    {
        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-custom-3',
        ]);
        $this->assertSame('x-custom-1, x-custom-2', $crawler->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testAllowHeaderAllowed()
    {
        $crawler = $this->post('web/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Headers' => 'x-custom-1, x-custom-2',
        ]);
        $this->assertEquals(null, $crawler->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertEquals(200, $crawler->getStatusCode());

        $this->assertSame('PONG', $crawler->getContent());
    }

    public function testAllowHeaderAllowedWildcard()
    {
        $this->app->make('config')->set('cors.allowed_headers', ['*']);

        $crawler = $this->post('web/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Headers' => 'x-custom-3',
        ]);
        $this->assertEquals(null, $crawler->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertEquals(200, $crawler->getStatusCode());

        $this->assertSame('PONG', $crawler->getContent());
    }

    public function testAllowHeaderNotAllowed()
    {
        $crawler = $this->post('web/ping', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Headers' => 'x-custom-3',
        ]);
        $this->assertEquals(null, $crawler->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertEquals(200, $crawler->getStatusCode());
    }

    public function testError()
    {
        $crawler = $this->post('api/error', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://localhost', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(500, $crawler->getStatusCode());
    }

    public function testValidationException()
    {
        $crawler = $this->post('api/validation', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
        ]);
        $this->assertSame('http://localhost', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(422, $crawler->getStatusCode());
    }

    public function testSubclassCanOverrideCorsConfig(): void
    {
        // Replace middleware with custom subclass that allows a different origin
        $this->setGlobalMiddleware([
            CustomHandleCors::class,
        ]);

        // Request with the custom origin (not in the base config)
        $crawler = $this->options('api/ping', [], [
            'Origin' => 'http://custom.example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        // The custom origin should be allowed because CustomHandleCors adds it
        $this->assertSame('http://custom.example.com', $crawler->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEquals(204, $crawler->getStatusCode());
    }

    protected function registerRoutes()
    {
        $router = $this->app->make(Router::class);

        $router->post('web/ping', function () {
            return 'PONG';
        });

        $router->post('api/ping', function () {
            return 'PONG';
        });

        $router->put('api/ping', function () {
            return 'PONG';
        });

        $router->post('api/error', function () {
            abort(500);
        });

        $router->post('api/validation', function (Request $request) {
            $request->validate([
                'name' => 'required',
            ]);

            return 'ok';
        });
    }
}

/**
 * Custom HandleCors subclass for testing the extension pattern.
 */
class CustomHandleCors extends HandleCors
{
    protected function getCorsConfig(): array
    {
        $config = parent::getCorsConfig();

        // Add a custom allowed origin
        $config['allowed_origins'][] = 'http://custom.example.com';

        return $config;
    }
}
