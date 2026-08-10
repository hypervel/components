<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Testbench\Foundation\Application as TestbenchApplication;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\PHPUnit\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

use function Hypervel\Testbench\default_skeleton_path;

class ApplicationTest extends TestCase
{
    #[Test]
    public function itCanCreateAnApplication(): void
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
    public function itCanCreateAnApplicationUsingCreateHelper(): void
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
    public function itCanCreateAnApplicationUsingCreateFromConfigHelper(): void
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

    #[Test]
    public function itTerminatesAndFlushesWhenTheResolvingCallbackFails(): void
    {
        $originalTimezone = date_default_timezone_get();
        $resolvingFailure = new RuntimeException('resolving failed');
        $terminationFailure = new RuntimeException('termination failed');
        $testbench = new FailingResolvingTestbenchApplication(
            (string) default_skeleton_path(),
            static function () use ($resolvingFailure): never {
                throw $resolvingFailure;
            },
        );
        $testbench->withTerminationFailure($terminationFailure);

        try {
            date_default_timezone_set('Europe/London');

            try {
                $testbench->createApplication();
                $this->fail('Expected the resolving callback to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame($resolvingFailure, $exception);
            }

            $this->assertSame('Europe/London', date_default_timezone_get());
            $this->assertSame(
                ['terminate', 'flush'],
                $testbench->createdApplication?->lifecycle,
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[Test]
    public function itRestoresTheTimezoneWhenVendorApplicationOwnershipDoesNotTransfer(): void
    {
        $originalTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/London');

            try {
                StandaloneTimezoneTestbenchApplication::createVendorSymlink(
                    (string) default_skeleton_path(),
                    (string) default_skeleton_path('missing-vendor'),
                );
                $this->fail('Expected vendor symlink creation to fail.');
            } catch (Throwable) {
                $this->addToAssertionCount(1);
            }

            $this->assertSame('Europe/London', date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }
}

class FailingResolvingTestbenchApplication extends TestbenchApplication
{
    public ?ResolvingTrackingApplication $createdApplication = null;

    protected ?Throwable $terminationFailure = null;

    public function withTerminationFailure(Throwable $terminationFailure): void
    {
        $this->terminationFailure = $terminationFailure;
    }

    #[Override]
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return 'Asia/Kuala_Lumpur';
    }

    protected function resolveApplication(): ApplicationContract
    {
        return $this->createdApplication = new ResolvingTrackingApplication(
            $this->getApplicationBasePath(),
            $this->terminationFailure,
        );
    }
}

class ResolvingTrackingApplication extends Application
{
    /** @var list<string> */
    public array $lifecycle = [];

    public function __construct(?string $basePath, protected ?Throwable $terminationFailure)
    {
        parent::__construct($basePath);
    }

    public function terminate(): void
    {
        $this->lifecycle[] = 'terminate';

        parent::terminate();

        if ($this->terminationFailure !== null) {
            throw $this->terminationFailure;
        }
    }

    public function flush(): void
    {
        $this->lifecycle[] = 'flush';

        parent::flush();
    }
}

class StandaloneTimezoneTestbenchApplication extends TestbenchApplication
{
    #[Override]
    protected function getApplicationTimezone(ApplicationContract $app): ?string
    {
        return 'Asia/Kuala_Lumpur';
    }
}
