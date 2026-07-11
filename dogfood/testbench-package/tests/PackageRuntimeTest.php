<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\TestbenchPackage\Tests;

use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\remote;

class PackageRuntimeTest extends TestCase
{
    use WithWorkbench;

    #[Test]
    public function itDiscoversTheRootPackageProvider(): void
    {
        $this->assertTrue($this->app->make('config')->boolean('dogfood.package_provider_loaded'));
    }

    #[Test]
    public function itLoadsWorkbenchProviderAndConfig(): void
    {
        $config = $this->app->make('config');

        $this->assertTrue($config->boolean('dogfood.workbench_provider_loaded'));
        $this->assertTrue($config->boolean('dogfood.workbench_config_loaded'));
    }

    #[Test]
    public function itRunsRemoteCommandsInsideThePackageRuntime(): void
    {
        $process = remote('dogfood:probe --no-ansi')->mustRun();

        $this->assertStringContainsString('package-provider', $process->getOutput());
        $this->assertStringContainsString('workbench-provider', $process->getOutput());
    }

    #[Test]
    public function itRunsInsideParallelPackageMode(): void
    {
        $this->assertNotSame('', (string) ($_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? ''));
        $this->assertSame('(true)', (string) ($_SERVER['TESTBENCH_PACKAGE_TESTER'] ?? $_ENV['TESTBENCH_PACKAGE_TESTER'] ?? ''));
    }
}
