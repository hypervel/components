<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Foundation\Application;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\PHPUnit\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Testbench\default_skeleton_path;

class ApplicationTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        TestbenchApplication::flushState($this);

        parent::tearDown();
    }

    #[Test]
    public function itCanCreateAnApplication()
    {
        $testbench = new TestbenchApplication((string) default_skeleton_path());
        $app = $testbench->createApplication();

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('App\\', $app->getNamespace());
        $this->assertEquals($environment, $app['env']);
        $this->assertSame($app['env'], $app['config']['app.env']);
        $this->assertSame($environment, $app->environment());
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $app->runningUnitTests());
        $this->assertFalse($testbench->isRunningTestCase());
    }

    #[Test]
    public function itCanCreateAnApplicationUsingCreateHelper()
    {
        $app = TestbenchApplication::create((string) default_skeleton_path());

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('App\\', $app->getNamespace());
        $this->assertEquals($environment, $app['env']);
        $this->assertSame($app['env'], $app['config']['app.env']);
        $this->assertSame($environment, $app->environment());
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $app->runningUnitTests());
    }

    #[Test]
    public function itCanCreateAnApplicationUsingCreateFromConfigHelper()
    {
        $config = new Config([
            'hypervel' => (string) default_skeleton_path(),
        ]);

        $app = TestbenchApplication::createFromConfig($config);

        $environment = Env::has('TESTBENCH_PACKAGE_TESTER') ? 'testing' : 'workbench';

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('App\\', $app->getNamespace());
        $this->assertEquals($environment, $app['env']);
        $this->assertSame($app['env'], $app['config']['app.env']);
        $this->assertSame($environment, $app->environment());
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $app->runningUnitTests());
    }
}
