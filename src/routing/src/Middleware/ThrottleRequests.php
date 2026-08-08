<?php

declare(strict_types=1);

namespace Hypervel\Routing\Middleware;

use Closure;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\Exceptions\ThrottleRequestsException;
use Hypervel\Http\Request;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\Unlimited;
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
                new Limit(
                    maxAttempts: $this->resolveMaxAttempts($request, $maxAttempts),
                    decaySeconds: $this->resolveDecaySeconds($decayMinutes),
                    key: $prefix . $this->resolveRequestSignature($request),
                ),
            ],
            $this->limiter->store(),
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
            Collection::wrap($limiterResponse)->all(),
            $this->limiter->store($this->limiter->limiterStore($limiterName)),
            $limiterName,
        );
    }

    /**
     * Handle an incoming request.
     *
     * @param array<AdmissionPolicy> $limits
     *
     * @throws \Hypervel\Http\Exceptions\ThrottleRequestsException
     */
    protected function handleRequest(
        Request $request,
        Closure $next,
        array $limits,
        Limiter $limiter,
        ?string $limiterName = null,
    ): Response {
        /** @var list<array{AdmissionPolicy, LimitResult}> $decisions */
        $decisions = [];

        // Laravel preflights every policy before recording hits. Atomic stores
        // consume in order, so an earlier accepted decision is never rolled back.
        foreach ($limits as $limit) {
            $result = $limit->afterCallback === null
                ? $limiter->consume($limit, $limiterName)
                : $limiter->inspect($limit, $limiterName);

            if ($result->denied()) {
                throw $this->buildException($request, $result, $limit->responseCallback);
            }

            $decisions[] = [$limit, $result];
        }

        $response = $next($request);

        foreach ($decisions as [$limit, $result]) {
            if ($limit->afterCallback !== null && ($limit->afterCallback)($response)) {
                $result = $limiter->consume($limit, $limiterName);
            }

            $response = $this->addHeaders(
                $response,
                $result->limit(),
                $result->remaining(),
            );
        }

        return $response;
    }

    /**
     * Resolve the fixed-window duration from the middleware argument.
     */
    protected function resolveDecaySeconds(float|int|string $decayMinutes): int
    {
        if (! is_numeric($decayMinutes)) {
            throw new InvalidRateLimitException('The rate limit decay minutes must be numeric.');
        }

        return (int) ceil((float) $decayMinutes * 60);
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
            return (string) $user->getAuthIdentifier();
        }
        if ($route = $request->route()) {
            return $route->getDomain() . '|' . $request->ip();
        }

        throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
    }

    /**
     * Create a 'too many attempts' exception.
     */
    protected function buildException(Request $request, LimitResult $result, ?callable $responseCallback = null): ThrottleRequestsException|HttpResponseException
    {
        // The atomic decision retains real unused capacity on weighted denials;
        // Laravel's split retry path reports zero remaining instead.
        $headers = $this->getHeaders(
            $result->limit(),
            $result->remaining(),
            $result->retryAfter(),
        );

        return is_callable($responseCallback)
            ? new HttpResponseException($responseCallback($request, $headers))
            : new ThrottleRequestsException('Too Many Attempts.', null, $headers);
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

    // Laravel's formatIdentifier() and shouldHashKeys() opt-out are omitted;
    // the rate-limiter package always hashes the complete policy identity.
}
