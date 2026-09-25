<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hypervel\Contracts\Foundation\MaintenanceMode;

class ArrayMaintenanceMode implements MaintenanceMode
{
    /**
     * Indicates if maintenance mode is currently active.
     */
    protected bool $active = false;

    /**
     * The payload provided when maintenance mode was activated.
     */
    protected array $payload = [];

    /**
     * Take the application down for maintenance.
     */
    public function activate(array $payload): void
    {
        $this->active = true;
        $this->payload = $payload;
    }

    /**
     * Take the application out of maintenance.
     */
    public function deactivate(): void
    {
        $this->active = false;
        $this->payload = [];
    }

    /**
     * Determine if the application is currently down for maintenance.
     */
    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Get the data array which was provided when the application was placed into maintenance.
     */
    public function data(): array
    {
        return $this->payload;
    }
}
