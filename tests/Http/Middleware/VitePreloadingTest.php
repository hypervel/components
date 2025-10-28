<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hyperf\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Vite;
use Hypervel\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Testbench\TestCase;
use Mockery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * @internal
 * @coversNothing
 */
class VitePreloadingTest extends TestCase
{
    use RunTestsInCoroutine;

    public function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Context::destroy(ResponseInterface::class);
        Context::destroy(Response::RANGE_HEADERS_CONTEXT);
        Context::destroy(ServerRequestInterface::class);
    }

    public function testItDoesNotSetLinkTagWhenNoTagsHaveBeenPreloaded()
    {
        $this->app->instance(Vite::class, new class extends Vite {
            protected $preloadedAssets = [];
        });
        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = (new AddLinkHeadersForPreloadedAssets())->handle(new Request(), function () {
            return (new Response())->make('Hello Hypervel', 200);
        });

        $this->assertNull($response->getHeader('Link')[0] ?? null);
    }

    public function testItAddsPreloadLinkHeader()
    {
        $this->app->instance(Vite::class, new class extends Vite {
            protected $preloadedAssets = [
                'https://hypervel.org/app.js' => [
                    'rel="modulepreload"',
                    'foo="bar"',
                ],
            ];
        });
        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = (new AddLinkHeadersForPreloadedAssets())->handle(new Request(), function () {
            return (new Response())->make('Hello Hypervel', 200);
        });

        $this->assertSame(
            '<https://hypervel.org/app.js>; rel="modulepreload"; foo="bar"',
            $response->getHeaderLine('Link'),
        );
    }

    // public function testItDoesNotAttachHeadersToNonIlluminateResponses()
    // {
    //     $this->app->instance(Vite::class, new class extends Vite {
    //         protected $preloadedAssets = [
    //             'https://hypervel.org/app.js' => [
    //                 'rel="modulepreload"',
    //                 'foo="bar"',
    //             ],
    //         ];
    //     });
    //     $psrResponse = new \Hyperf\HttpMessage\Base\Response();
    //     Context::set(ResponseInterface::class, $psrResponse);

    //     $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, function () {
    //         return new SymfonyResponse('Hello Hypervel');
    //     });

    //     $this->assertNull($response->headers->get('Link'));
    // }

    public function testItDoesNotOverwriteOtherLinkHeaders()
    {
        $this->app->instance(Vite::class, new class extends Vite {
            protected $preloadedAssets = [
                'https://hypervel.org/app.js' => [
                    'rel="modulepreload"',
                    'foo="bar"',
                ],
            ];
        });
        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = (new AddLinkHeadersForPreloadedAssets())->handle(new Request(), function () {
            return (new Response())->make('Hello Hypervel', 200, ['Link' => '<https://hypervel.org/logo.png>; rel="preload"; as="image"']);
        });

        $this->assertSame(
            [
                '<https://hypervel.org/logo.png>; rel="preload"; as="image"',
                '<https://hypervel.org/app.js>; rel="modulepreload"; foo="bar"',
            ],
            $response->getHeader('Link'),
        );
    }

    public function testItCanLimitNumberOfAssetsPreloaded()
    {
        $this->app->instance(Vite::class, new class extends Vite {
            protected $preloadedAssets = [
                'https://hypervel.org/first.js' => [
                    'rel="modulepreload"',
                    'foo="bar"',
                ],
                'https://hypervel.org/second.js' => [
                    'rel="modulepreload"',
                    'foo="bar"',
                ],
                'https://hypervel.org/third.js' => [
                    'rel="modulepreload"',
                    'foo="bar"',
                ],
                'https://hypervel.org/fourth.js' => [
                    'rel="modulepreload"',
                    'foo="bar"',
                ],
            ];
        });
        $psrResponse = new \Hyperf\HttpMessage\Base\Response();
        Context::set(ResponseInterface::class, $psrResponse);

        $response = (new AddLinkHeadersForPreloadedAssets())->handle(new Request(), fn () => (new Response())->make('ok'), 2);

        $this->assertSame(
            [
                '<https://hypervel.org/first.js>; rel="modulepreload"; foo="bar", <https://hypervel.org/second.js>; rel="modulepreload"; foo="bar"',
            ],
            $response->getHeaders()['Link'],
        );
    }

    public function testItCanConfigureTheMiddleware()
    {
        $definition = AddLinkHeadersForPreloadedAssets::using(limit: 5);

        $this->assertSame('Hypervel\Http\Middleware\AddLinkHeadersForPreloadedAssets:5', $definition);
    }
}
