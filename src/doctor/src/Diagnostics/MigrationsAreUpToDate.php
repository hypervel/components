<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Database\Migrations\Migrator;
use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Details;
use Hypervel\Support\Facades\Artisan;
use Swoole\Coroutine\CanceledException;
use Throwable;

class MigrationsAreUpToDate extends Diagnostic implements Fixable
{
    public string $name = 'Migrations are current';

    public string $group = 'database';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'no-files' => 'The application does not have migration files.',
            'repository-missing' => Message::make(
                summary: 'The migrations table does not exist.',
                remediation: 'Run `php artisan migrate` to create the migrations table and apply the migrations.',
                confirmation: 'Create the migrations table and apply pending migrations using `php artisan migrate`?',
            )->link(Link::docs('migrations', 'running-migrations')),
            'inspection-failed' => 'The application could not inspect database migrations.',
            'current' => 'Database migrations are current.',
            'pending' => Message::make(
                summary: 'Database migrations are pending.',
                remediation: 'Run `php artisan migrate` to apply the pending migrations.',
                confirmation: 'Apply pending migrations using `php artisan migrate`?',
            )->link(Link::docs('migrations', 'running-migrations')),
            'migration-failed' => 'Database migrations could not be applied.',
            'migrated' => 'Database migrations were applied.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        try {
            $files = $migrator->getMigrationFiles(array_values(array_unique([
                ...$migrator->paths(),
                database_path('migrations'),
            ])));

            if ($files === []) {
                return $this->pass('no-files');
            }

            return $migrator->usingConnection(null, function () use ($migrator, $files): DiagnosticResult {
                if (! $migrator->repositoryExists()) {
                    return $this->fail('repository-missing')->fixable(EnvironmentMode::Local);
                }

                $pending = $migrator->getPendingMigrations(
                    $files,
                    $migrator->getRepository()->getRan(),
                );

                if ($pending === []) {
                    return $this->pass('current');
                }

                return $this->fail('pending')
                    ->fixable(EnvironmentMode::Local)
                    ->withDetails(Details::bullets(array_map($migrator->getMigrationName(...), $pending)));
            });
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            return $this->fail('inspection-failed')
                ->withDetails($e->getMessage());
        }
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        try {
            if (Artisan::call('migrate', ['--no-interaction' => true]) !== 0) {
                return $this->fixFailed('migration-failed')
                    ->withDetails(Details::processOutput(
                        Artisan::output(),
                        '',
                        'The migrate command exited without output.',
                    ));
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->fixFailed('migration-failed')
                ->withDetails($exception->getMessage());
        }

        return $this->fixed('migrated');
    }
}
