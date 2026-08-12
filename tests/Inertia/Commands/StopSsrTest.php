<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Commands;

use GuzzleHttp\Exception\TransferException;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Tests\Inertia\TestCase;
use Mockery as m;

class StopSsrTest extends TestCase
{
    public function testFailsWhenTheSsrServerIsUnhealthy(): void
    {
        $gateway = m::mock(HttpGateway::class);
        $gateway->shouldReceive('isHealthy')->once()->andReturn(false);
        $gateway->shouldNotReceive('shutdown');
        $this->app->instance(HttpGateway::class, $gateway);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Unable to connect to Inertia SSR server.')
            ->assertExitCode(1);
    }

    public function testSucceedsWhenTheSsrServerStops(): void
    {
        $gateway = m::mock(HttpGateway::class);
        $gateway->shouldReceive('isHealthy')->once()->andReturn(true);
        $gateway->shouldReceive('shutdown')->once()->andReturn(true);
        $this->app->instance(HttpGateway::class, $gateway);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Inertia SSR server stopped.')
            ->assertExitCode(0);
    }

    public function testFailsWhenTheSsrServerRefusesToStop(): void
    {
        $gateway = m::mock(HttpGateway::class);
        $gateway->shouldReceive('isHealthy')->once()->andReturn(true);
        $gateway->shouldReceive('shutdown')->once()->andReturn(false);
        $this->app->instance(HttpGateway::class, $gateway);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Inertia SSR server refused to stop.')
            ->assertExitCode(1);
    }

    public function testAcceptsAResponseLessCloseAfterTheHealthCheck(): void
    {
        $gateway = m::mock(HttpGateway::class);
        $gateway->shouldReceive('isHealthy')->once()->andReturn(true);
        $gateway->shouldReceive('shutdown')->once()->andThrow(new TransferException('Connection closed'));
        $this->app->instance(HttpGateway::class, $gateway);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Inertia SSR server stopped.')
            ->assertExitCode(0);
    }
}
