<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\TestbenchPackage\Tests;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase;
use Hypervel\Testbench\Workbench\Workbench;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\remote;
use function Hypervel\Testbench\workbench_relative_path;

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
    public function itResolvesPathsRelativeToThePackageWorkbench(): void
    {
        $this->assertSame('workbench/config/dogfood.php', workbench_relative_path('config', 'dogfood.php'));
        $this->assertSame('workbench/config/dogfood.php', workbench_relative_path('./config', 'dogfood.php'));
        $this->assertSame('Workbench\App\\', Workbench::detectNamespace('app', true));
    }

    #[Test]
    public function itFallsBackToTheSkeletonUserModelWhenWorkbenchDoesNotProvideOne(): void
    {
        $filesystem = new Filesystem;
        $modelPath = base_path('app/Models/User.php');

        Workbench::flushCachedClassAndNamespaces();
        $this->assertNull(Workbench::applicationUserModel());

        try {
            $filesystem->put($modelPath, '<?php');
            Workbench::flushCachedClassAndNamespaces();

            $this->assertSame('App\Models\User', Workbench::applicationUserModel());
        } finally {
            $filesystem->delete($modelPath);
            Workbench::flushCachedClassAndNamespaces();
        }

        $this->assertNull(Workbench::applicationUserModel());
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
