<?php

declare(strict_types=1);

namespace Hypervel\Router\Middleware;

use Closure;
use Hyperf\Support\Traits\InteractsWithTime;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\Unlimited;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Cache\InvalidArgumentException;
use Hypervel\HttpMessage\Exceptions\HttpResponseException;
use Hypervel\HttpMessage\Exceptions\ThrottleRequestsException;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use UnitEnum;

use function Hypervel\Support\enum_value;

class ThrottleRequests implements MiddlewareInterface
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
     */
    public static function with(int $maxAttempts = 60, int $decayMinutes = 1, string $prefix = ''): string
    {
        return static::class . ':' . implode(',', func_get_args());
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        int|string $maxAttempts = 60,
        float|int|string $decayMinutes = 1,
        string $prefix = ''
    ): ResponseInterface {
        if (! is_numeric($decayMinutes)) {
            throw new InvalidArgumentException('decayMinutes must be numeric.');
        }

        if (is_string($maxAttempts)
            && func_num_args() === 3
            && ! is_null($limiter = $this->limiter->limiter($maxAttempts))) {
            return $this->handleRequestUsingNamedLimiter($request, $handler, $maxAttempts, $limiter);
        }

        return $this->handleRequest(
            $request,
            $handler,
            [
                (object) [
                    'key' => $prefix . $this->resolveRequestSignature(),
                    'maxAttempts' => $this->resolveMaxAttempts($maxAttempts),
                    'decayMinutes' => floatval($decayMinutes),
                    'responseCallback' => null,
                ],
            ]
        );
    }

    /**
     * Handle an incoming request.
     *
     * @throws ThrottleRequestsException
     */
    protected function handleRequestUsingNamedLimiter(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        string $limiterName,
        Closure $limiter
    ): ResponseInterface {
        $limiterResponse = $limiter($request);

        if ($limiterResponse instanceof ResponseInterface) {
            return $limiterResponse;
        }

        if ($limiterResponse instanceof Unlimited) {
            return $handler->handle($request);
        }

        return $this->handleRequest(
            $request,
            $handler,
            array_map(function ($limit) use ($limiterName) {
                return (object) [
                    'key' => self::$shouldHashKeys ? md5($limiterName . $limit->key) : $limiterName . ':' . $limit->key,
                    'maxAttempts' => $limit->maxAttempts,
                    'decayMinutes' => $limit->decayMinutes,
                    'responseCallback' => $limit->responseCallback,
                ];
            }, Arr::wrap($limiterResponse))
        );
    }

    /**
     * Handle an incoming request.
     *
     * @throws HttpResponseException|ThrottleRequestsException
     */
    protected function handleRequest(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
        array $limits
    ): ResponseInterface {
        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }

            $this->limiter->hit($limit->key, (int) round($limit->decayMinutes * 60));
        }

        $response = $handler->handle($request);

        foreach ($limits as $limit) {
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
     */
    protected function resolveMaxAttempts(int|string $maxAttempts): int
    {
        if (str_contains($maxAttempts, '|')) {
            $maxAttempts = explode('|', $maxAttempts, 2)[$this->user() ? 1 : 0];
        }

        if (! is_numeric($maxAttempts) && $this->user()) {
            $maxAttempts = $this->user()->{$maxAttempts};
        }

        return (int) $maxAttempts;
    }

    /**
     * Resolve request signature.
     *
     * @throws RuntimeException
     */
    protected function resolveRequestSignature(): string
    {
        if ($user = $this->user()) {
            return $this->formatIdentifier((string) $user->getAuthIdentifier());
        }

        $domain = $this->domain();
        $ip = $this->ip();

        if ($domain && $ip) {
            return $this->formatIdentifier("{$domain}|{$ip}");
        }

        throw new RuntimeException('Unable to generate the request signature.');
    }

    /**
     * Throw a 'too many attempts' exception.
     */
    protected function buildException(
        ServerRequestInterface $request,
        string $key,
        int $maxAttempts,
        ?callable $responseCallback = null
    ): HttpResponseException|ThrottleRequestsException {
        $retryAfter = $this->getTimeUntilNextRetry($key);

        $headers = $this->getHeaders(
            $maxAttempts,
            $this->calculateRemainingAttempts($key, $maxAttempts, $retryAfter),
            $retryAfter
        );

        return is_callable($responseCallback)
            ? new HttpResponseException($responseCallback($request, $headers))
            : new ThrottleRequestsException('Too Many Attempts.', headers: $headers);
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
    protected function addHeaders(
        ResponseInterface $response,
        int $maxAttempts,
        int $remainingAttempts,
        ?int $retryAfter = null
    ): ResponseInterface {
        $headers = $this->getHeaders($maxAttempts, $remainingAttempts, $retryAfter, $response);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Get the limit headers information.
     */
    protected function getHeaders(
        int $maxAttempts,
        int $remainingAttempts,
        ?int $retryAfter = null,
        ?ResponseInterface $response = null
    ): array {
        if ($response
            && ! empty($response->getHeader('X-RateLimit-Remaining'))
            && (int) $response->getHeader('X-RateLimit-Remaining')[0] <= (int) $remainingAttempts) {
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

    /**
     * Get the currently authenticated user.
     */
    protected function user(): ?Authenticatable
    {
        return Auth::user();
    }

    /**
     * Get the currently request domain.
     */
    protected function domain(): string
    {
        return preg_replace(';https?://;', '', url(''));
    }

    /**
     * Get the currently request ip.
     */
    protected function ip(): string
    {
        return request()->ip();
    }
}
