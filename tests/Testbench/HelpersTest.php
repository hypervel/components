<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Composer\InstalledVersions;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Exceptions\ApplicationNotAvailableException;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Runner\Version;
use ReflectionProperty;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\hypervel_or_fail;
use function Hypervel\Testbench\hypervel_version_compare;
use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\package_version_compare;
use function Hypervel\Testbench\php_version_compare;
use function Hypervel\Testbench\phpunit_version_compare;
use function Hypervel\Testbench\uses_default_skeleton;

class HelpersTest extends TestCase
{
    #[Test]
    public function itCanCompareHypervelVersion(): void
    {
        $hypervelVersion = Application::VERSION;

        $this->assertSame(0, hypervel_version_compare($hypervelVersion));
        $this->assertTrue(hypervel_version_compare($hypervelVersion, '=='));
    }

    #[Test]
    public function itCanComparePhpVersion(): void
    {
        $phpVersion = PHP_VERSION_ID === 80600 ? '8.6.0' : PHP_VERSION;

        $this->assertSame(0, php_version_compare($phpVersion));
        $this->assertTrue(php_version_compare($phpVersion, '=='));
    }

    #[Test]
    public function itCanComparePhpunitVersion(): void
    {
        $phpunitVersion = explode('-', Version::id(), 2)[0];

        $this->assertSame(0, phpunit_version_compare($phpunitVersion));
        $this->assertTrue(phpunit_version_compare($phpunitVersion, '=='));
    }

    #[Test]
    #[DataProvider('phpunitDevelopmentVersions')]
    public function itComparesPhpunitDevelopmentVersionsAsTheirRelease(string $phpunitVersion): void
    {
        $pharVersion = new ReflectionProperty(Version::class, 'pharVersion');
        $originalPharVersion = $pharVersion->getValue();
        $pharVersion->setValue(null, $phpunitVersion);

        try {
            $this->assertSame(0, phpunit_version_compare('13.3.0'));
            $this->assertTrue(phpunit_version_compare('13.4.0', '<'));
        } finally {
            $pharVersion->setValue(null, $originalPharVersion);
        }
    }

    /**
     * Get PHPUnit development version identifiers.
     *
     * @return iterable<string, array{string}>
     */
    public static function phpunitDevelopmentVersions(): iterable
    {
        yield 'without git' => ['13.3-dev'];
        yield 'with git' => ['13.3-gabc1234'];
    }

    #[Test]
    public function itCanEvaluatePackageVersion(): void
    {
        $version = InstalledVersions::getPrettyVersion('phpunit/phpunit');

        $this->assertSame(0, package_version_compare('phpunit/phpunit', $version));
        $this->assertTrue(package_version_compare('phpunit/phpunit', $version, '='));
        $this->assertTrue(package_version_compare('phpunit/phpunit', $version, '<='));
        $this->assertTrue(package_version_compare('phpunit/phpunit', $version, '>='));

        $this->assertFalse(package_version_compare('phpunit/phpunit', $version, '<'));
        $this->assertFalse(package_version_compare('phpunit/phpunit', $version, '>'));
    }

    #[Test]
    public function itCanEvaluateProvidedPackageVersion(): void
    {
        $version = InstalledVersions::getVersionRanges('hypervel/support');

        $this->assertTrue(package_version_compare('hypervel/support', $version));
        $this->assertTrue(package_version_compare('hypervel/support', $version, '='));
        $this->assertTrue(package_version_compare('hypervel/support', $version, '<='));
        $this->assertTrue(package_version_compare('hypervel/support', $version, '>='));

        $this->assertFalse(package_version_compare('hypervel/support', $version, '<'));
        $this->assertFalse(package_version_compare('hypervel/support', $version, '>'));

        $this->assertTrue(package_version_compare('hypervel/support', $version, 'eq'));
        $this->assertTrue(package_version_compare('hypervel/support', $version, 'le'));
        $this->assertTrue(package_version_compare('hypervel/support', $version, 'ge'));

        $this->assertFalse(package_version_compare('hypervel/support', $version, 'lt'));
        $this->assertFalse(package_version_compare('hypervel/support', $version, 'gt'));
        $this->assertFalse(package_version_compare('hypervel/support', $version, 'ne'));

        $this->assertTrue(package_version_compare('psr/http-message-implementation', '1.0', '>='));
        $this->assertTrue(package_version_compare('psr/http-message-implementation', '1.0', 'ge'));
    }

    #[Test]
    public function itThrowsExceptionWhenPackageIsNotInstalled(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessageIs('Package "hypervel/is-not-installed" is not installed');

        package_version_compare('hypervel/is-not-installed', '1.0.0', '=');
    }

    #[Test]
    public function itCanThrowApplicationNotAvailableExceptionWhenAppIsNotHypervel(): void
    {
        $this->expectException(ApplicationNotAvailableException::class);
        $this->expectExceptionMessageIs(sprintf('Application is not available to run [%s]', __METHOD__));

        hypervel_or_fail(null);
    }

    #[Test]
    public function itDetectsTheDefaultSkeletonFromTheApplicationBasePath(): void
    {
        $filesystem = new Filesystem;
        $defaultBasePath = $this->app->basePath();
        $customBasePath = ParallelTesting::tempDir('HelpersTest');
        $filesystem->deleteDirectory($customBasePath);
        $filesystem->makeDirectory(join_paths($customBasePath, 'bootstrap'), 0700, recursive: true);

        try {
            $this->assertTrue(uses_default_skeleton());
            $this->assertFalse(uses_default_skeleton($customBasePath));

            $this->app->setBasePath($customBasePath);

            $this->assertFalse(uses_default_skeleton());
            $this->assertTrue(uses_default_skeleton($defaultBasePath));
        } finally {
            $this->app->setBasePath($defaultBasePath);
            $filesystem->deleteDirectory($customBasePath);
        }
    }

    #[Test]
    #[DataProvider('terminationStatuses')]
    public function itCanTerminateWithStatus(string|int $status, int $exitCode, string $output): void
    {
        $process = new Process([
            php_binary(),
            '-r',
            sprintf(
                'require %s; Hypervel\Testbench\terminate(null, %s);',
                var_export(package_path('vendor', 'autoload.php'), true),
                var_export($status, true),
            ),
        ]);

        $process->run();

        $this->assertSame($exitCode, $process->getExitCode());
        $this->assertSame($output, $process->getOutput());
    }

    /**
     * Get termination statuses with their expected exit codes and output.
     *
     * @return iterable<string, array{int|string, int, string}>
     */
    public static function terminationStatuses(): iterable
    {
        yield 'integer' => [3, 3, ''];
        yield 'string' => ['Stopped', 0, 'Stopped'];
    }
}
