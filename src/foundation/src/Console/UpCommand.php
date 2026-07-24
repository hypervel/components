<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Foundation\Console\Concerns\ReloadsWorkers;
use Hypervel\Foundation\Events\MaintenanceModeDisabled;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'up')]
class UpCommand extends Command
{
    use ReloadsWorkers;

    /**
     * The console command name.
     */
    protected ?string $name = 'up';

    /**
     * The console command description.
     */
    protected string $description = 'Bring the application out of maintenance mode';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $stateCommitted = false;

        try {
            if (! $this->hypervel->maintenanceMode()->active()) {
                $this->components->info('Application is already up.');

                return 0;
            }

            $this->hypervel->maintenanceMode()->deactivate();
            $stateCommitted = true;

            $exception = null;

            try {
                $this->hypervel->make('events')->dispatch(new MaintenanceModeDisabled);
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }

            try {
                $this->reloadWorkers();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }

            if ($exception !== null) {
                throw $exception;
            }

            $this->components->info('Application is now live.');
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
            }

            $this->components->error(sprintf(
                $stateCommitted
                    ? 'The application is live, but a follow-up operation failed: %s.'
                    : 'Failed to disable maintenance mode: %s.',
                $e->getMessage(),
            ));

            return 1;
        }

        return 0;
    }
}
