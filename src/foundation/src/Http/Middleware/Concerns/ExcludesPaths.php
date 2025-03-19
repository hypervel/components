<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware\Concerns;

use Hyperf\Stringable\Str;
use Psr\Http\Message\ServerRequestInterface;

trait ExcludesPaths
{
    /**
     * Determine if the request has a URI that should be excluded.
     */
    protected function inExceptArray(ServerRequestInterface $request): bool
    {
        $fullUrl = $this->getFullUrl($request);
        $decodedUrl = $this->decodedPath($request);
        foreach ($this->getExcludedPaths() as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if (Str::is($except, $fullUrl) || Str::is($except, $decodedUrl)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the URIs that should be excluded.
     */
    public function getExcludedPaths(): array
    {
        return $this->except ?? [];
    }

    /**
     * Get the full URL for the request.
     */
    protected function getFullUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $query = $uri->getQuery();

        $baseUrl = $uri->getScheme() . '://' . $uri->getAuthority();
        $pathInfo = $uri->getPath();

        $question = $baseUrl . $pathInfo === '/' ? '/?' : '?';
        $url = $baseUrl . $pathInfo;

        return $query ? $url . $question . $query : $url;
    }

    /**
     * Parse the pattern and format for usage.
     */
    protected function decodedPath(ServerRequestInterface $request): string
    {
        $path = trim($request->getUri()->getPath(), '/');

        return rawurldecode($path === '' ? '/' : $path);
    }
}
