<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware\Concerns;

use Hypervel\Http\Request;
use Hypervel\Support\Str;

trait ExcludesPaths
{
    /**
     * Determine if the request has a URI that should be excluded.
     */
    protected function inExceptArray(Request $request): bool
    {
        $fullUrl = null;
        $decodedPath = null;

        foreach ($this->getExcludedPaths() as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if (Str::is($except, $fullUrl ??= $request->fullUrl())
                || Str::is($except, $decodedPath ??= $request->decodedPath())) {
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
}
