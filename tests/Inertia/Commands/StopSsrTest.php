<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Commands;

use Hypervel\Http\Client\Request;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Inertia\TestCase;

class StopSsrTest extends TestCase
{
    protected string $healthUrl;

    protected string $shutdownUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = app(HttpGateway::class);
        $this->healthUrl = $gateway->getProductionUrl('/health');
        $this->shutdownUrl = $gateway->getProductionUrl('/shutdown');

        Http::preventStrayRequests();
    }

    public function testFailsWhenTheSsrServerIsUnhealthy(): void
    {
        Http::fake([
            $this->healthUrl => Http::response(status: 500),
        ]);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Unable to connect to Inertia SSR server.')
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function testSucceedsWhenTheSsrServerStops(): void
    {
        Http::fake([
            $this->healthUrl => Http::response(status: 200),
            $this->shutdownUrl => Http::response(status: 200),
        ]);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Inertia SSR server stopped.')
            ->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->url() === $this->shutdownUrl);
    }

    public function testFailsWhenTheSsrServerRefusesToStop(): void
    {
        Http::fake([
            $this->healthUrl => Http::response(status: 200),
            $this->shutdownUrl => Http::response(status: 500),
        ]);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Inertia SSR server refused to stop.')
            ->assertExitCode(1);
    }

    public function testAcceptsAResponseLessCloseAfterTheHealthCheck(): void
    {
        Http::fake([
            $this->healthUrl => Http::response(status: 200),
            $this->shutdownUrl => Http::failedConnection('Connection closed'),
        ]);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Inertia SSR server stopped.')
            ->assertExitCode(0);
    }
}
