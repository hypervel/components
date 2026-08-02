<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Http\Middleware\HandleCors;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testResolvedCorsConfigurationRemainsLocalToEachRequest(): void
    {
        HandleCors::resolveConfigUsing(fn (Request $request) => [
            'paths' => ['*'],
            'supports_credentials' => false,
            'allowed_origins' => ['http://' . $request->getHost()],
            'allowed_origins_patterns' => [],
            'allowed_headers' => [],
            'allowed_methods' => ['GET'],
            'exposed_headers' => [],
            'max_age' => 0,
        ]);

        $container = new Container;
        $container->instance('config', new Repository(['cors' => ['paths' => ['*']]]));
        $middleware = new HandleCors($container);

        [$resultA, $resultB] = parallel([
            function () use ($middleware) {
                $request = $this->makeCorsRequest('a.example.com');

                usleep(5000);
                $response = $middleware->handle($request, function () {
                    usleep(5000);

                    return new Response('', 200);
                });

                return $response->headers->get('Access-Control-Allow-Origin');
            },
            function () use ($middleware) {
                usleep(2500);

                $request = $this->makeCorsRequest('b.example.com');
                $response = $middleware->handle($request, function () {
                    usleep(5000);

                    return new Response('', 200);
                });

                return $response->headers->get('Access-Control-Allow-Origin');
            },
        ]);

        $this->assertSame('http://a.example.com', $resultA);
        $this->assertSame('http://b.example.com', $resultB);
    }

    /**
     * Create a CORS request for the given host.
     */
    protected function makeCorsRequest(string $host): Request
    {
        $request = Request::create("http://{$host}/api/ping");
        $request->headers->set('Origin', "http://{$host}");

        return $request;
    }
}
