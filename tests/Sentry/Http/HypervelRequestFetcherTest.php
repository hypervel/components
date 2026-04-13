<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Http;

use Hypervel\Routing\Router;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Hypervel\Tests\Sentry\SentryTestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @coversNothing
 */
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
}
