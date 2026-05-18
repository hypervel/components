<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Http;

use Hypervel\Routing\Router;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Hypervel\Tests\Sentry\SentryTestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionProperty;

class HypervelRequestFetcherTest extends SentryTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('/', function () {
            return 'Hello!';
        });
    }

    public function testPsr7InstanceCanBeResolved(): void
    {
        // Make a request so RequestContext is populated
        $this->get('/');

        $fetcher = new HypervelRequestFetcher;
        $request = $fetcher->fetchRequest();

        $this->assertInstanceOf(ServerRequestInterface::class, $request);
    }

    public function testFlushStateClearsPsrHttpFactoryCache()
    {
        $this->get('/');

        $fetcher = new HypervelRequestFetcher;
        $fetcher->fetchRequest();

        $property = new ReflectionProperty(HypervelRequestFetcher::class, 'psrHttpFactory');

        $this->assertNotNull($property->getValue());

        HypervelRequestFetcher::flushState();

        $this->assertNull($property->getValue());
    }
}
