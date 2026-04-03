<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Closure;
use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Cookie\CookieValuePrefix;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Foundation\Http\Middleware\Concerns\ExcludesPaths;
use Hypervel\Http\Exceptions\OriginMismatchException;
use Hypervel\Http\Request;
use Hypervel\Session\TokenMismatchException;
use Hypervel\Support\Arr;
use Hypervel\Support\InteractsWithTime;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class PreventRequestForgery
{
    use ExcludesPaths;
    use InteractsWithTime;

    /**
     * The URIs that should be excluded.
     *
     * @var array<int, string>
     */
    protected array $except = [];

    /**
     * The globally ignored URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected static array $neverVerify = [];

    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     */
    protected bool $addHttpCookie = true;

    /**
     * Indicates whether requests from the same site should be allowed.
     */
    protected static bool $allowSameSite = false;

    /**
     * Indicates whether only origin verification should be used.
     */
    protected static bool $originOnly = false;

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected Application $app,
        protected Encrypter $encrypter,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @throws \Hypervel\Session\TokenMismatchException
     * @throws \Hypervel\Http\Exceptions\OriginMismatchException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $this->isReading($request)
            || $this->runningUnitTests()
            || $this->inExceptArray($request)
            || $this->hasValidOrigin($request)
            || $this->tokensMatch($request)
        ) {
            return tap($next($request), function ($response) use ($request) {
                if ($this->shouldAddXsrfTokenCookie()) {
                    $this->addCookieToResponse($request, $response);
                }
            });
        }

        throw new TokenMismatchException('CSRF token mismatch.');
    }

    /**
     * Determine if the HTTP request uses a 'read' verb.
     */
    protected function isReading(Request $request): bool
    {
        return in_array($request->method(), ['HEAD', 'GET', 'OPTIONS']);
    }

    /**
     * Determine if the application is running unit tests.
     */
    protected function runningUnitTests(): bool
    {
        return $this->app->runningInConsole() && $this->app->runningUnitTests();
    }

    /**
     * Determine if the request has a valid origin based on the Sec-Fetch-Site header.
     *
     * @throws \Hypervel\Http\Exceptions\OriginMismatchException
     */
    protected function hasValidOrigin(Request $request): bool
    {
        $secFetchSite = $request->header('Sec-Fetch-Site');

        if ($secFetchSite === 'same-origin') {
            return true;
        }

        if ($secFetchSite === 'same-site' && static::$allowSameSite) {
            return true;
        }

        if (static::$originOnly) {
            throw new OriginMismatchException('Origin mismatch.');
        }

        return false;
    }

    /**
     * Determine if the session and input CSRF tokens match.
     */
    protected function tokensMatch(Request $request): bool
    {
        $token = $this->getTokenFromRequest($request);

        return is_string($request->session()->token())
               && is_string($token)
               && hash_equals($request->session()->token(), $token);
    }

    /**
     * Get the CSRF token from the request.
     */
    protected function getTokenFromRequest(Request $request): ?string
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if (! $token && $header = $request->header('X-XSRF-TOKEN')) {
            try {
                $token = CookieValuePrefix::remove($this->encrypter->decrypt($header, static::serialized()));
            } catch (DecryptException) {
                $token = '';
            }
        }

        return $token;
    }

    /**
     * Determine if the cookie should be added to the response.
     */
    public function shouldAddXsrfTokenCookie(): bool
    {
        if (static::$originOnly) {
            return false;
        }

        return $this->addHttpCookie;
    }

    /**
     * Add the CSRF token to the response cookies.
     */
    protected function addCookieToResponse(Request $request, Response $response): Response
    {
        $config = config('session');

        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        $response->headers->setCookie($this->newCookie($request, $config));

        return $response;
    }

    /**
     * Create a new "XSRF-TOKEN" cookie that contains the CSRF token.
     */
    protected function newCookie(Request $request, array $config): Cookie
    {
        return new Cookie(
            'XSRF-TOKEN',
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }

    /**
     * Indicate that the given URIs should be excluded from CSRF verification.
     */
    public static function except(array|string $uris): void
    {
        static::$neverVerify = array_values(array_unique(
            array_merge(static::$neverVerify, Arr::wrap($uris))
        ));
    }

    /**
     * Indicate that requests from the same site should be allowed.
     */
    public static function allowSameSite(bool $allow = true): void
    {
        static::$allowSameSite = $allow;
    }

    /**
     * Indicate that only origin verification should be used.
     */
    public static function useOriginOnly(bool $originOnly = true): void
    {
        static::$originOnly = $originOnly;
    }

    /**
     * Get the URIs that should be excluded.
     */
    public function getExcludedPaths(): array
    {
        return array_merge($this->except, static::$neverVerify);
    }

    /**
     * Determine if the cookie contents should be serialized.
     */
    public static function serialized(): bool
    {
        return EncryptCookies::serialized('XSRF-TOKEN');
    }

    /**
     * Flush the state of the middleware.
     */
    public static function flushState(): void
    {
        static::$neverVerify = [];
        static::$allowSameSite = false;
        static::$originOnly = false;
    }
}
