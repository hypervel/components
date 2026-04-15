<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\package_path;

class CommanderPathResolutionTest extends TestCase
{
    private string $temporaryPackagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $tempDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $this->temporaryPackagePath = $tempDir . '/hypervel-testbench-paths-' . getmypid() . '-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryPackagePath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporaryPackagePath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->temporaryPackagePath);
        }

        parent::tearDown();
    }

    #[Test]
    public function vendorBinaryUsesInstalledRootPackageWhenWorkingPathIsUnset(): void
    {
        mkdir($this->temporaryPackagePath . '/vendor/bin', 0777, true);
        mkdir($this->temporaryPackagePath . '/subdirectory', 0777, true);

        file_put_contents(
            $this->temporaryPackagePath . '/vendor/autoload.php',
            str_replace(
                '__PACKAGE_ROOT__',
                var_export($this->temporaryPackagePath, true),
                <<<'PHP'
<?php

declare(strict_types=1);

namespace Composer;

final class InstalledVersions
{
    public static function getRootPackage(): array
    {
        return ['install_path' => __PACKAGE_ROOT__];
    }
}

namespace Hypervel\Testbench;

final class Bootstrapper
{
    public static function bootstrap(): void
    {
    }

    public static function getConfiguration(): ?object
    {
        return null;
    }
}

namespace Hypervel\Testbench\Foundation;

final class Config
{
}

namespace Hypervel\Testbench\Console;

final class Commander
{
    public function __construct(
        private readonly object $config,
        private readonly string $workingPath,
    ) {
    }

    public function handle(): void
    {
        fwrite(STDOUT, $this->workingPath);
    }
}
PHP
            )
        );

        file_put_contents(
            $this->temporaryPackagePath . '/vendor/bin/testbench',
            str_replace(
                '__BINARY_PATH__',
                var_export(package_path('src', 'testbench', 'bin', 'testbench'), true),
                <<<'PHP'
<?php

declare(strict_types=1);

$_composer_autoload_path = __DIR__ . '/../autoload.php';

require __BINARY_PATH__;
PHP
            )
        );

        $process = new Process(
            command: [php_binary(), $this->temporaryPackagePath . '/vendor/bin/testbench'],
            cwd: $this->temporaryPackagePath . '/subdirectory',
            env: ['TESTBENCH_WORKING_PATH' => false],
        );

        $process->mustRun();

        $this->assertSame($this->temporaryPackagePath, $process->getOutput());
    }
}
