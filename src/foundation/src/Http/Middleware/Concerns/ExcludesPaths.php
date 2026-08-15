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

            // is() is checked first: it matches against the decoded path, while
            // fullUrlIs() has to rebuild the absolute URL. Both are pure, so the
            // order only decides which one gets to short-circuit the other.
            if ($request->is($except) || $request->fullUrlIs($except)) {
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
