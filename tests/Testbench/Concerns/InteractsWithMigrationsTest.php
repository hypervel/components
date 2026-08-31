<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\default_migration_path;

class InteractsWithMigrationsTest extends TestCase
{
    private int $hypervelMigrationOptionResolutions = 0;

    /**
     * @var array<string, mixed>
     */
    private array $migrationOptions = [];

    #[Test]
    public function itSkipsMigrationPathWorkWhenWorkbenchInstallationIsDisabled(): void
    {
        $fixture = new DisabledHypervelMigrationsFixture;

        $fixture->prepare();

        $this->assertFalse($fixture->registrationChecked);
        $this->assertFalse($fixture->migrationsLoaded);
    }

    #[Test]
    public function itPreparesHypervelMigrationOptionsOnce(): void
    {
        $this->loadHypervelMigrations([
            '--database' => 'testing',
            '--pretend' => true,
        ]);

        $this->assertSame(1, $this->hypervelMigrationOptionResolutions);
        $this->assertSame([
            '--database' => 'testing',
            '--pretend' => true,
            '--path' => default_migration_path(),
            '--realpath' => true,
        ], $this->migrationOptions);
    }

    /**
     * Resolve Hypervel migration options.
     *
     * @param array<string, mixed>|string $database
     * @return array<string, mixed>
     */
    protected function resolveHypervelMigrationsOptions(array|string $database = []): array
    {
        ++$this->hypervelMigrationOptionResolutions;

        return parent::resolveHypervelMigrationsOptions($database);
    }

    /**
     * Capture migration processor options.
     *
     * @param array<string, mixed> $options
     */
    protected function runMigrationProcessor(ApplicationContract $app, array $options): void
    {
        $this->migrationOptions = $options;
    }
}

class DisabledHypervelMigrationsFixture
{
    use WithHypervelMigrations;

    public bool $registrationChecked = false;

    public bool $migrationsLoaded = false;

    /**
     * Get the disabled Workbench configuration.
     */
    public static function cachedConfigurationForWorkbench(): ConfigContract
    {
        return new Config(['workbench' => ['install' => false]]);
    }

    /**
     * Prepare Hypervel migrations.
     */
    public function prepare(): void
    {
        $this->prepareHypervelMigrations();
    }

    /**
     * Record migration-path registration checks.
     */
    protected function shouldRegisterMigrationPaths(): bool
    {
        $this->registrationChecked = true;

        return false;
    }

    /**
     * Record direct migration loading.
     */
    protected function loadHypervelMigrations(): void
    {
        $this->migrationsLoaded = true;
    }
}
