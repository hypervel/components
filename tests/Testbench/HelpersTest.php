<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Foundation\Application;
use Hypervel\Testbench\Exceptions\ApplicationNotAvailableException;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Runner\Version;

use function Hypervel\Testbench\hypervel_or_fail;
use function Hypervel\Testbench\hypervel_version_compare;
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
    public function itCanThrowApplicationNotAvailableExceptionWhenAppIsNotHypervel(): void
    {
        $this->expectException(ApplicationNotAvailableException::class);
        $this->expectExceptionMessage(sprintf('Application is not available to run [%s]', __METHOD__));

        hypervel_or_fail(null);
    }
}
