<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use DateTimeInterface;
use Exception;
use Hypervel\Console\Command;
use Hypervel\Foundation\Events\MaintenanceModeEnabled;
use Hypervel\Foundation\Exceptions\RegisterErrorViewPaths;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'down')]
class DownCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'down {--redirect= : The path that users should be redirected to}
                                 {--render= : The view that should be prerendered for display during maintenance mode}
                                 {--retry= : The number of seconds or the datetime after which the request may be retried}
                                 {--refresh= : The number of seconds after which the browser may refresh}
                                 {--secret= : The secret phrase that may be used to bypass maintenance mode}
                                 {--with-secret : Generate a random secret phrase that may be used to bypass maintenance mode}
                                 {--status=503 : The status code that should be used when returning the maintenance mode response}';

    /**
     * The console command description.
     */
    protected string $description = 'Put the application into maintenance / demo mode';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            if ($this->hypervel->maintenanceMode()->active() && ! $this->getSecret()) {
                $this->components->info('Application is already down.');

                return 0;
            }

            $downFilePayload = $this->getDownFilePayload();

            $this->hypervel->maintenanceMode()->activate($downFilePayload);

            file_put_contents(
                storage_path('framework/maintenance.php'),
                file_get_contents(__DIR__ . '/stubs/maintenance-mode.stub')
            );

            $this->hypervel->make('events')->dispatch(new MaintenanceModeEnabled);

            $this->reloadWorkers();

            $this->components->info('Application is now in maintenance mode.');

            if ($downFilePayload['secret'] !== null) {
                $this->components->info('You may bypass maintenance mode via [' . config('app.url') . "/{$downFilePayload['secret']}].");
            }
        } catch (Exception $e) {
            $this->components->error(sprintf(
                'Failed to enter maintenance mode: %s.',
                $e->getMessage(),
            ));

            return 1;
        }

        return 0;
    }

    /**
     * Get the payload to be placed in the "down" file.
     */
    protected function getDownFilePayload(): array
    {
        return [
            'except' => $this->excludedPaths(),
            'redirect' => $this->redirectPath(),
            'retry' => $this->getRetryTime(),
            'refresh' => $this->option('refresh'),
            'secret' => $this->getSecret(),
            'status' => (int) ($this->option('status') ?? 503),
            'template' => $this->option('render') ? $this->prerenderView() : null,
        ];
    }

    /**
     * Get the paths that should be excluded from maintenance mode.
     */
    protected function excludedPaths(): array
    {
        try {
            $appMiddleware = $this->hypervel->getNamespace() . 'Http\Middleware\PreventRequestsDuringMaintenance';

            return $this->hypervel->make($appMiddleware)->getExcludedPaths();
        } catch (Throwable) {
            try {
                return $this->hypervel->make(PreventRequestsDuringMaintenance::class)->getExcludedPaths();
            } catch (Throwable) {
                return [];
            }
        }
    }

    /**
     * Get the path that users should be redirected to.
     */
    protected function redirectPath(): ?string
    {
        if ($this->option('redirect') && $this->option('redirect') !== '/') {
            return '/' . trim($this->option('redirect'), '/');
        }

        return $this->option('redirect');
    }

    /**
     * Prerender the specified view so that it can be rendered even before loading Composer.
     */
    protected function prerenderView(): string
    {
        (new RegisterErrorViewPaths)();

        return view($this->option('render'), [
            'retryAfter' => $this->option('retry'),
        ])->render();
    }

    /**
     * Get the number of seconds or date/time the client should wait before retrying their request.
     */
    protected function getRetryTime(): int|string|null
    {
        $retry = $this->option('retry');

        if (is_numeric($retry) && $retry > 0) {
            return (int) $retry;
        }

        if (is_string($retry) && ! empty($retry)) {
            try {
                $date = Carbon::parse($retry);

                return $date->format(DateTimeInterface::RFC7231);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Get the secret phrase that may be used to bypass maintenance mode.
     */
    protected function getSecret(): ?string
    {
        return match (true) {
            ! is_null($this->option('secret')) => $this->option('secret'),
            $this->option('with-secret') => Str::random(),
            default => null,
        };
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
