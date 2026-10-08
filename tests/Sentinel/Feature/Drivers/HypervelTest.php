<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentinel\Feature\Drivers;

use Hypervel\Context\RequestContext;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Request;
use Hypervel\Sentinel\Sentinel;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class HypervelTest extends TestCase
{
    use InteractsWithPublishedFiles;

    /**
     * List of published files to be deleted after the test.
     */
    protected array $files = [
        '.dockerenv',
    ];

    public function testItAllowsRequestsOutsideTheLocalEnvironment(): void
    {
        $this->app->instance('env', 'production');

        $this->assertTrue(Sentinel::driver()->authorize($this->createForwardedRequest('202.168.65.217')));
    }

    public function testItAllowsDirectLocalRequests(): void
    {
        $this->app->instance('env', 'local');

        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->assertTrue(Sentinel::driver()->authorize($request));
    }

    public function testItRejectsLocalRequestsForwardedForPublicIps(): void
    {
        $this->app->instance('env', 'local');

        $this->assertFalse(Sentinel::driver()->authorize($this->createForwardedRequest('202.168.65.217')));

        // Upstream's Docker marker must not let public clients through a loopback proxy.
        (new Filesystem)->put(base_path('.dockerenv'), '');

        $this->assertFalse(Sentinel::driver()->authorize($this->createForwardedRequest('202.168.65.217')));
    }

    public function testItRequiresTrustedProxiesForLocalTunnelRequests(): void
    {
        $this->app->instance('env', 'local');

        $request = Request::create('https://hypervel.ngrok-free.app/horizon', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Unable to access "GET /horizon" using "local" environment');

        Sentinel::driver()->authorize($request);
    }

    /**
     * Create a request forwarded by a trusted local proxy for the given client IP.
     */
    private function createForwardedRequest(string $clientIp): Request
    {
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => $clientIp,
        ]);

        // Hypervel keeps trusted proxies on the current coroutine's request.
        RequestContext::set($request);
        Request::setTrustedProxies(['127.0.0.1'], SymfonyRequest::HEADER_X_FORWARDED_FOR);

        return $request;
    }
}
