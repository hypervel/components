<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\ThrottleRequestsTest;

use Hypervel\Auth\GenericUser;
use Hypervel\Http\Request;
use Hypervel\Routing\Middleware\ThrottleRequests;
use Hypervel\Tests\Routing\RoutingTestCase;

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
            sha1('123'),
            (new ExposesThrottleRequestSignature)->resolveRequestSignatureForTest($request)
        );

        ThrottleRequests::shouldHashKeys(false);

        $this->assertSame(
            '123',
            (new ExposesThrottleRequestSignature)->resolveRequestSignatureForTest($request)
        );
    }
}

class ExposesThrottleRequestSignature extends ThrottleRequests
{
    public function __construct()
    {
    }

    public function resolveRequestSignatureForTest(Request $request): string
    {
        return $this->resolveRequestSignature($request);
    }
}
