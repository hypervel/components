<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Exception;
use Hypervel\Console\Command;
use Hypervel\Foundation\Events\MaintenanceModeDisabled;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'up')]
class UpCommand extends Command
{
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
        try {
            if (! $this->hypervel->maintenanceMode()->active()) {
                $this->components->info('Application is already up.');

                return 0;
            }

            $this->hypervel->maintenanceMode()->deactivate();

            if (is_file(storage_path('framework/maintenance.php'))) {
                unlink(storage_path('framework/maintenance.php'));
            }

            $this->hypervel->make('events')->dispatch(new MaintenanceModeDisabled());

            $this->reloadWorkers();

            $this->components->info('Application is now live.');
        } catch (Exception $e) {
            $this->components->error(sprintf(
                'Failed to disable maintenance mode: %s.',
                $e->getMessage(),
            ));

            return 1;
        }

        return 0;
    }

    /**
     * Attempt a best-effort worker reload via SIGUSR1.
     */
    protected function reloadWorkers(): void
    {
        $pidFile = $this->hypervel->make('config')->get('server.settings.pid_file');

        if (empty($pidFile) || ! is_file($pidFile)) {
            return;
        }

        $pid = (int) file_get_contents($pidFile);

        if ($pid > 0 && posix_kill($pid, 0)) {
            posix_kill($pid, SIGUSR1);
        }
    }
}
