<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Hyperf\Contract\ApplicationInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Tests\Horizon\IntegrationTestCase;
use Workbench\App\Exceptions\ExceptionHandler;

use function Hypervel\Coroutine\run;

Bootstrapper::bootstrap();

$app = new Application();
$app->bind(KernelContract::class, ConsoleKernel::class);
$app->bind(ExceptionHandlerContract::class, ExceptionHandler::class);

ApplicationContext::setContainer($app);
$app->get(ApplicationInterface::class);

$config = $app->get(ConfigInterface::class);
$config->set('horizon.prefix', IntegrationTestCase::HORIZON_PREFIX);
$config->set('queue', [
    'default' => 'redis',
    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ],
    ],
]);

$app->register(HorizonServiceProvider::class);

/** @var Worker $worker */
$worker = $app->get(Worker::class);

// Pause the worker if needed...
if (in_array('--paused', $_SERVER['argv'])) {
    $worker->paused = true;
}

// Start the daemon loop.
run(function () use ($worker) {
    $worker->daemon(
        'redis',
        'default',
        new WorkerOptions()
    );

    CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
});
