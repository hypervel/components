<?php

declare(strict_types=1);

namespace Hypervel\Reverb;

use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Exceptions\InvalidApplication;
use Hypervel\Support\Collection;

class ConfigApplicationProvider implements ApplicationProvider
{
    /**
     * Create a new config provider instance.
     */
    public function __construct(protected Collection $applications)
    {
    }

    /**
     * Get all configured applications as Application instances.
     *
     * @return Collection<int, Application>
     */
    public function all(): Collection
    {
        return $this->applications->map(function (array $app) {
            return $this->findById($app['app_id']);
        });
    }

    /**
     * Find an application instance by ID.
     *
     * @throws InvalidApplication
     */
    public function findById(string $id): Application
    {
        return $this->find('app_id', $id);
    }

    /**
     * Find an application instance by key.
     *
     * @throws InvalidApplication
     */
    public function findByKey(string $key): Application
    {
        return $this->find('key', $key);
    }

    /**
     * Find an application instance.
     *
     * @throws InvalidApplication
     */
    public function find(string $key, mixed $value): Application
    {
        $app = $this->applications->firstWhere($key, $value);

        if (! $app) {
            throw new InvalidApplication();
        }

        return new Application(
            $app['app_id'],
            $app['key'],
            $app['secret'],
            (int) $app['ping_interval'],
            (int) ($app['activity_timeout'] ?? 30),
            $app['allowed_origins'],
            (int) $app['max_message_size'],
            isset($app['max_connections']) ? (int) $app['max_connections'] : null,
            $app['accept_client_events_from'] ?? 'all',
            $app['rate_limiting'] ?? null,
            $app['options'] ?? [],
            $app['webhooks'] ?? [],
        );
    }
}
