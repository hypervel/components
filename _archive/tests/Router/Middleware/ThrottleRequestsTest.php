<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router\Middleware;

use Hypervel\Router\Middleware\ThrottleRequests;
use Hypervel\Tests\TestCase;

enum ThrottleRequestsTestLimiterEnum: string
{
    case Api = 'api';
    case Web = 'web';
}

enum ThrottleRequestsTestLimiterUnitEnum
{
    case uploads;
    case downloads;
}

enum ThrottleRequestsTestLimiterIntEnum: int
{
    case Default = 1;
}

/**
 * @internal
 * @coversNothing
 */
class ThrottleRequestsTest extends TestCase
{
    public function testUsingWithString(): void
    {
        $result = ThrottleRequests::using('api');

        $this->assertSame(ThrottleRequests::class . ':api', $result);
    }

    public function testUsingWithStringBackedEnum(): void
    {
        $result = ThrottleRequests::using(ThrottleRequestsTestLimiterEnum::Api);

        $this->assertSame(ThrottleRequests::class . ':api', $result);
    }

    public function testUsingWithUnitEnum(): void
    {
        $result = ThrottleRequests::using(ThrottleRequestsTestLimiterUnitEnum::uploads);

        $this->assertSame(ThrottleRequests::class . ':uploads', $result);
    }

    public function testUsingWithIntBackedEnumCoercesToString(): void
    {
        // PHP implicitly converts int to string in concatenation
        $result = ThrottleRequests::using(ThrottleRequestsTestLimiterIntEnum::Default);

        $this->assertSame(ThrottleRequests::class . ':1', $result);
    }
}
