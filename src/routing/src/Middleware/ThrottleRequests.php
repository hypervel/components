<?php

declare(strict_types=1);

namespace Hypervel\Routing\Middleware;

use Closure;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\Unlimited;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\Exceptions\ThrottleRequestsException;
use Hypervel\Http\Request;
use Hypervel\Routing\Exceptions\MissingRateLimiterException;
use Hypervel\Support\Collection;
use Hypervel\Support\InteractsWithTime;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

use function Hypervel\Support\enum_value;

class ThrottleRequests
{
    use InteractsWithTime;

    /**
     * The rate limiter instance.
     */
    protected RateLimiter $limiter;

    /**
     * Indicates if the rate limiter keys should be hashed.
     */
    protected static bool $shouldHashKeys = true;

    /**
     * Create a new request throttler.
     */
    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Specify the named rate limiter to use for the middleware.
     */
    public static function using(UnitEnum|string $name): string
    {
        return static::class . ':' . enum_value($name);
    }

    /**
     * Specify the rate limiter configuration for the middleware.
     *
     * @named-arguments-supported
     */
    public static function with(int $maxAttempts = 60, int $decayMinutes = 1, string $prefix = ''): string
    {
        return static::class . ':' . implode(',', func_get_args());
    }

    /**
     * Handle an incoming request.
     *
     * @throws \Hypervel\Http\Exceptions\ThrottleRequestsException
     * @throws \Hypervel\Routing\Exceptions\MissingRateLimiterException
     */
    public function handle(Request $request, Closure $next, int|string $maxAttempts = 60, float|int|string $decayMinutes = 1, string $prefix = ''): Response
    {
        if (is_string($maxAttempts)
            && func_num_args() === 3
            && ! is_null($limiter = $this->limiter->limiter($maxAttempts))) {
            return $this->handleRequestUsingNamedLimiter($request, $next, $maxAttempts, $limiter);
        }

        return $this->handleRequest(
            $request,
            $next,
            [
                (object) [
                    'key' => $prefix . $this->resolveRequestSignature($request),
                    'maxAttempts' => $this->resolveMaxAttempts($request, $maxAttempts),
                    'decaySeconds' => 60 * $decayMinutes,
                    'afterCallback' => null,
                    'responseCallback' => null,
                ],
            ]
        );
    }

    /**
     * Handle an incoming request using a named limiter.
     *
     * @throws \Hypervel\Http\Exceptions\ThrottleRequestsException
     */
    protected function handleRequestUsingNamedLimiter(Request $request, Closure $next, string $limiterName, Closure $limiter): Response
    {
        $limiterResponse = $limiter($request);

        if ($limiterResponse instanceof Response) {
            return $limiterResponse;
        }
        if ($limiterResponse instanceof Unlimited) {
            return $next($request);
        }

        return $this->handleRequest(
            $request,
            $next,
            Collection::wrap($limiterResponse)->map(function ($limit) use ($limiterName) {
                return (object) [
                    'key' => self::$shouldHashKeys ? md5($limiterName . $limit->key) : $limiterName . ':' . $limit->key,
                    'maxAttempts' => $limit->maxAttempts,
                    'decaySeconds' => $limit->decaySeconds,
                    'afterCallback' => $limit->afterCallback,
                    'responseCallback' => $limit->responseCallback,
                ];
            })->all()
        );
    }

