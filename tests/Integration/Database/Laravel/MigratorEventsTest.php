<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Database\Events\MigrationEnded;
use Hypervel\Database\Events\MigrationsEnded;
use Hypervel\Database\Events\MigrationSkipped;
use Hypervel\Database\Events\MigrationsStarted;
use Hypervel\Database\Events\MigrationStarted;
use Hypervel\Database\Events\NoPendingMigrations;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MigratorEventsTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function migrateOptions()
    {
        return [
            '--path' => realpath(__DIR__ . '/stubs/'),
            '--realpath' => true,
        ];
    }

    public function testMigrationEventsAreFired()
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

    public function testMigrationEventsContainTheOptionsAndPretendFalse()
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

    public function testMigrationEventsContainTheOptionsAndPretendTrue()
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

    public function testMigrationEventsContainTheMigrationAndMethod()
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
            return $event->method === 'up' && $event->migration instanceof Migration;
        });
        Event::assertDispatched(MigrationStarted::class, function ($event) {
            return $event->method === 'down' && $event->migration instanceof Migration;
        });
        Event::assertDispatched(MigrationEnded::class, function ($event) {
            return $event->method === 'up' && $event->migration instanceof Migration;
        });
        Event::assertDispatched(MigrationEnded::class, function ($event) {
            return $event->method === 'down' && $event->migration instanceof Migration;
        });
    }

    public function testTheNoMigrationEventIsFiredWhenNothingToMigrate()
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

    public function testMigrationSkippedEventIsFired()
    {
        Event::fake();

        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/stubs/2014_10_13_000000_skipped_migration.php'),
            '--realpath' => true,
        ]);

        Event::assertDispatched(MigrationSkipped::class, function ($event) {
            return $event->migrationName === '2014_10_13_000000_skipped_migration';
        });
    }
}
