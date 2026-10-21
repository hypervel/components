<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Middleware;

use Closure;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Support\Uri;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ValidateOrigin
{
    /**
     * Create the origin middleware.
     */
    public function __construct(protected Repository $config)
    {
    }

    /**
     * Reject browser origins that the application has not allowed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origins = $request->headers->all('origin');

        if ($origins === []) {
            return $next($request);
        }

        $origin = count($origins) === 1 ? $this->origin($origins[0]) : null;

        if ($origin !== null) {
            // Trust configured origins, never the incoming request's Host header.
            if ($origin === $this->origin($this->config->string('app.url'), allowPath: true)) {
                return $next($request);
            }

            foreach ($this->config->array('mcp.allowed_origins') as $allowedOrigin) {
                if ($origin === $this->origin($allowedOrigin)) {
                    return $next($request);
                }
            }
        }

        throw new AccessDeniedHttpException('Origin is not allowed.');
    }

    /**
     * Normalize an HTTP origin without making a network request.
     */
    protected function origin(string $value, bool $allowPath = false): ?string
    {
        try {
            $uri = Uri::of($value);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (! in_array($uri->scheme(), ['http', 'https'], true)
            || $uri->host() === null || $uri->user() !== null) {
            return null;
        }

        $origin = $uri->scheme() . '://' . $uri->authority();

        return $allowPath || $uri->value() === $origin ? $origin : null;
    }
}
