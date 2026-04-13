<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @coversNothing
 */
class RoutingServiceProviderTest extends RoutingTestCase
{
    public function testResolvingPsrRequest()
    {
        $psrRequest = $this->app->make(ServerRequestInterface::class);

        $this->assertInstanceOf(ServerRequestInterface::class, $psrRequest);
    }

    public function testResolvingPsrResponse()
    {
        $psrResponse = $this->app->make(ResponseInterface::class);

        $this->assertInstanceOf(ResponseInterface::class, $psrResponse);
    }
}
