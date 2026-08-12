<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use Hypervel\Http\Request;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Support\Str;

class LoginRateLimiter
{
    /**
     * Create a new login rate limiter instance.
     */
    public function __construct(
        protected RateLimiter $limiter,
    ) {
    }

    /**
     * Get the number of attempts for the given key.
     */
    public function attempts(Request $request): int
    {
        $policy = $this->limit($request);
        $result = $this->limiter->inspect($policy);

        return $result->limit() - $result->remaining();
    }

    /**
     * Determine if the user has too many failed login attempts.
     */
    public function tooManyAttempts(Request $request): bool
    {
        return $this->limiter->inspect($this->limit($request))->denied();
    }

    /**
     * Increment the login attempts for the user.
     */
    public function increment(Request $request): void
    {
        $this->limiter->consume($this->limit($request));
    }

    /**
     * Determine the number of seconds until logging in is available again.
     */
    public function availableIn(Request $request): int
    {
        return $this->limiter->inspect($this->limit($request))->resetAfter();
    }

    /**
     * Clear the login locks for the given user credentials.
     */
    public function clear(Request $request): void
    {
        $this->limiter->clear($this->limit($request));
    }

    /**
     * Build the fixed login rate limit.
     */
    private function limit(Request $request): Limit
    {
        return Limit::perMinute(5)->by($this->throttleKey($request));
    }

    /**
     * Get the throttle key for the given request.
     *
     * Scoped to the current guard so lockouts in one actor silo never
     * block logins in another for the same username and IP.
     */
    protected function throttleKey(Request $request): string
    {
        return Str::transliterate(Fortify::guardName() . '|' . Str::lower((string) $request->input(Fortify::username())) . '|' . $request->ip());
    }
}
