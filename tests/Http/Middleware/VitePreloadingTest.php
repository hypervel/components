<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Middleware;

use Hypervel\Container\Container;
use Hypervel\Foundation\Vite;
use Hypervel\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class VitePreloadingTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::setFacadeApplication(null);
        Facade::clearResolvedInstances();

        parent::tearDown();
    }

    public function testItDoesNotSetLinkTagWhenNoTagsHaveBeenPreloaded(): void
    {
        $this->withPreloadedAssets([]);

        $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, function () {
            return new Response('Hello Hypervel');
        });

        $this->assertNull($response->headers->get('Link'));
    }

    public function testItAddsPreloadLinkHeader(): void
    {
        $this->withPreloadedAssets([
            'https://hypervel.org/app.js' => [
                'rel="modulepreload"',
                'foo="bar"',
            ],
        ]);

        $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, function () {
            return new Response('Hello Hypervel');
        });

        $this->assertSame(
            '<https://hypervel.org/app.js>; rel="modulepreload"; foo="bar"',
            $response->headers->get('Link'),
        );
    }

    public function testItDoesNotAttachHeadersToNonIlluminateResponses(): void
    {
        $this->withPreloadedAssets([
            'https://hypervel.org/app.js' => [
                'rel="modulepreload"',
                'foo="bar"',
            ],
        ]);

        $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, function () {
            return new SymfonyResponse('Hello Hypervel');
        });

        $this->assertNull($response->headers->get('Link'));
    }

    public function testItDoesNotOverwriteOtherLinkHeaders(): void
    {
        $this->withPreloadedAssets([
            'https://hypervel.org/app.js' => [
                'rel="modulepreload"',
                'foo="bar"',
            ],
        ]);

        $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, function () {
            return new Response('Hello Hypervel', headers: ['Link' => '<https://hypervel.org/logo.png>; rel="preload"; as="image"']);
        });

        $this->assertSame(
            [
                '<https://hypervel.org/logo.png>; rel="preload"; as="image"',
                '<https://hypervel.org/app.js>; rel="modulepreload"; foo="bar"',
            ],
            $response->headers->all('Link'),
        );
    }

    public function testItCanLimitNumberOfAssetsPreloaded(): void
    {
        $this->withPreloadedAssets([
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
        ]);

        $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, fn () => new Response('ok'), 2);

        $this->assertSame(
            [
                '<https://hypervel.org/first.js>; rel="modulepreload"; foo="bar", <https://hypervel.org/second.js>; rel="modulepreload"; foo="bar"',
            ],
            $response->headers->all('Link'),
        );
    }

    public function testFontPreloadEntriesResultInLinkHeaders(): void
    {
        $this->withPreloadedAssets([
            'https://example.com/build/assets/inter-400.woff2' => [
                'rel="preload"',
                'as="font"',
                'type="font/woff2"',
                'crossorigin="anonymous"',
            ],
        ]);

        $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, function () {
            return new Response('Hello Hypervel');
        });

        $this->assertSame(
            '<https://example.com/build/assets/inter-400.woff2>; rel="preload"; as="font"; type="font/woff2"; crossorigin="anonymous"',
            $response->headers->get('Link'),
        );
    }

    public function testFontPreloadsDoNotOverwriteExistingJsPreloads(): void
    {
        $this->withPreloadedAssets([
            'https://example.com/build/assets/app.js' => [
                'rel="modulepreload"',
            ],
            'https://example.com/build/assets/inter-400.woff2' => [
                'rel="preload"',
                'as="font"',
                'type="font/woff2"',
                'crossorigin="anonymous"',
            ],
        ]);

        $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, function () {
            return new Response('Hello Hypervel');
        });

        $this->assertSame(
            [
                '<https://example.com/build/assets/app.js>; rel="modulepreload", <https://example.com/build/assets/inter-400.woff2>; rel="preload"; as="font"; type="font/woff2"; crossorigin="anonymous"',
            ],
            $response->headers->all('Link'),
        );
    }

    public function testLimitAppliesToCombinedJsAndFontPreloads(): void
    {
        $this->withPreloadedAssets([
            'https://example.com/build/assets/app.js' => [
                'rel="modulepreload"',
            ],
            'https://example.com/build/assets/inter-400.woff2' => [
                'rel="preload"',
                'as="font"',
            ],
            'https://example.com/build/assets/inter-700.woff2' => [
                'rel="preload"',
                'as="font"',
            ],
        ]);

        $response = (new AddLinkHeadersForPreloadedAssets)->handle(new Request, fn () => new Response('ok'), 2);

        $this->assertSame(
            [
                '<https://example.com/build/assets/app.js>; rel="modulepreload", <https://example.com/build/assets/inter-400.woff2>; rel="preload"; as="font"',
            ],
            $response->headers->all('Link'),
        );
    }

    public function testItCanConfigureTheMiddleware(): void
    {
        $definition = AddLinkHeadersForPreloadedAssets::using(limit: 5);

        $this->assertSame('Hypervel\Http\Middleware\AddLinkHeadersForPreloadedAssets:5', $definition);
    }

    /**
     * Seed a Vite facade instance with preloaded assets.
     *
     * @param array<string, list<string>> $preloadedAssets
     */
    protected function withPreloadedAssets(array $preloadedAssets): void
    {
        $app = new Container;
        $app->instance(Vite::class, new class($preloadedAssets) extends Vite {
            public function __construct(protected array $preloadedAssets)
            {
            }

            public function preloadedAssets(): array
            {
                return $this->preloadedAssets;
            }
        });

        Facade::setFacadeApplication($app);
    }
}
