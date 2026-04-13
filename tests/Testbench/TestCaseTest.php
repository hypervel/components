<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Contracts\TestCase as TestCaseContract;
use Hypervel\Testbench\Foundation\Application as Testbench;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\PHPUnit\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\container;

/**
 * @internal
 * @coversNothing
 */
class TestCaseTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        Testbench::flushState($this);

        parent::tearDown();
    }

    #[Test]
    public function itCanCreateTheTestcase()
    {
        // Use a real dummy test method on the anonymous class so PHPUnit's
        // metadata parser can resolve the stored method name while
        // createApplication() loads environment variables.
        $testbench = new /**
         * @coversNothing
         */
        class('testDummy') extends \Hypervel\Testbench\TestCase {
            public function testDummy()
            {
            }
        };

        $app = $testbench->createApplication();

        $this->assertInstanceOf(Application::class, $app);
        $this->assertEquals('UTC', date_default_timezone_get());
        $this->assertEquals('testing', $app['env']);
        $this->assertSame('testing', $app->environment());
        $this->assertTrue($app->runningUnitTests());
        $this->assertInstanceOf(ConfigRepository::class, $app['config']);

        $this->assertInstanceOf(TestCaseContract::class, $testbench);
        $this->assertTrue($testbench->isRunningTestCase());
        $this->assertFalse($testbench->isRunningTestCaseUsingPest());

        $app->terminate();
    }

    #[Test]
    public function itCanCreateAContainer()
    {
        $container = container();

        $app = $container->createApplication();

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';

        $this->assertInstanceOf(Application::class, $app);
        $this->assertEquals('UTC', date_default_timezone_get());
        $this->assertEquals($environment, $app['env']);
        $this->assertSame($environment, $app->environment());
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $app->runningUnitTests());
        $this->assertInstanceOf(ConfigRepository::class, $app['config']);

        $this->assertFalse($container->isRunningTestCase());
        $this->assertFalse($container->isRunningTestCaseUsingPest());

        $app->terminate();
    }
}
