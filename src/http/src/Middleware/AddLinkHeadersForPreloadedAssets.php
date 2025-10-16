<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Vite;
use Swow\Psr7\Message\ResponsePlusInterface;

class AddLinkHeadersForPreloadedAssets
{
    /**
     * Configure the middleware.
     */
    public static function using(int $limit): string
    {
        return static::class . ':' . $limit;
    }

    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next, ?int $limit = null): ResponsePlusInterface
    {
        return tap($next($request), function ($response) use ($limit) {
            if ($response instanceof ResponsePlusInterface && Vite::preloadedAssets() !== []) {
                $response->addHeader('Link', (new Collection(Vite::preloadedAssets()))
                    ->when($limit, fn ($assets, $limit) => $assets->take($limit))
                    ->map(fn ($attributes, $url) => "<{$url}>; " . implode('; ', $attributes))
                    ->join(', '));
            }
        });
    }
}
