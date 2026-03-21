<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Composer\InstalledVersions;
use Hypervel\Contracts\Console\Kernel as ConsoleKernel;
use Hypervel\Testbench\Foundation\Console\CreateSqliteDbCommand;
use Hypervel\Testbench\Foundation\Console\DropSqliteDbCommand;
use Hypervel\Testbench\Foundation\Console\PurgeSkeletonCommand;
use Hypervel\Testbench\Foundation\Console\ServeCommand;
use Hypervel\Testbench\Foundation\Console\SyncSkeletonCommand;
use Hypervel\Testbench\Foundation\Console\TestCommand;
use Hypervel\Testbench\Foundation\Console\TestFallbackCommand;
use Hypervel\Testbench\Foundation\Console\VendorPublishCommand;
use Hypervel\Testbench\TestbenchServiceProvider;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class TestbenchServiceProviderTest extends TestCase
{
    /**
     * Get package providers.
     *
     * @param \Hypervel\Contracts\Foundation\Application $app
     * @return array<int, class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            TestbenchServiceProvider::class,
        ];
    }

    #[Test]
    public function itRegistersTheExpectedConsoleCommands(): void
    {
        /** @var array<string, object> $commands */
        $commands = $this->app->make(ConsoleKernel::class)->all();

        $this->assertArrayHasKey('package:test', $commands);
        $this->assertInstanceOf($this->expectedPackageTestCommand(), $commands['package:test']);
        $this->assertArrayHasKey('package:create-sqlite-db', $commands);
        $this->assertInstanceOf(CreateSqliteDbCommand::class, $commands['package:create-sqlite-db']);
        $this->assertArrayHasKey('package:drop-sqlite-db', $commands);
        $this->assertInstanceOf(DropSqliteDbCommand::class, $commands['package:drop-sqlite-db']);
        $this->assertArrayHasKey('package:purge-skeleton', $commands);
        $this->assertInstanceOf(PurgeSkeletonCommand::class, $commands['package:purge-skeleton']);
        $this->assertArrayHasKey('serve', $commands);
        $this->assertSame(ServeCommand::class, $commands['serve']::class);
        $this->assertArrayHasKey('package:sync-skeleton', $commands);
        $this->assertInstanceOf(SyncSkeletonCommand::class, $commands['package:sync-skeleton']);
        $this->assertArrayHasKey('vendor:publish', $commands);
        $this->assertSame(VendorPublishCommand::class, $commands['vendor:publish']::class);
    }

    /**
     * Resolve the expected package:test command class.
     *
     * @return class-string<TestCommand|TestFallbackCommand>
     */
    protected function expectedPackageTestCommand(): string
    {
        return InstalledVersions::isInstalled('nunomaduro/collision')
            ? TestCommand::class
            : TestFallbackCommand::class;
    }
}
