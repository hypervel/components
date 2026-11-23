<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Middleware;

use Closure;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Request;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use Hypervel\Support\Uri;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

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
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origins = $request->headers->all('origin');

        if ($origins === [] || (count($origins) === 1 && $this->allowed($origins[0]))) {
            return $next($request);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => ErrorCode::INVALID_REQUEST->value,
                'message' => 'Forbidden: The request origin is not allowed.',
            ],
        ], 403);
    }

    /**
     * Determine whether the request origin is allowed.
     */
    protected function allowed(string $origin): bool
    {
        $normalized = $this->normalize($origin);

        // Browsers send only a scheme, host and port, so a header with a path, query or fragment is malformed.
        if ($normalized === null || ! $this->isBareOrigin($origin)) {
            return false;
        }

        // Trust configured origins, never the incoming request's Host header.
        if ($normalized === $this->normalize($this->config->string('app.url'))) {
            return true;
        }

        // Unlike upstream, loopback origins are not trusted implicitly: a local web app on the
        // browser user's machine is not necessarily one the server operator trusts. A configured
        // entry with a path never matches either, rather than trusting every app on its host.
        return array_any(
            // @phpstan-ignore hypervel.untypedConfigRead (An origin may be configured as one string or a list.)
            Arr::wrap($this->config->get('mcp.allowed_origins')),
            fn (mixed $allowed): bool => $allowed === '*' || (
                is_string($allowed)
                && $this->isBareOrigin(Str::chopEnd($allowed, '/'))
                && $this->normalize($allowed) === $normalized
            ),
        );
    }

    /**
     * Determine whether the value has no path, query or fragment after its scheme and authority.
     */
    protected function isBareOrigin(string $value): bool
    {
        return substr_count($value, '/') === 2 && strpbrk($value, '?#') === false;
    }

    /**
     * Reduce an HTTP URL to its normalized origin without making a network request.
     */
    protected function normalize(string $origin): ?string
    {
        try {
            $uri = Uri::of($origin);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (! in_array($uri->scheme(), ['http', 'https'], true)
            || $uri->host() === null || $uri->user() !== null) {
            return null;
        }

        return $uri->scheme() . '://' . $uri->authority();
    }
}
