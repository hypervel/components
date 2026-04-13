<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Ssr;

class BundleDetector
{
    /**
     * The cached bundle path.
     */
    private static ?string $cachedBundle = null;

    /**
     * Whether detection has been performed.
     */
    private static bool $bundleDetected = false;

    /**
     * Detect and return the path to the SSR bundle file.
     *
     * The result is cached for the worker lifetime to avoid
     * repeated filesystem checks on every SSR dispatch.
     */
    public function detect(): ?string
    {
        if (! self::$bundleDetected) {
            self::$cachedBundle = $this->findBundle();
            self::$bundleDetected = true;
        }

        return self::$cachedBundle;
    }

    /**
     * Search candidate paths for the SSR bundle file.
     */
    private function findBundle(): ?string
    {
        return collect([
            config('inertia.ssr.bundle'),
            base_path('bootstrap/ssr/ssr.js'),
            base_path('bootstrap/ssr/app.js'),
            base_path('bootstrap/ssr/ssr.mjs'),
            base_path('bootstrap/ssr/app.mjs'),
            public_path('js/ssr.js'),
            public_path('js/app.js'),
        ])->filter()->first(function ($path) {
            return file_exists($path);
        });
    }

    /**
     * Reset the cached bundle detection state.
     */
    public static function flushState(): void
    {
        self::$cachedBundle = null;
        self::$bundleDetected = false;
    }
}
