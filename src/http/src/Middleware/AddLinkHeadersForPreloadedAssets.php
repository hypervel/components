<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Vite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AddLinkHeadersForPreloadedAssets
{
    /**
     * Handle the incoming request.
     */
    public function handle(ServerRequestInterface $request, Closure $next): ResponseInterface
    {
        $response = $next($request);

        if (Vite::preloadedAssets() !== []) {
            $preloaded = (new Collection(Vite::preloadedAssets()))
                ->map(fn ($attributes, $url) => "<{$url}>; " . implode('; ', $attributes))
                ->join(', ');
            $response = $response->withHeader('Link', $preloaded);
        }

        return $response;
    }
}
