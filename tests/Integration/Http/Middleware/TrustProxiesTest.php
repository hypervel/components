<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Middleware\TrustProxiesTest;

use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;

use function Hypervel\Coroutine\parallel;

class TrustProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TrustProxies::flushState();
        TrustProxiesFormRequest::$ip = null;

        Route::get('/whoami', fn (Request $request) => $request->ip())
            ->middleware(TrustProxies::class);

        Route::get('/slow-whoami', function (Request $request) {
            usleep((int) $request->server->get('HTTP_X_SLEEP_US', 0));

            return $request->ip();
        })->middleware(TrustProxies::class);

        Route::get('/form-request-ip', fn (TrustProxiesFormRequest $request) => [
            'ip' => TrustProxiesFormRequest::$ip,
            'routeIp' => $request->ip(),
        ])->middleware(TrustProxies::class);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        TrustProxiesFormRequest::$ip = null;

        parent::tearDown();
    }

    public function testIpReflectsXForwardedForFromTrustedProxy()
    {
        TrustProxies::at(['10.0.0.1']);

        $this->call('GET', '/whoami', server: [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ])->assertOk()->assertContent('9.9.9.9');
    }

    public function testIpIgnoresXForwardedForWhenProxyUntrusted()
    {
        TrustProxies::at(['10.0.0.1']);

        $this->call('GET', '/whoami', server: [
            'REMOTE_ADDR' => '30.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ])->assertOk()->assertContent('30.0.0.1');
    }

    public function testWildcardTrustsCallingProxy()
    {
        TrustProxies::at('*');

        $this->call('GET', '/whoami', server: [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ])->assertOk()->assertContent('9.9.9.9');
    }

    public function testFormRequestSeesForwardedIpAfterTrustProxiesMiddleware()
    {
        TrustProxies::at(['10.0.0.1']);

        $response = $this->call('GET', '/form-request-ip', server: [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
        ]);

        $response->assertOk();
        $this->assertSame('9.9.9.9', $response->json('ip'));
        $this->assertSame('9.9.9.9', $response->json('routeIp'));
    }

    public function testConcurrentRequestsThroughMiddlewareKeepTrustedProxyStateIsolated()
    {
        TrustProxies::at('*');

        [$responseA, $responseB] = parallel([
            fn () => $this->call('GET', '/slow-whoami', server: [
                'REMOTE_ADDR' => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '9.9.9.9',
                'HTTP_X_SLEEP_US' => '10000',
            ]),
            function () {
                usleep(2500);

                return $this->call('GET', '/slow-whoami', server: [
                    'REMOTE_ADDR' => '20.0.0.1',
                    'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
                ]);
            },
        ]);

        $responseA->assertOk()->assertContent('9.9.9.9');
        $responseB->assertOk()->assertContent('8.8.8.8');
    }
}

class TrustProxiesFormRequest extends FormRequest
{
    public static ?string $ip = null;

    public function authorize(): bool
    {
        static::$ip = $this->ip();

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
