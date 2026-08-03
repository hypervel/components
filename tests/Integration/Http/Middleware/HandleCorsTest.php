<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Middleware\HandleCorsTest;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Http\Kernel;
use Hypervel\Foundation\Validation\ValidatesRequests;
use Hypervel\Http\Middleware\HandleCors;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Testbench\TestCase;

class HandleCorsTest extends TestCase
{
    use ValidatesRequests;

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

        $app->make(Kernel::class)->prependMiddleware(HandleCors::class);
    }

    protected function defineRoutes(Router $router): void
    {
        $router->post('web/ping', fn () => 'PONG');
        $router->post('api/ping', fn () => 'PONG');
        $router->put('api/ping', fn () => 'PONG');
        $router->post('api/error', fn () => abort(500));

        $router->post('api/validation', function (Request $request) {
            $this->validate($request, [
                'name' => 'required',
            ]);

            return 'ok';
        });
    }

    public function testPreflightForMatchedPathReturnsCorsHeaders(): void
    {
        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertStatus(204);
        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testActualRequestForMatchedPathReceivesCorsHeaders(): void
    {
        $response = $this->call('POST', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        $response->assertOk();
        $response->assertSeeText('PONG');
        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testRequestForUnmatchedPathReceivesNoCorsHeaders(): void
    {
        $response = $this->call('POST', 'web/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        $response->assertOk();
        $response->assertSeeText('PONG');
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testPreflightWithoutOriginHeaderReturnsConfiguredOrigin(): void
    {
        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertNoContent();
    }

    public function testAllowAllOrigins(): void
    {
        config(['cors.allowed_origins' => ['*']]);

        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://hypervel.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertNoContent();
    }

    public function testAllowAllOriginsWildcard(): void
    {
        config(['cors.allowed_origins' => ['*.hypervel.org']]);

        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://test.hypervel.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('http://test.hypervel.org', $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertNoContent();
    }

    public function testOriginsWildcardIncludesNestedSubdomains(): void
    {
        config(['cors.allowed_origins' => ['*.hypervel.org']]);

        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://api.service.test.hypervel.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('http://api.service.test.hypervel.org', $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertNoContent();
    }

    public function testOriginsWildcardDoesNotMatchAnotherDomain(): void
    {
        config(['cors.allowed_origins' => ['*.hypervel.org']]);

        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://test.symfony.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testPreflightForNonExistingMatchedRouteReturnsCorsHeaders(): void
    {
        $response = $this->call('OPTIONS', 'api/pang', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertNoContent();
    }

    public function testDisallowedOriginUsesConfiguredOrigin(): void
    {
        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://otherhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testNonPreflightRequestHasNoAllowMethodsHeader(): void
    {
        $response = $this->call('POST', 'web/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Methods'));
        $response->assertOk()->assertContent('PONG');
    }

    public function testNonPreflightRequestWithDisallowedMethodHasNoAllowMethodsHeader(): void
    {
        $response = $this->call('POST', 'web/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PUT',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Methods'));
        $response->assertOk();
    }

    public function testPreflightAllowsConfiguredHeaders(): void
    {
        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-custom-1, x-custom-2',
        ]);

        $this->assertSame('x-custom-1, x-custom-2', $response->headers->get('Access-Control-Allow-Headers'));
        $response->assertNoContent()->assertContent('');
    }

    public function testPreflightAllowsWildcardHeaders(): void
    {
        config(['cors.allowed_headers' => ['*']]);

        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-custom-3',
        ]);

        $this->assertSame('x-custom-3', $response->headers->get('Access-Control-Allow-Headers'));
        $response->assertNoContent()->assertContent('');
    }

    public function testPreflightFallsBackToConfiguredHeaders(): void
    {
        $response = $this->call('OPTIONS', 'api/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-custom-3',
        ]);

        $this->assertSame('x-custom-1, x-custom-2', $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testActualUnmatchedRequestHasNoAllowHeadersHeader(): void
    {
        $response = $this->call('POST', 'web/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-custom-1, x-custom-2',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Headers'));
        $response->assertOk()->assertContent('PONG');
    }

    public function testActualUnmatchedRequestWithWildcardHeadersHasNoAllowHeadersHeader(): void
    {
        config(['cors.allowed_headers' => ['*']]);

        $response = $this->call('POST', 'web/ping', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-custom-3',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Headers'));
        $response->assertOk()->assertContent('PONG');
    }

    public function testErrorResponseReceivesCorsHeaders(): void
    {
        $response = $this->call('POST', 'api/error', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertInternalServerError();
    }

    public function testValidationRedirectReceivesCorsHeaders(): void
    {
        $response = $this->call('POST', 'api/validation', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertRedirect();
    }
}
