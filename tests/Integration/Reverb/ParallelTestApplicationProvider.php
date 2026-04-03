<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\ConfigApplicationProvider;
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
    public function __construct(
        private ConfigApplicationProvider $base,
    ) {
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
     */
    private function parallelApp(int $token): Application
    {
        return new Application(
            id: (string) (200000 + $token),
            key: "reverb-parallel-{$token}",
            secret: "reverb-parallel-secret-{$token}",
            pingInterval: 60,
            activityTimeout: 30,
            allowedOrigins: ['*'],
            maxMessageSize: 10_000,
            acceptClientEventsFrom: 'members',
            webhooks: [
                'url' => 'https://example.com/webhook',
                'secret' => 'webhook-secret',
                'events' => ['channel_occupied', 'channel_vacated', 'member_added', 'member_removed', 'client_event'],
            ],
        );
    }
}
