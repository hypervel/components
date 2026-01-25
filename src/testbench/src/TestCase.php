<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hyperf\Context\ApplicationContext;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Application;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Foundation\Testing\TestCase as BaseTestCase;
use Hypervel\Queue\Queue;
use Swoole\Timer;
use Workbench\App\Exceptions\ExceptionHandler;

/**
 * @internal
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
    protected static $hasBootstrappedTestbench = false;

    protected function setUp(): void
    {
        if (! static::$hasBootstrappedTestbench) {
            Bootstrapper::bootstrap();
            static::$hasBootstrappedTestbench = true;
        }

        $this->afterApplicationCreated(function () {
            Timer::clearAll();
            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
        });

        parent::setUp();
    }

    protected function createApplication(): ApplicationContract
    {
        $app = new Application();
        $app->bind(KernelContract::class, ConsoleKernel::class);
        $app->bind(ExceptionHandlerContract::class, ExceptionHandler::class);

        ApplicationContext::setContainer($app);

        return $app;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Queue::createPayloadUsing(null);
    }
}
