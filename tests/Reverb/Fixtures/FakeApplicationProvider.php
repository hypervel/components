<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Fixtures;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\ApplicationProvider;
use Hypervel\Reverb\Exceptions\InvalidApplication;
use Hypervel\Support\Collection;

class FakeApplicationProvider implements ApplicationProvider
{
    /**
     * The applications collection.
     *
     * @var Collection<int, Application>
     */
    protected Collection $apps;

    /**
     * Create a new fake provider instance.
     */
    public function __construct()
    {
        $this->apps = collect([
            new Application('id', 'key', 'secret', 60, 30, ['*'], 10_000, options: [
                'host' => 'localhost',
                'port' => 443,
                'scheme' => 'https',
                'useTLS' => true,
            ]),
        ]);
    }

    /**
     * Get all of the configured applications.
     *
     * @return Collection<int, Application>
     */
    public function all(): Collection
    {
        return $this->apps;
    }

    /**
     * Find an application instance by ID.
     *
     * @throws InvalidApplication
     */
    public function findById(string $id): Application
    {
        return $this->apps->first();
    }

    /**
     * Find an application instance by key.
     *
     * @throws InvalidApplication
     */
    public function findByKey(string $key): Application
    {
        return $this->apps->first();
    }
}