    /**
     * Handle an incoming request.
     *
     * @throws \Hypervel\Http\Exceptions\ThrottleRequestsException
     */
    protected function handleRequest(Request $request, Closure $next, array $limits): Response
    {
        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }
        }

        foreach ($limits as $limit) {
            if (! $limit->afterCallback) {
                $this->limiter->hit($limit->key, $limit->decaySeconds);
            }
        }

        $response = $next($request);

        foreach ($limits as $limit) {
            if ($limit->afterCallback && ($limit->afterCallback)($response)) {
                $this->limiter->hit($limit->key, $limit->decaySeconds);
            }

            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
            );
        }

        return $response;
    }

    /**
     * Resolve the number of attempts if the user is authenticated or not.
     *
     * @throws \Hypervel\Routing\Exceptions\MissingRateLimiterException
     */
    protected function resolveMaxAttempts(Request $request, int|string $maxAttempts): int
    {
        if (str_contains((string) $maxAttempts, '|')) {
            $maxAttempts = explode('|', (string) $maxAttempts, 2)[$request->user() ? 1 : 0];
        }

        if (! is_numeric($maxAttempts)
            && $request->user()?->hasAttribute($maxAttempts)
        ) {
            $maxAttempts = $request->user()->{$maxAttempts};
        }

        // If we still don't have a numeric value, there was no matching rate limiter...
        if (! is_numeric($maxAttempts)) {
            is_null($request->user())
                ? throw MissingRateLimiterException::forLimiter($maxAttempts)
                : throw MissingRateLimiterException::forLimiterAndUser($maxAttempts, get_class($request->user()));
        }

        return (int) $maxAttempts;
    }

    /**
     * Resolve request signature.
     *
     * @throws RuntimeException
     */
    protected function resolveRequestSignature(Request $request): string
    {
        if ($user = $request->user()) {
            return $this->formatIdentifier($user->getAuthIdentifier());
        }
        if ($route = $request->route()) {
            return $this->formatIdentifier($route->getDomain() . '|' . $request->ip());
        }

        throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }

    /**
     * Create a 'too many attempts' exception.
     */
    protected function buildException(Request $request, string $key, int $maxAttempts, ?callable $responseCallback = null): ThrottleRequestsException|HttpResponseException
    {
        $retryAfter = $this->getTimeUntilNextRetry($key);

        $headers = $this->getHeaders(
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts, $retryAfter),
            $retryAfter
        );

        return is_callable($responseCallback)
            ? new HttpResponseException($responseCallback($request, $headers))
            : new ThrottleRequestsException('Too Many Attempts.', null, $headers);
    }

    /**
     * Get the number of seconds until the next retry.
     */
    protected function getTimeUntilNextRetry(string $key): int
    {
        return $this->limiter->availableIn($key);
    }

    /**
     * Add the limit header information to the given response.
     */
    protected function addHeaders(Response $response, int $maxAttempts, int $remainingAttempts, ?int $retryAfter = null): Response
    {
        $response->headers->add(
            $this->getHeaders($maxAttempts, $remainingAttempts, $retryAfter, $response)
        );

        return $response;
    }

    /**
     * Get the limit headers information.
     */
    protected function getHeaders(int $maxAttempts, int $remainingAttempts, ?int $retryAfter = null, ?Response $response = null): array
    {
        if ($response
            && ! is_null($response->headers->get('X-RateLimit-Remaining'))
            && (int) $response->headers->get('X-RateLimit-Remaining') <= $remainingAttempts) {
            return [];
        }

        $headers = [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ];

        if (! is_null($retryAfter)) {
            $headers['Retry-After'] = $retryAfter;
            $headers['X-RateLimit-Reset'] = $this->availableAt($retryAfter);
        }

        return $headers;
    }

    /**
     * Calculate the number of remaining attempts.
     */
    protected function calculateRemainingAttempts(string $key, int $maxAttempts, ?int $retryAfter = null): int
    {
        return is_null($retryAfter) ? $this->limiter->retriesLeft($key, $maxAttempts) : 0;
    }

    /**
     * Format the given identifier based on the configured hashing settings.
     */
    private function formatIdentifier(string $value): string
    {
        return self::$shouldHashKeys ? sha1($value) : $value;
    }

    /**
     * Specify whether rate limiter keys should be hashed.
     */
    public static function shouldHashKeys(bool $shouldHashKeys = true): void
    {
        self::$shouldHashKeys = $shouldHashKeys;
    }
}
