<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Middleware\TrustHostsTest;

use Hypervel\Http\Middleware\TrustHosts;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

class TrustHostsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        AlwaysTrustHosts::flushState();

        Route::get('/host', fn (Request $request) => $request->getHost())
            ->middleware(AlwaysTrustHosts::class);
    }

    protected function tearDown(): void
    {
        AlwaysTrustHosts::flushState();

        parent::tearDown();
    }

    public function testRequestSucceedsWithTrustedHostPattern(): void
    {
        AlwaysTrustHosts::at(['^example\.com$'], subdomains: false);

        $this->call('GET', 'http://example.com/host')
            ->assertOk()
            ->assertContent('example.com');
    }

    public function testRequestFailsWithUntrustedHost(): void
    {
        AlwaysTrustHosts::at(['^example\.com$'], subdomains: false);
        $this->withoutExceptionHandling();
        $this->expectException(SuspiciousOperationException::class);

        $this->call('GET', 'http://evil.com/host');
    }

    public function testDynamicTrustHostsClosureRunsPerRequest(): void
    {
        // This verifies the legacy callback is re-run for each request. Real
        // applications should not trust the raw Host header without first
        // checking it against trusted application data.
        AlwaysTrustHosts::at(fn () => ['^' . preg_quote(request()->headers->get('HOST'), '/') . '$'], subdomains: false);

        $this->call('GET', 'http://a.example.com/host')
            ->assertOk()
            ->assertContent('a.example.com');

        $this->call('GET', 'http://b.example.com/host')
            ->assertOk()
            ->assertContent('b.example.com');
    }

    public function testRequestAwareResolverUsesVerifiedHostPatterns(): void
    {
        $verifiedHosts = [
            'tenant.example.com' => ['^tenant\.example\.com$', '^admin\.tenant\.example\.com$'],
            'other.example.com' => ['^other\.example\.com$'],
        ];

        AlwaysTrustHosts::resolveHostsUsing(
            static fn (Request $request): array => $verifiedHosts[$request->headers->get('HOST')] ?? [],
        );

        $this->call('GET', 'http://tenant.example.com/host')
            ->assertOk()
            ->assertContent('tenant.example.com');

        $this->call('GET', 'http://other.example.com/host')
            ->assertOk()
            ->assertContent('other.example.com');
    }

    public function testRequestAwareResolverRejectsUnrecognizedHosts(): void
    {
        AlwaysTrustHosts::resolveHostsUsing(
            static fn (Request $request): array => $request->headers->get('HOST') === 'tenant.example.com'
                ? ['^tenant\.example\.com$']
                : [],
        );

        $this->withoutExceptionHandling();
        $this->expectException(SuspiciousOperationException::class);

        $this->call('GET', 'http://evil.com/host');
    }

    public function testEmptyLegacyHostClosureFailsClosed(): void
    {
        AlwaysTrustHosts::at(fn () => [], subdomains: false);

        $this->withoutExceptionHandling();
        $this->expectException(SuspiciousOperationException::class);

        $this->call('GET', 'http://evil.com/host');
    }

    public function testMissingTrustedHostConfigurationFailsClosed(): void
    {
        config(['app.url' => '']);

        $this->withoutExceptionHandling();
        $this->expectException(SuspiciousOperationException::class);

        $this->call('GET', 'http://evil.com/host');
    }

    public function testFlushStateClearsRequestAwareResolver(): void
    {
        AlwaysTrustHosts::resolveHostsUsing(static fn (): array => ['^tenant\.example\.com$']);

        $this->call('GET', 'http://tenant.example.com/host')
            ->assertOk()
            ->assertContent('tenant.example.com');

        AlwaysTrustHosts::flushState();

        $this->withoutExceptionHandling();
        $this->expectException(SuspiciousOperationException::class);

        $this->call('GET', 'http://tenant.example.com/host');
    }

    public function testNonArrayRequestAwareResolverResultFailsClosed(): void
    {
        AlwaysTrustHosts::resolveHostsUsing(static fn (): mixed => null);

        $this->withoutExceptionHandling();
        $this->expectException(SuspiciousOperationException::class);

        $this->call('GET', 'http://tenant.example.com/host');
    }
}

class AlwaysTrustHosts extends TrustHosts
{
    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }
}
