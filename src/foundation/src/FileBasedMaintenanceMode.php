<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;

class FileBasedMaintenanceMode implements MaintenanceModeContract
{
    public function __construct(protected Filesystem $files = new Filesystem)
    {
    }

    /**
     * Take the application down for maintenance.
     */
    public function activate(array $payload): void
    {
        $this->files->replace(
            $this->path(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Take the application out of maintenance.
     */
    public function deactivate(): void
    {
        $path = $this->path();

        if ($this->files->exists($path)
            && ! $this->files->delete($path)
            && $this->files->exists($path)) {
            throw new RuntimeException("Unable to remove the maintenance mode file [{$path}].");
        }
    }

    /**
     * Determine if the application is currently down for maintenance.
     */
    public function active(): bool
    {
        return $this->files->exists($this->path());
    }

    /**
     * Get the data array which was provided when the application was placed into maintenance.
     */
    public function data(): array
    {
        $data = json_decode($this->files->get($this->path()), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('The maintenance mode file does not contain a valid payload.');
        }

        return $data;
    }

    /**
     * Get the path where the file is stored that signals that the application is down for maintenance.
     */
    protected function path(): string
    {
        return storage_path('framework/down');
    }
}
