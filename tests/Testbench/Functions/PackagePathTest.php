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
use function Hypervel\Testbench\testbench_relative_path;

class PackagePathTest extends TestCase
{
    #[Test]
    public function itCanUsePackagePath(): void
    {
        $this->assertSame(realpath(dirname(__DIR__, 3)), package_path());
        $this->assertSame(implode('', [realpath(dirname(__DIR__, 3)), DIRECTORY_SEPARATOR]), package_path(DIRECTORY_SEPARATOR));
    }

    #[Test]
    #[DataProvider('workingPathValues')]
    public function itCanResolvePackagePathFromEnvironment(string|false $workingPath, string $expectedPath): void
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
            env: ['TESTBENCH_WORKING_PATH' => $workingPath],
        );

        $process->mustRun();

        $this->assertSame($expectedPath, $process->getOutput());
    }

    public static function workingPathValues(): iterable
    {
        yield 'absent' => [false, package_path()];
        yield 'decoded false' => ['false', package_path()];
        yield 'empty' => ['', package_path()];
        yield 'valid directory' => [package_path('tests'), package_path('tests')];
    }

    #[Test]
    public function itKeepsExternalTestbenchPathsAbsolute(): void
    {
        $process = new Process(
            command: [
                php_binary(),
                '-r',
                sprintf(
                    'require %s; echo Hypervel\Testbench\testbench_relative_path("workbench");',
                    var_export(package_path('vendor', 'autoload.php'), true)
                ),
            ],
            cwd: package_path(),
            env: ['TESTBENCH_WORKING_PATH' => package_path('tests')],
        );

        $process->mustRun();

        $this->assertSame(testbench_path('workbench'), $process->getOutput());
    }

    #[Test]
    public function itCanUseTestbenchPath(): void
    {
        $this->assertSame(realpath(package_path('src/testbench')), testbench_path());
        $this->assertSame(
            realpath(package_path('src/testbench/workbench')),
            testbench_path('workbench')
        );
    }

    #[Test]
    #[DataProvider('pathDataProvider')]
    public function itCanResolveCorrectPackagePath(string $path): void
    {
        $this->assertSame(
            realpath(join_paths(__DIR__, 'PackagePathTest.php')),
            $path
        );
    }

    public static function pathDataProvider(): iterable
    {
        yield [package_path('tests' . DIRECTORY_SEPARATOR . 'Testbench' . DIRECTORY_SEPARATOR . 'Functions' . DIRECTORY_SEPARATOR . 'PackagePathTest.php')];
        yield [package_path('./tests' . DIRECTORY_SEPARATOR . 'Testbench' . DIRECTORY_SEPARATOR . 'Functions' . DIRECTORY_SEPARATOR . 'PackagePathTest.php')];
        yield [package_path(DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Testbench' . DIRECTORY_SEPARATOR . 'Functions' . DIRECTORY_SEPARATOR . 'PackagePathTest.php')];

        yield [package_path('tests', 'Testbench', 'Functions', 'PackagePathTest.php')];
        yield [package_path(['tests', 'Testbench', 'Functions', 'PackagePathTest.php'])];
        yield [package_path('./tests', 'Testbench', 'Functions', 'PackagePathTest.php')];
        yield [package_path(['./tests', 'Testbench', 'Functions', 'PackagePathTest.php'])];
    }

    #[Test]
    public function itCanResolveRelativeTestbenchPaths(): void
    {
        $expected = realpath(package_path('src/testbench/workbench/resources/views'));

        $this->assertSame(
            $expected,
            testbench_path('./workbench', 'resources', 'views')
        );
        $this->assertSame(
            $expected,
            testbench_path(['./workbench', 'resources', 'views'])
        );

        $this->assertSame(
            'src/testbench/workbench/resources/views',
            testbench_relative_path('./workbench', 'resources', 'views')
        );
        $this->assertSame(
            'src/testbench/workbench/resources/views',
            testbench_relative_path(['./workbench', 'resources', 'views'])
        );
    }
}
