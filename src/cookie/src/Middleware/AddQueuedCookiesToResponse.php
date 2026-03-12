<?php

declare(strict_types=1);

namespace Hypervel\Cookie\Middleware;

use Closure;
use Hypervel\Contracts\Cookie\QueueingFactory as CookieJar;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddQueuedCookiesToResponse
{
    /**
     * Create a new CookieQueue instance.
     */
    public function __construct(
        protected CookieJar $cookies,
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->cookies->getQueuedCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
