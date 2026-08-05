<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\ThrottleRequestsTest;

use Hypervel\Auth\GenericUser;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\Routing\Middleware\ThrottleRequests;
use Hypervel\Routing\Route;
use Hypervel\Tests\Routing\RoutingTestCase;
use RuntimeException;

enum NamedLimiter: string
{
    case Uploads = 'uploads';
}

class ThrottleRequestsTest extends RoutingTestCase
{
    public function testAuthenticatedIntegerIdentifierIsNormalizedForRequestSignature(): void
    {
        $request = Request::create('/ping');
        $request->setUserResolver(fn (): GenericUser => new GenericUser([
            'id' => 123,
            'password' => 'secret',
            'remember_token' => null,
        ]));

        $this->assertSame(
            '123',
            (new ExposesThrottleRequests)->resolveRequestSignatureForTest($request)
        );
    }

    public function testRouteSignatureIsReturnedWithoutPreHashing(): void
    {
        $request = Request::create('/ping', server: ['REMOTE_ADDR' => '192.0.2.1']);
        $request->setRouteResolver(fn (): Route => (new Route('GET', '/ping', fn () => null))->domain('api.example.com'));

        $this->assertSame(
            'api.example.com|192.0.2.1',
            (new ExposesThrottleRequests)->resolveRequestSignatureForTest($request)
        );
    }

    public function testMissingRouteCannotProduceARequestSignature(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to generate the request signature. Route unavailable.');

        (new ExposesThrottleRequests)->resolveRequestSignatureForTest(Request::create('/ping'));
    }

    public function testItCanGenerateMiddlewareDefinitions(): void
    {
        $this->assertSame(ThrottleRequests::class . ':uploads', ThrottleRequests::using(NamedLimiter::Uploads));
        $this->assertSame(ThrottleRequests::class . ':25', ThrottleRequests::with(25));
        $this->assertSame(ThrottleRequests::class . ':25,2', ThrottleRequests::with(25, 2));
        $this->assertSame(ThrottleRequests::class . ':25,2,foo', ThrottleRequests::with(25, 2, 'foo'));
        $this->assertSame(
            ThrottleRequests::class . ':25,2,foo',
            ThrottleRequests::with(maxAttempts: 25, decayMinutes: 2, prefix: 'foo')
        );
        $this->assertSame(ThrottleRequests::class . ':60,1,foo', ThrottleRequests::with(prefix: 'foo'));
    }

    public function testFractionalMiddlewareDurationsRoundUpToAWholeSecond(): void
    {
        $middleware = new ExposesThrottleRequests;

        $this->assertSame(60, $middleware->resolveDecaySecondsForTest(1));
        $this->assertSame(30, $middleware->resolveDecaySecondsForTest('0.5'));
        $this->assertSame(1, $middleware->resolveDecaySecondsForTest(0.001));
    }

    public function testNonNumericMiddlewareDurationIsRejected(): void
    {
        $this->expectException(InvalidRateLimitException::class);
        $this->expectExceptionMessage('The rate limit decay minutes must be numeric.');

        (new ExposesThrottleRequests)->resolveDecaySecondsForTest('invalid');
    }
}

class ExposesThrottleRequests extends ThrottleRequests
{
    public function __construct()
    {
    }

    public function resolveRequestSignatureForTest(Request $request): string
    {
        return $this->resolveRequestSignature($request);
    }

    public function resolveDecaySecondsForTest(float|int|string $decayMinutes): int
    {
        return $this->resolveDecaySeconds($decayMinutes);
    }
}
