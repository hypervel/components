<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http\Middleware\Concerns;

use Hypervel\Context\RequestContext;
use Hypervel\Foundation\Http\Middleware\Concerns\ExcludesPaths;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Exception\ConflictingHeadersException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

class ExcludesPathsTest extends TestCase
{
    public function testExcludesPathPatterns(): void
    {
        $excluder = new ExcludesPathsTestExcluder(['up', 'api/*']);

        $this->assertTrue($excluder->check(Request::create('http://example.com/up')));
        $this->assertTrue($excluder->check(Request::create('http://example.com/api/users')));
        $this->assertFalse($excluder->check(Request::create('http://example.com/dashboard')));
    }

    public function testExcludesAbsoluteUrlPatterns(): void
    {
        // The full URL is only rebuilt for patterns that could match one, so a
        // pattern written as an absolute URL still has to work.
        $excluder = new ExcludesPathsTestExcluder(['http://example.com/admin/*']);

        $this->assertTrue($excluder->check(Request::create('http://example.com/admin/users')));
        $this->assertFalse($excluder->check(Request::create('http://other.com/admin/users')));
    }

    public function testEmptyExclusionListMatchesNothing(): void
    {
        $excluder = new ExcludesPathsTestExcluder([]);

        $this->assertFalse($excluder->check(Request::create('http://example.com/up')));
    }

    public function testUntrustedHostIsRejectedEvenWhenThePathIsExcluded(): void
    {
        $request = Request::create('http://evil.com/up');
        RequestContext::set($request);
        Request::setTrustedHosts(['^allowed\.com$']);

        $excluder = new ExcludesPathsTestExcluder(['up']);

        $this->expectException(SuspiciousOperationException::class);

        $excluder->check($request);
    }

    public function testConflictingForwardedHeadersAreRejectedEvenWhenThePathIsExcluded(): void
    {
        foreach (['host', 'proto', 'port'] as $axis) {
            $request = $this->conflictingRequest($axis);
            $excluder = new ExcludesPathsTestExcluder(['up']);

            try {
                $excluder->check($request);

                $this->fail("A conflicting {$axis} header was not rejected.");
            } catch (ConflictingHeadersException $exception) {
                $this->assertInstanceOf(ConflictingHeadersException::class, $exception);
            }
        }
    }

    /**
     * Build a request whose Forwarded and X-Forwarded-* headers disagree on one axis.
     */
    protected function conflictingRequest(string $axis): Request
    {
        $request = Request::create('http://allowed.com/up', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_FORWARDED' => 'for=1.2.3.4;host=allowed.com;proto=https;port=443',
            'HTTP_X_FORWARDED_HOST' => $axis === 'host' ? 'evil.com' : 'allowed.com',
            'HTTP_X_FORWARDED_PROTO' => $axis === 'proto' ? 'http' : 'https',
            'HTTP_X_FORWARDED_PORT' => $axis === 'port' ? '8080' : '443',
        ]);

        RequestContext::set($request);

        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_FORWARDED
            | Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);

        return $request;
    }
}

class ExcludesPathsTestExcluder
{
    use ExcludesPaths;

    public function __construct(protected array $except = [])
    {
    }

    public function check(Request $request): bool
    {
        return $this->inExceptArray($request);
    }
}
