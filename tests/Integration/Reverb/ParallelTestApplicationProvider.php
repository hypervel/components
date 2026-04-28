<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Support\Collection;

/**
 * Test-only ApplicationProvider that dynamically resolves per-worker apps.
 *
 * Wraps the real ConfigApplicationProvider and intercepts lookups matching
 * the parallel test pattern (app ID 2XXXXX, key reverb-parallel-N). This
 * allows each paratest worker to use isolated app credentials without
 * pre-registering a fixed number of apps on the server.
 */
final class ParallelTestApplicationProvider implements ApplicationProvider
{
    private Application $baseApp;

    public function __construct(
        private ApplicationProvider $base,
    ) {
        $this->baseApp = $base->all()->first();
    }

    /**
     * Get all configured applications.
     */
    public function all(): Collection
    {
        return $this->base->all();
    }

    /**
     * Find an application by ID.
     */
    public function findById(string $id): Application
    {
        if (preg_match('/^2\d{5}$/', $id)) {
            return $this->parallelApp((int) $id - 200000);
        }

        return $this->base->findById($id);
    }

    /**
     * Find an application by key.
     */
    public function findByKey(string $key): Application
    {
        if (preg_match('/^reverb-parallel-(\d+)$/', $key, $matches)) {
            return $this->parallelApp((int) $matches[1]);
        }

        return $this->base->findByKey($key);
    }

    /**
     * Create a parallel test app for the given worker token.
     *
     * Inherits all settings from the first configured app (app 0) and only
     * overrides the credentials for per-worker isolation.
     */
    private function parallelApp(int $token): Application
    {
        $base = $this->baseApp;

        return new Application(
            id: (string) (200000 + $token),
            key: "reverb-parallel-{$token}",
            secret: "reverb-parallel-secret-{$token}",
            pingInterval: $base->pingInterval(),
            activityTimeout: $base->activityTimeout(),
            allowedOrigins: $base->allowedOrigins(),
            maxMessageSize: $base->maxMessageSize(),
            maxConnections: $base->maxConnections(),
            acceptClientEventsFrom: $base->acceptClientEventsFrom(),
            rateLimiting: $base->rateLimiting(),
            options: $base->options(),
            webhooks: $base->webhooks(),
        );
    }
}
