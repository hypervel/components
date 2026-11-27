<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Events\MigrationEnded;
use Hypervel\Database\Events\MigrationFailed;
use Hypervel\Database\Events\MigrationsEnded;
use Hypervel\Database\Events\MigrationSkipped;
use Hypervel\Database\Events\MigrationsStarted;
use Hypervel\Database\Events\MigrationStarted;
use Hypervel\Database\Events\NoPendingMigrations;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class MigratorEventsTest extends TestCase
{
    use DatabaseMigrations;

    protected string $errorLogDirectory;

    /**
     * Prepare an isolated directory for reporter failures.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->errorLogDirectory = ParallelTesting::tempDir('MigratorEventsTest');
        (new Filesystem)->deleteDirectory($this->errorLogDirectory);
        mkdir($this->errorLogDirectory, recursive: true);
    }

    /**
     * Remove the owned reporter output directory.
     */
    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->errorLogDirectory);

        parent::tearDown();
    }

    protected function migrateOptions()
    {
        return [
            '--path' => realpath(__DIR__ . '/Fixtures/'),
            '--realpath' => true,
        ];
    }

    public function testMigrationEventsAreFired(): void
    {
        Event::fake();

        $this->artisan('migrate', $this->migrateOptions());
        $this->artisan('migrate:rollback', $this->migrateOptions());

        Event::assertDispatched(MigrationsStarted::class, 2);
        Event::assertDispatched(MigrationsEnded::class, 2);
        Event::assertDispatched(MigrationStarted::class, 2);
        Event::assertDispatched(MigrationEnded::class, 2);
        Event::assertDispatched(MigrationSkipped::class, 1);
    }

    public function testPassiveObserversDoNotCauseMigrationEventsToDispatch(): void
    {
        $observedEvents = [];
        $events = $this->app->make(Dispatcher::class);

        foreach ([
            MigrationEnded::class,
            MigrationsEnded::class,
            MigrationSkipped::class,
            MigrationsStarted::class,
            MigrationStarted::class,
            NoPendingMigrations::class,
        ] as $event) {
            $events->observe($event, static function (object $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            });
        }

        $this->artisan('migrate', $this->migrateOptions());
        $this->artisan('migrate:rollback', $this->migrateOptions());

        $this->assertSame([], $observedEvents);
    }

    public function testMigrationEventsContainTheOptionsAndPretendFalse(): void
    {
        Event::fake();

        $this->artisan('migrate', $this->migrateOptions());
        $this->artisan('migrate:rollback', $this->migrateOptions());

        Event::assertDispatched(MigrationsStarted::class, function ($event) {
            return $event->method === 'up'
                && is_array($event->options)
                && isset($event->options['pretend'])
                && $event->options['pretend'] === false;
        });
        Event::assertDispatched(MigrationsStarted::class, function ($event) {
            return $event->method === 'down'
                && is_array($event->options)
                && isset($event->options['pretend'])
                && $event->options['pretend'] === false;
        });
        Event::assertDispatched(MigrationsEnded::class, function ($event) {
            return $event->method === 'up'
                && is_array($event->options)
                && isset($event->options['pretend'])
                && $event->options['pretend'] === false;
        });
        Event::assertDispatched(MigrationsEnded::class, function ($event) {
            return $event->method === 'down'
                && is_array($event->options)
                && isset($event->options['pretend'])
                && $event->options['pretend'] === false;
        });
    }

    public function testMigrationEventsContainTheOptionsAndPretendTrue(): void
    {
        Event::fake();

        $this->artisan('migrate', $this->migrateOptions() + ['--pretend' => true]);
        $this->artisan('migrate:rollback', $this->migrateOptions()); // doesn't support pretend

        Event::assertDispatched(MigrationsStarted::class, function ($event) {
            return $event->method === 'up'
                && is_array($event->options)
                && isset($event->options['pretend'])
                && $event->options['pretend'] === true;
        });

        Event::assertDispatched(MigrationsEnded::class, function ($event) {
            return $event->method === 'up'
                && is_array($event->options)
                && isset($event->options['pretend'])
                && $event->options['pretend'] === true;
        });
    }

    public function testMigrationEventsContainTheMigrationAndMethod(): void
    {
        Event::fake();

        $this->artisan('migrate', $this->migrateOptions());
        $this->artisan('migrate:rollback', $this->migrateOptions());

        Event::assertDispatched(MigrationsStarted::class, function ($event) {
            return $event->method === 'up';
        });
        Event::assertDispatched(MigrationsStarted::class, function ($event) {
            return $event->method === 'down';
        });
        Event::assertDispatched(MigrationsEnded::class, function ($event) {
            return $event->method === 'up';
        });
        Event::assertDispatched(MigrationsEnded::class, function ($event) {
            return $event->method === 'down';
        });

        Event::assertDispatched(MigrationStarted::class, function ($event) {
            return $event->method === 'up'
                && $event->migration instanceof Migration
                && $event->name === '2014_10_12_000000_create_members_table';
        });
        Event::assertDispatched(MigrationStarted::class, function ($event) {
            return $event->method === 'down'
                && $event->migration instanceof Migration
                && $event->name === '2014_10_12_000000_create_members_table';
        });
        Event::assertDispatched(MigrationEnded::class, function ($event) {
            return $event->method === 'up'
                && $event->migration instanceof Migration
                && $event->name === '2014_10_12_000000_create_members_table'
                && is_float($event->duration);
        });
        Event::assertDispatched(MigrationEnded::class, function ($event) {
            return $event->method === 'down'
                && $event->migration instanceof Migration
                && $event->name === '2014_10_12_000000_create_members_table'
                && is_float($event->duration);
        });
    }

    public function testMigrationEndedEventContainsTheDuration(): void
    {
        Event::fake();

        $this->artisan('migrate', $this->migrateOptions());
        $this->artisan('migrate:rollback', $this->migrateOptions());

        Event::assertDispatched(MigrationEnded::class, function (MigrationEnded $event): bool {
            return $event->method === 'up'
                && is_float($event->duration)
                && $event->duration >= 0;
        });
        Event::assertDispatched(MigrationEnded::class, function (MigrationEnded $event): bool {
            return $event->method === 'down'
                && is_float($event->duration)
                && $event->duration >= 0;
        });
    }

    public function testTheNoMigrationEventIsFiredWhenNothingToMigrate(): void
    {
        Event::fake();

        $this->artisan('migrate');
        $this->artisan('migrate:rollback');

        Event::assertDispatched(NoPendingMigrations::class, function ($event) {
            return $event->method === 'up';
        });
        Event::assertDispatched(NoPendingMigrations::class, function ($event) {
            return $event->method === 'down';
        });
    }

    public function testMigrationSkippedEventIsFired(): void
    {
        Event::fake();

        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/Fixtures/2014_10_13_000000_skipped_migration.php'),
            '--realpath' => true,
        ]);

        Event::assertDispatched(MigrationSkipped::class, function (MigrationSkipped $event): bool {
            return $event->name === '2014_10_13_000000_skipped_migration';
        });
    }

    public function testMigrationFailedEventIsFired(): void
    {
        Event::fake();

        try {
            $this->artisan('migrate', [
                '--path' => realpath(__DIR__ . '/Fixtures/FailingMigrations'),
                '--realpath' => true,
            ]);

            $this->fail('The migration exception was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Jack ruined this migration', $exception->getMessage());
        }

        Event::assertDispatched(MigrationStarted::class, 1);
        Event::assertNotDispatched(MigrationEnded::class);
        Event::assertNotDispatched(MigrationsEnded::class);

        Event::assertDispatched(MigrationFailed::class, function (MigrationFailed $event) use ($exception): bool {
            return $event->method === 'up'
                && $event->migration instanceof Migration
                && $event->name === '2014_10_14_000000_failing_migration'
                && $event->exception === $exception;
        });
    }

    #[TestWith(['reported'])]
    #[TestWith(['reporter failure'])]
    #[TestWith(['listener cancellation'])]
    public function testFailingMigrationListenerPreservesThePrimaryFailureAndCancellation(string $case): void
    {
        Exceptions::fake();
        $listenerException = $case === 'listener cancellation'
            ? new CanceledException('The failure notification was canceled.')
            : new RuntimeException('The failure notification could not be delivered.');
        $migrationException = null;
        $previousErrorLog = null;

        if ($case === 'reporter failure') {
            $this->mock(ExceptionHandler::class)
                ->shouldReceive('report')->once()->with($listenerException)
                ->andThrow(new RuntimeException('The exception reporter failed.'));

            $previousErrorLog = ini_set('error_log', $this->errorLogDirectory . '/report.log');
        }

        Event::listen(MigrationFailed::class, function (MigrationFailed $event) use ($listenerException, &$migrationException): void {
            $migrationException = $event->exception;

            throw $listenerException;
        });

        try {
            try {
                $this->artisan('migrate', [
                    '--path' => realpath(__DIR__ . '/Fixtures/FailingMigrations'),
                    '--realpath' => true,
                ]);

                $this->fail('The migration failure or cancellation was not rethrown.');
            } catch (Throwable $exception) {
                $this->assertSame($case === 'listener cancellation' ? $listenerException : $migrationException, $exception);
            }

            if ($case === 'reporter failure') {
                $this->assertStringContainsString($listenerException->getMessage(), file_get_contents($this->errorLogDirectory . '/report.log'));
            } else {
                $reportedException = $case === 'listener cancellation' ? $migrationException : $listenerException;
                Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception === $reportedException);
            }
        } finally {
            if ($previousErrorLog !== null) {
                ini_set('error_log', $previousErrorLog);
            }
        }
    }
}
