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

    public function testRequestSucceedsWithTrustedHostPattern()
    {
        AlwaysTrustHosts::at(['^example\.com$'], subdomains: false);

        $this->call('GET', 'http://example.com/host')
            ->assertOk()
            ->assertContent('example.com');
    }

    public function testRequestFailsWithUntrustedHost()
    {
        AlwaysTrustHosts::at(['^example\.com$'], subdomains: false);
        $this->withoutExceptionHandling();
        $this->expectException(SuspiciousOperationException::class);

        $this->call('GET', 'http://evil.com/host');
    }

    public function testDynamicTrustHostsClosureRunsPerRequest()
    {
        AlwaysTrustHosts::at(fn () => ['^' . preg_quote(request()->headers->get('HOST'), '/') . '$'], subdomains: false);

        $this->call('GET', 'http://a.example.com/host')
            ->assertOk()
            ->assertContent('a.example.com');

        $this->call('GET', 'http://b.example.com/host')
            ->assertOk()
            ->assertContent('b.example.com');
    }
}

class AlwaysTrustHosts extends TrustHosts
{
    protected function shouldSpecifyTrustedHosts(): bool
    {
        return true;
    }
}
