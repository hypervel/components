<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Fortify\LoginRateLimiter;
use Hypervel\Http\Request;
use Hypervel\Support\CarbonImmutable;

class LoginRateLimiterTest extends TestCase
{
    public function testPublicMethodsUseOneFixedLoginPolicy(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');
        $limiter = $this->app->make(LoginRateLimiter::class);
        $request = Request::create('/login', 'POST', [
            'email' => 'taylor@example.com',
        ], server: [
            'REMOTE_ADDR' => '192.0.2.1',
        ]);

        $this->assertSame(0, $limiter->attempts($request));
        $this->assertFalse($limiter->tooManyAttempts($request));
        $this->assertSame(0, $limiter->availableIn($request));

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $limiter->increment($request);

            $this->assertSame($attempt, $limiter->attempts($request));
        }

        $this->assertTrue($limiter->tooManyAttempts($request));
        $this->assertSame(60, $limiter->availableIn($request));

        CarbonImmutable::setTestNow('2000-01-01 00:00:30');

        $this->assertSame(30, $limiter->availableIn($request));

        $limiter->clear($request);

        $this->assertSame(0, $limiter->attempts($request));
        $this->assertFalse($limiter->tooManyAttempts($request));
        $this->assertSame(0, $limiter->availableIn($request));
    }
}
