<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware\Concerns;

use Hypervel\Http\Request;

trait ExcludesPaths
{
    /**
     * Determine if the request has a URI that should be excluded.
     */
    protected function inExceptArray(Request $request): bool
    {
        foreach ($this->getExcludedPaths() as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->fullUrlIs($except) || $request->is($except)) {
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
