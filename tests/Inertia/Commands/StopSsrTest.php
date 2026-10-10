<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Commands;

use Hypervel\Http\Client\Destinations\DestinationPolicyException;
use Hypervel\Http\Client\Destinations\DisallowedDestinationException;
use Hypervel\Http\Client\Request;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Inertia\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

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

    public function testFailureWhenTheSsrServerIsNotRunning(): void
    {
        Http::fake([
            $this->healthUrl => Http::failedConnection('Connection refused'),
        ]);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Unable to connect to Inertia SSR server.')
            ->assertExitCode(1);
    }

    public function testSuccessWhenTheSsrServerIsNotRunningAndTheGracefulOptionIsUsed(): void
    {
        Http::fake([
            $this->healthUrl => Http::failedConnection('Connection refused'),
        ]);

        $this->artisan('inertia:stop-ssr', ['--graceful' => true])
            ->expectsOutput('Inertia SSR server is not running.')
            ->assertExitCode(0);
    }

    public function testFailureWhenAnotherServiceRespondsOnTheSsrUrl(): void
    {
        Http::fake([
            $this->healthUrl => Http::response('Hello from another service', 404),
        ]);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Unable to connect to Inertia SSR server.')
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function testFailureWhenAnotherServiceRespondsOnTheSsrUrlAndTheGracefulOptionIsUsed(): void
    {
        Http::fake([
            $this->healthUrl => Http::response('Hello from another service', 404),
        ]);

        $this->artisan('inertia:stop-ssr', ['--graceful' => true])
            ->expectsOutput('Unable to connect to Inertia SSR server.')
            ->assertExitCode(1);
    }

    public function testTheResponseBodyIsNotPrintedToTheConsole(): void
    {
        Http::fake([
            $this->healthUrl => Http::response('Hello from another service', 404),
        ]);

        $this->expectOutputString('');

        $this->artisan('inertia:stop-ssr')
            ->doesntExpectOutputToContain('Hello from another service')
            ->run();
    }

    #[TestWith([DisallowedDestinationException::class])]
    #[TestWith([DestinationPolicyException::class])]
    public function testHealthChecksFailWhenTheSsrDestinationIsBlocked(string $exceptionClass): void
    {
        Http::fake([
            $this->healthUrl => static fn (): never => throw new $exceptionClass('Blocked SSR destination.'),
        ]);

        $this->artisan('inertia:check-ssr')
            ->expectsOutput('Inertia SSR server is not running.')
            ->assertExitCode(1);

        $this->artisan('inertia:stop-ssr')
            ->expectsOutput('Unable to connect to Inertia SSR server.')
            ->assertExitCode(1);

        $this->artisan('inertia:stop-ssr', ['--graceful' => true])
            ->expectsOutput('Unable to connect to Inertia SSR server.')
            ->assertExitCode(1);
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
