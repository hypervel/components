<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Attributes\ResolvesHypervel;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Console\CreateSqliteDbCommand;
use Hypervel\Testbench\Foundation\Console\DropSqliteDbCommand;
use Hypervel\Testbench\Foundation\Console\InstallCommand;
use Hypervel\Testbench\Foundation\Console\PurgeSkeletonCommand;
use Hypervel\Testbench\Foundation\Console\ServeCommand;
use Hypervel\Testbench\Foundation\Console\SyncSkeletonCommand;
use Hypervel\Testbench\Foundation\Console\TestCommand;
use Hypervel\Testbench\Foundation\Console\VendorPublishCommand;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Testbench\Workbench\Workbench;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\workbench;

class TestbenchServiceProviderTest extends TestCase
{
    protected ?Config $suppliedConfiguration = null;

    /**
     * Get package providers.
     *
     * @param Application $app
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            TestbenchServiceProvider::class,
        ];
    }

    /**
     * Start Workbench with a supplied configuration before providers register.
     */
    public function startWorkbenchWithSuppliedConfiguration(Application $app): void
    {
        Workbench::start($app, $this->suppliedConfiguration = new Config(['workbench' => ['auth' => true]]));
    }

    #[Test]
    public function itProvidesTheWorkbenchConfigurationByDefault(): void
    {
        $this->assertSame(Workbench::configuration(), $this->app->make(ConfigContract::class));
    }

    #[Test]
    #[ResolvesHypervel('startWorkbenchWithSuppliedConfiguration')]
    public function itKeepsAnExplicitlySuppliedWorkbenchConfiguration(): void
    {
        $this->assertSame($this->suppliedConfiguration, $this->app->make(ConfigContract::class));
        $this->assertTrue(workbench()['auth']);
    }

    #[Test]
    public function itRegistersTheExpectedConsoleCommands(): void
    {
        /** @var array<string, object> $commands */
        $commands = $this->app->make(ConsoleKernel::class)->all();

        $this->assertArrayHasKey('package:test', $commands);
        $this->assertInstanceOf(TestCommand::class, $commands['package:test']);
        $this->assertArrayHasKey('package:create-sqlite-db', $commands);
        $this->assertInstanceOf(CreateSqliteDbCommand::class, $commands['package:create-sqlite-db']);
        $this->assertArrayHasKey('package:drop-sqlite-db', $commands);
        $this->assertInstanceOf(DropSqliteDbCommand::class, $commands['package:drop-sqlite-db']);
        $this->assertArrayHasKey('package:install', $commands);
        $this->assertInstanceOf(InstallCommand::class, $commands['package:install']);
        $this->assertArrayHasKey('package:purge-skeleton', $commands);
        $this->assertInstanceOf(PurgeSkeletonCommand::class, $commands['package:purge-skeleton']);
        $this->assertArrayHasKey('serve', $commands);
        $this->assertSame(ServeCommand::class, $commands['serve']::class);
        $this->assertArrayHasKey('package:sync-skeleton', $commands);
        $this->assertInstanceOf(SyncSkeletonCommand::class, $commands['package:sync-skeleton']);
        $this->assertArrayHasKey('vendor:publish', $commands);
        $this->assertSame(VendorPublishCommand::class, $commands['vendor:publish']::class);
    }
}
