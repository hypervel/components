<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Hypervel\Container\Container;
use Hypervel\Contracts\Console\Application as ConsoleApplicationContract;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Exceptions\Handler as ExceptionHandler;
use Hypervel\Foundation\Testing\RedisTestConfiguration;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Queue\Worker;
use Hypervel\Queue\WorkerOptions;
use Hypervel\Testbench\Bootstrapper;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

use function Hypervel\Coroutine\run;

Bootstrapper::bootstrap();

$app = new Application(BASE_PATH);
$app->singleton(KernelContract::class, ConsoleKernel::class);
$app->singleton(ExceptionHandlerContract::class, ExceptionHandler::class);

Container::setInstance($app);
$app->make(ConsoleApplicationContract::class);

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
            'migration_batch_size' => -1,
        ],
    ],
]);

$token = getenv('TEST_TOKEN');

// This subprocess must apply the topology before Horizon snapshots its Redis connection.
RedisTestConfiguration::configure($config, $token);

$app->register(HorizonServiceProvider::class);

/** @var Worker $worker */
$worker = $app->make('queue.worker');

// Pause the worker if needed...
if (in_array('--paused', $_SERVER['argv'])) {
    $worker->paused = true;
}

// Start the daemon loop.
run(function () use ($worker) {
    $worker->daemon(
        'redis',
        'default',
        new WorkerOptions
    );

    CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
});
