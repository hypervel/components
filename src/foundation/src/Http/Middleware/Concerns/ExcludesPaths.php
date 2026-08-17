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
        $excluded = $this->getExcludedPaths();

        if ($excluded === []) {
            return false;
        }

        // Reading the host validates it against the trusted host patterns, and
        // that must happen even when a path matches: is() only looks at the
        // path, so short-circuiting on it would let an untrusted Host header
        // through on excluded paths. Resolving it once up front keeps the
        // validation and lets the cheap path match run first below.
        $request->getHost();

        foreach ($excluded as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            // is() is checked first: it matches against the decoded path, while
            // fullUrlIs() has to rebuild the absolute URL. Both are free of
            // further side effects, so the order only decides which one gets to
            // short-circuit the other.
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
