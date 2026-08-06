<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing;

use Hypervel\Routing\Middleware\ThrottleRequestsWithRedis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function Hypervel\Coroutine\parallel;

class RoutingServiceProviderTest extends RoutingTestCase
{
    public function testResolvingPsrRequest(): void
    {
        $psrRequest = $this->app->make(ServerRequestInterface::class);

        $this->assertInstanceOf(ServerRequestInterface::class, $psrRequest);
    }

    public function testResolvingPsrResponse(): void
    {
        $psrResponse = $this->app->make(ResponseInterface::class);

        $this->assertInstanceOf(ResponseInterface::class, $psrResponse);
    }

    public function testRedisThrottleResolvesAsAFreshInstance(): void
    {
        $this->assertNotSame(
            $this->app->make(ThrottleRequestsWithRedis::class),
            $this->app->make(ThrottleRequestsWithRedis::class),
        );
    }

    public function testRedisThrottleStateIsIsolatedBetweenConcurrentResolutions(): void
    {
        $results = parallel([
            function (): int {
                $middleware = $this->app->make(ThrottleRequestsWithRedis::class);
                $middleware->remaining['shared-key'] = 1;
                usleep(5000);

                return $middleware->remaining['shared-key'];
            },
            function (): int {
                $middleware = $this->app->make(ThrottleRequestsWithRedis::class);
                $middleware->remaining['shared-key'] = 2;
                usleep(1000);

                return $middleware->remaining['shared-key'];
            },
        ]);

        $this->assertSame([1, 2], $results);
    }
}
