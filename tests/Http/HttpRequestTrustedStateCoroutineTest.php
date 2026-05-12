<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

use function Hypervel\Coroutine\parallel;

class HttpRequestTrustedStateCoroutineTest extends TestCase
{
    public function testConcurrentRequestsHaveIsolatedTrustedProxies()
    {
        [$resultA, $resultB] = parallel([
            function () {
                $request = Request::create('http://a.example.com/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
                RequestContext::set($request);

                Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
                usleep(5000);

                return [
                    'trusted' => Request::getTrustedProxies(),
                    'isFrom' => $request->isFromTrustedProxy(),
                ];
            },
            function () {
                usleep(2500);

                $request = Request::create('http://b.example.com/', 'GET', [], [], [], ['REMOTE_ADDR' => '20.0.0.1']);
                RequestContext::set($request);

                Request::setTrustedProxies(['20.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
                usleep(5000);

                return [
                    'trusted' => Request::getTrustedProxies(),
                    'isFrom' => $request->isFromTrustedProxy(),
                ];
            },
        ]);

        $this->assertSame(['10.0.0.1'], $resultA['trusted']);
        $this->assertTrue($resultA['isFrom']);
        $this->assertSame(['20.0.0.1'], $resultB['trusted']);
        $this->assertTrue($resultB['isFrom']);
    }

    public function testConcurrentClientIpResolutionIsIsolated()
    {
        [$clientA, $clientB] = parallel([
            function () {
                $request = Request::create('http://a.example.com/', 'GET', [], [], [], [
                    'REMOTE_ADDR' => '10.0.0.1',
                    'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
                ]);
                RequestContext::set($request);
                Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
                usleep(5000);

                return $request->ip();
            },
            function () {
                usleep(2500);

                $request = Request::create('http://b.example.com/', 'GET', [], [], [], [
                    'REMOTE_ADDR' => '20.0.0.1',
                    'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
                ]);
                RequestContext::set($request);
                Request::setTrustedProxies(['20.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
                usleep(5000);

                return $request->ip();
            },
        ]);

        $this->assertSame('9.9.9.9', $clientA);
        $this->assertSame('8.8.8.8', $clientB);
    }

    public function testConcurrentHostResolutionIsIsolated()
    {
        [$hostA, $hostB] = parallel([
            function () {
                $request = Request::create('http://a.com/');
                RequestContext::set($request);
                Request::setTrustedHosts(['^a\.com$']);
                usleep(5000);

                return $request->getHost();
            },
            function () {
                usleep(2500);

                $request = Request::create('http://b.com/');
                RequestContext::set($request);
                Request::setTrustedHosts(['^b\.com$']);
                usleep(5000);

                return $request->getHost();
            },
        ]);

        $this->assertSame('a.com', $hostA);
        $this->assertSame('b.com', $hostB);
    }

    public function testHostValidityFlagsAreIsolated()
    {
        [$resultA, $resultB] = parallel([
            function () {
                $request = Request::create('http://evil.com/');
                RequestContext::set($request);
                Request::setTrustedHosts(['^a\.com$']);

                try {
                    $request->getHost();
                } catch (SuspiciousOperationException) {
                    usleep(5000);

                    return $request->getHost();
                }

                return 'unexpected';
            },
            function () {
                usleep(2500);

                $request = Request::create('http://b.com/');
                RequestContext::set($request);
                Request::setTrustedHosts(['^b\.com$']);
                usleep(5000);

                return $request->getHost();
            },
        ]);

        $this->assertSame('', $resultA);
        $this->assertSame('b.com', $resultB);
    }
}
