<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

class ProcessInspector
{
    /**
     * Create a new process inspector instance.
     *
     * @param Exec $exec the command executor
     */
    public function __construct(
        public Exec $exec
    ) {
    }

    /**
     * Get the IDs of all Horizon processes running on the system.
     */
    public function current(): array
    {
        return array_diff(
            $this->exec->run('pgrep -f [h]orizon'),
            $this->exec->run('pgrep -f horizon:purge')
        );
    }

    /**
     * Get an array of running Horizon processes that can't be accounted for.
     */
    public function orphaned(): array
    {
        return array_diff($this->current(), $this->monitoring());
    }

    /**
     * Get all of the process IDs Horizon is actively monitoring.
     */
    public function monitoring(): array
    {
        return collect(app(SupervisorRepository::class)->all())
            ->pluck('pid')
            ->pipe(function (Collection $processes) {
                foreach ($processes as $process) {
                    /** @var int|string $process */
                    $processes = $processes->merge($this->exec->run('pgrep -P ' . (string) $process));
                }

                return $processes;
            })
            ->merge(
                Arr::pluck(app(MasterSupervisorRepository::class)->all(), 'pid')
            )->all();
    }
}
