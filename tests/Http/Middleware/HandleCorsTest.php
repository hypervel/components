<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Http\Middleware\HandleCors;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function Hypervel\Coroutine\parallel;

class HandleCorsTest extends TestCase
{
    protected array $defaultConfig = [
        'paths' => ['api/*'],
        'supports_credentials' => false,
        'allowed_origins' => ['http://localhost'],
        'allowed_origins_patterns' => [],
        'allowed_headers' => ['X-Custom-1', 'X-Custom-2'],
        'allowed_methods' => ['GET', 'POST'],
        'exposed_headers' => [],
        'max_age' => 0,
    ];

    public function testReturnsAllowOriginHeaderForPreflightWithoutOriginHeader()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testOptionsAllowOriginAllowed()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testAllowAllOrigins()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://hypervel.org',
            'Access-Control-Request-Method' => 'POST',
        ], ['allowed_origins' => ['*']]);

        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testAllowAllOriginsWildcardPattern()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://test.hypervel.org',
            'Access-Control-Request-Method' => 'POST',
        ], ['allowed_origins' => ['*.hypervel.org']]);

        $this->assertSame('http://test.hypervel.org', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testOriginsWildcardIncludesNestedSubdomains()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://api.service.test.hypervel.org',
            'Access-Control-Request-Method' => 'POST',
        ], ['allowed_origins' => ['*.hypervel.org']]);

        $this->assertSame('http://api.service.test.hypervel.org', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testWildcardOriginsNoMatch()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://test.symfony.com',
            'Access-Control-Request-Method' => 'POST',
        ], ['allowed_origins' => ['*.hypervel.org']]);

        $this->assertSame('', $response->headers->get('Access-Control-Allow-Origin', ''));
    }

    public function testOptionsAllowOriginNotAllowed()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://otherhost',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function testAllowMethodForNonPreflightHasNoAllowMethodsHeader()
    {
        $response = $this->dispatchRequest('POST', 'api/ping', [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Methods'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllowHeaderAllowedOptions()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-custom-1, x-custom-2',
        ]);

        $this->assertSame('x-custom-1, x-custom-2', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testAllowHeaderAllowedWildcardOptions()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-custom-3',
        ], ['allowed_headers' => ['*']]);

        $this->assertSame('x-custom-3', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testAllowHeaderNotAllowedOptions()
    {
        $response = $this->dispatchPreflight('api/ping', [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-custom-3',
        ]);

        $this->assertSame('x-custom-1, x-custom-2', $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testNonMatchingPathPassesThrough()
    {
        // path doesn't match 'api/*' — middleware should not add CORS headers
        $response = $this->dispatchRequest('POST', 'web/ping', [
            'Origin' => 'http://localhost',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testErrorResponseStillReceivesCorsHeaders()
    {
        $response = $this->dispatchRequest('POST', 'api/error', [
            'Origin' => 'http://localhost',
        ], status: 500);

        $this->assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame(500, $response->getStatusCode());
    }

    public function testSkipWhenCallbackBypassesMiddleware()
    {
        HandleCors::skipWhen(fn () => true);

        try {
            $response = $this->dispatchPreflight('api/ping', [
                'Origin' => 'http://localhost',
                'Access-Control-Request-Method' => 'POST',
            ]);

            $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        } finally {
            HandleCors::flushState();
        }
    }

    public function testResolveConfigUsingOverridesContainerConfig()
    {
        HandleCors::resolveConfigUsing(function (Request $request) {
            return array_merge($this->defaultConfig, [
                'allowed_origins' => ['http://custom.example.com'],
            ]);
        });

        try {
            $response = $this->dispatchPreflight('api/ping', [
                'Origin' => 'http://custom.example.com',
                'Access-Control-Request-Method' => 'POST',
            ]);

            $this->assertSame('http://custom.example.com', $response->headers->get('Access-Control-Allow-Origin'));
            $this->assertSame(204, $response->getStatusCode());
        } finally {
            HandleCors::flushState();
        }
    }

    public function testStockMiddlewareDoesNotLeakBetweenConcurrentCoroutines()
    {
        // Two concurrent requests through the same stock middleware with uniform
        // config — each should resolve independently. Catches any unexpected
        // shared state we may have missed in the fresh-per-request design.
        $middleware = $this->makeMiddleware([
            'paths' => ['api/*'],
            'allowed_origins' => ['http://localhost', 'http://other.test'],
        ]);

        [$resultA, $resultB] = parallel([
            function () use ($middleware) {
                $request = $this->makeRequest('OPTIONS', 'api/ping', [
                    'Origin' => 'http://localhost',
                    'Access-Control-Request-Method' => 'POST',
                ]);

                usleep(5000);
                $response = $middleware->handle($request, fn () => new Response('', 200));
                usleep(5000);

                return $response->headers->get('Access-Control-Allow-Origin');
            },
            function () use ($middleware) {
                usleep(2500);

                $request = $this->makeRequest('OPTIONS', 'api/ping', [
                    'Origin' => 'http://other.test',
                    'Access-Control-Request-Method' => 'GET',
                ]);

                $response = $middleware->handle($request, fn () => new Response('', 200));
                usleep(5000);

                return $response->headers->get('Access-Control-Allow-Origin');
            },
        ]);

        $this->assertSame('http://localhost', $resultA, 'Coroutine A saw the wrong origin in its preflight response.');
        $this->assertSame('http://other.test', $resultB, 'Coroutine B saw the wrong origin in its preflight response.');
    }

    public function testResolveConfigUsingDoesNotLeakBetweenConcurrentCoroutines()
    {
        // The resolver varies allowed origins by the request's host. Two
        // concurrent requests should each see their own host's config —
        // not the other's.
        HandleCors::resolveConfigUsing(function (Request $request) {
            return [
                'paths' => ['*'],
                'allowed_origins' => ['http://' . $request->getHost()],
            ];
        });

        try {
            $middleware = $this->makeMiddleware(['paths' => ['*']]);

            [$resultA, $resultB] = parallel([
                function () use ($middleware) {
                    $request = Request::create('http://a.example.com/api/ping', 'OPTIONS');
                    $request->headers->set('Origin', 'http://a.example.com');
                    $request->headers->set('Access-Control-Request-Method', 'GET');

                    usleep(5000);
                    $response = $middleware->handle($request, fn () => new Response('', 200));
                    usleep(5000);

                    return $response->headers->get('Access-Control-Allow-Origin');
                },
                function () use ($middleware) {
                    usleep(2500);

                    $request = Request::create('http://b.example.com/api/ping', 'OPTIONS');
                    $request->headers->set('Origin', 'http://b.example.com');
                    $request->headers->set('Access-Control-Request-Method', 'GET');

                    $response = $middleware->handle($request, fn () => new Response('', 200));
                    usleep(5000);

                    return $response->headers->get('Access-Control-Allow-Origin');
                },
            ]);

            $this->assertSame('http://a.example.com', $resultA, 'Coroutine A saw the wrong CORS origin — config leaked from coroutine B.');
            $this->assertSame('http://b.example.com', $resultB, 'Coroutine B saw the wrong CORS origin — config leaked from coroutine A.');
        } finally {
            HandleCors::flushState();
        }
    }

    /**
     * Build a HandleCors instance backed by a fresh Container + Config.
     */
    protected function makeMiddleware(array $overrides = []): HandleCors
    {
        $config = array_replace($this->defaultConfig, $overrides);

        return new HandleCors($this->makeContainer($config));
    }

    protected function makeContainer(array $corsConfig): Container
    {
        $container = new Container;
        $container->instance('config', new Repository(['cors' => $corsConfig]));

        return $container;
    }

    protected function makeRequest(string $method, string $path, array $headers = []): Request
    {
        $request = Request::create('http://localhost/' . ltrim($path, '/'), $method);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    protected function dispatchPreflight(string $path, array $headers, array $configOverrides = []): SymfonyResponse
    {
        return $this->makeMiddleware($configOverrides)->handle(
            $this->makeRequest('OPTIONS', $path, $headers),
            fn () => new Response('', 200),
        );
    }

    protected function dispatchRequest(string $method, string $path, array $headers, array $configOverrides = [], int $status = 200): SymfonyResponse
    {
        return $this->makeMiddleware($configOverrides)->handle(
            $this->makeRequest($method, $path, $headers),
            fn () => new Response('', $status),
        );
    }
}
