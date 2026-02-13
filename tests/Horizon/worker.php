<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Hyperf\Contract\ApplicationInterface;
use Hypervel\Container\Container;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
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
$app->singleton(KernelContract::class, ConsoleKernel::class);
$app->singleton(ExceptionHandlerContract::class, ExceptionHandler::class);

Container::setInstance($app);
$app->make(ApplicationInterface::class);

$config = $app->make('config');
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
$worker = $app->make(Worker::class);

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
