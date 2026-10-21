<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Middleware;

use Closure;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Passport\ApiTokenCookieFactory;
use Hypervel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class CreateFreshApiToken
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected ApiTokenCookieFactory $cookieFactory,
    ) {
    }

    /**
     * Specify the guard for the middleware.
     */
    public static function using(?string $guard = null): string
    {
        $guard = is_null($guard) ? '' : ':' . $guard;

        return static::class . $guard;
    }

    /**
     * Handle an incoming request.
     *
     * @param (Closure(Request): BaseResponse) $next
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): BaseResponse
    {
        $response = $next($request);

        if ($this->shouldReceiveFreshToken($request, $response, $guard)) {
            /** @var JsonResponse|Response $response */
            $response->withCookie($this->cookieFactory->make(
                $request->user($guard)->getAuthIdentifier(),
                $request->session()->token(),
                $guard
            ));
        }

        return $response;
    }

    /**
     * Determine if the given request should receive a fresh token.
     */
    protected function shouldReceiveFreshToken(Request $request, BaseResponse $response, ?string $guard): bool
    {
        return $this->requestShouldReceiveFreshToken($request, $guard)
               && $this->responseShouldReceiveFreshToken($response);
    }

    /**
     * Determine if the request should receive a fresh token.
     */
    protected function requestShouldReceiveFreshToken(Request $request, ?string $guard): bool
    {
        return $request->isMethod('GET') && $request->user($guard);
    }

    /**
     * Determine if the response should receive a fresh token.
     */
    protected function responseShouldReceiveFreshToken(BaseResponse $response): bool
    {
        return ($response instanceof Response
                || $response instanceof JsonResponse)
                && ! $this->alreadyContainsToken($response);
    }

    /**
     * Determine if the given response already contains an API token.
     *
     * This avoids us overwriting a just "refreshed" token.
     */
    protected function alreadyContainsToken(BaseResponse $response): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === Passport::cookie()) {
                return true;
            }
        }

        return false;
    }
}
