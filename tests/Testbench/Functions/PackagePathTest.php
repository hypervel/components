<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Functions;

use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\testbench_path;

/**
 * @internal
 * @coversNothing
 */
class PackagePathTest extends TestCase
{
    #[Test]
    public function itCanUsePackagePath()
    {
        $this->assertSame(realpath(dirname(__DIR__, 3)), package_path());
        $this->assertSame(implode('', [realpath(dirname(__DIR__, 3)), DIRECTORY_SEPARATOR]), package_path(DIRECTORY_SEPARATOR));
    }

    #[Test]
    public function itCanResolvePackagePathWithoutTestbenchWorkingPath(): void
    {
        $process = new Process(
            command: [
                php_binary(),
                '-r',
                sprintf(
                    'require %s; echo Hypervel\Testbench\package_path();',
                    var_export(package_path('vendor', 'autoload.php'), true)
                ),
            ],
            cwd: package_path('tests', 'Testbench', 'Functions'),
            env: ['TESTBENCH_WORKING_PATH' => false],
        );

        $process->mustRun();

        $this->assertSame(package_path(), $process->getOutput());
    }

    #[Test]
    public function itCanUseTestbenchPath()
    {
        $this->assertSame(realpath(package_path('src/testbench')), testbench_path());
        $this->assertSame(
            realpath(package_path('src/testbench/workbench')),
            testbench_path('workbench')
        );
    }

    #[Test]
    #[DataProvider('pathDataProvider')]
    public function itCanResolveCorrectPackagePath(string $path)
    {
        $this->assertSame(
            realpath(join_paths(__DIR__, 'PackagePathTest.php')),
            package_path(join_paths('./tests', 'Testbench', 'Functions', 'PackagePathTest.php'))
        );

        $this->assertSame(
            realpath(join_paths(__DIR__, 'PackagePathTest.php')),
            package_path(join_paths('tests', 'Testbench', 'Functions', 'PackagePathTest.php'))
        );

        $this->assertSame(
            realpath(join_paths(__DIR__, 'PackagePathTest.php')),
            package_path(DIRECTORY_SEPARATOR . join_paths('tests', 'Testbench', 'Functions', 'PackagePathTest.php'))
        );

        $this->assertSame(
            realpath(join_paths(__DIR__, 'PackagePathTest.php')),
            package_path(join_paths('tests', 'Testbench', 'Functions', 'PackagePathTest.php'))
        );
    }

    public static function pathDataProvider()
    {
        yield [package_path('tests' . DIRECTORY_SEPARATOR . 'Testbench' . DIRECTORY_SEPARATOR . 'Functions' . DIRECTORY_SEPARATOR . 'PackagePathTest.php')];
        yield [package_path('./tests' . DIRECTORY_SEPARATOR . 'Testbench' . DIRECTORY_SEPARATOR . 'Functions' . DIRECTORY_SEPARATOR . 'PackagePathTest.php')];
        yield [package_path(DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Testbench' . DIRECTORY_SEPARATOR . 'Functions' . DIRECTORY_SEPARATOR . 'PackagePathTest.php')];

        yield [package_path('tests', 'Testbench', 'Functions', 'PackagePathTest.php')];
        yield [package_path(['tests', 'Testbench', 'Functions', 'PackagePathTest.php'])];
        yield [package_path('./tests', 'Testbench', 'Functions', 'PackagePathTest.php')];
        yield [package_path(['./tests', 'Testbench', 'Functions', 'PackagePathTest.php'])];
    }
}
