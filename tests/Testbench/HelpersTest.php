<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Composer\InstalledVersions;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Exceptions\ApplicationNotAvailableException;
use Hypervel\Testbench\TestCase;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Runner\Version;
use Symfony\Component\Process\Process;

use function Hypervel\Support\php_binary;
use function Hypervel\Testbench\hypervel_or_fail;
use function Hypervel\Testbench\hypervel_version_compare;
use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\package_version_compare;
use function Hypervel\Testbench\phpunit_version_compare;

class HelpersTest extends TestCase
{
    #[Test]
    public function itCanCompareHypervelVersion(): void
    {
        $hypervel = str_contains(Application::VERSION, '.') && substr_count(Application::VERSION, '.') === 1
            ? Application::VERSION . '.0'
            : Application::VERSION;

        $this->assertSame(0, hypervel_version_compare($hypervel));
        $this->assertTrue(hypervel_version_compare($hypervel, '=='));
    }

    #[Test]
    public function itCanComparePhpunitVersion(): void
    {
        $version = Version::id();

        $phpunit = match (true) {
            str_starts_with($version, '13.0-') => '13.0.0',
            default => $version,
        };

        $this->assertSame(0, phpunit_version_compare($phpunit));
        $this->assertTrue(phpunit_version_compare($phpunit, '=='));
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

        $this->assertTrue(package_version_compare('psr/http-message-implementation', '1.0', '>='));
    }

    #[Test]
    public function itThrowsExceptionWhenPackageIsNotInstalled(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Package "hypervel/is-not-installed" is not installed');

        package_version_compare('hypervel/is-not-installed', '1.0.0', '=');
    }

    #[Test]
    public function itCanThrowApplicationNotAvailableExceptionWhenAppIsNotHypervel(): void
    {
        $this->expectException(ApplicationNotAvailableException::class);
        $this->expectExceptionMessage(sprintf('Application is not available to run [%s]', __METHOD__));

        hypervel_or_fail(null);
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
