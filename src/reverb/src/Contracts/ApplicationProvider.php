<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Contracts;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Exceptions\InvalidApplication;
use Hypervel\Support\Collection;

interface ApplicationProvider
{
    /**
     * Get all configured applications.
     *
     * @return Collection<int, Application>
     */
    public function all(): Collection;

    /**
     * Find an application by ID.
     *
     * @throws InvalidApplication
     */
    public function findById(string $id): Application;

    /**
     * Find an application by key.
     *
     * @throws InvalidApplication
     */
    public function findByKey(string $key): Application;
}
