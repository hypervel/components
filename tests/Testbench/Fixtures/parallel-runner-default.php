<?php

declare(strict_types=1);

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Testbench\Features\ParallelRunner;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testing\ParallelTesting;

$rootPath = dirname(__DIR__, 3);

require $rootPath . '/vendor/autoload.php';

$firstApplication = null;
$secondApplication = null;

try {
    $runner = (new ReflectionClass(ParallelRunner::class))->newInstanceWithoutConstructor();
    $createApplication = new ReflectionMethod(ParallelRunner::class, 'createApplication');

    /** @var ApplicationContract $firstApplication */
    $firstApplication = $createApplication->invoke($runner);
    $runtimePath = $firstApplication->basePath();
    $environmentExamplePath = $runtimePath . DIRECTORY_SEPARATOR . '.env.example';
    $environmentExample = (string) file_get_contents($environmentExamplePath);
    $modifiedEnvironmentExample = $environmentExample . PHP_EOL . 'PARALLEL_RUNNER_RUNTIME_SENTINEL=1';

    file_put_contents($environmentExamplePath, $modifiedEnvironmentExample);

    $firstApplication->terminate();
    $firstApplication->flush();
    $firstApplication = null;

    /** @var ApplicationContract $secondApplication */
    $secondApplication = $createApplication->invoke($runner);
    $parallelTesting = $secondApplication->make(ParallelTesting::class);
    $parallelTestingReflection = new ReflectionClass($parallelTesting);

    echo json_encode([
        'environment' => Env::get('HYPERVEL_TEST_PARALLEL_RUNNER_ENV'),
        'provider' => $secondApplication->bound('parallel-runner.configured-provider'),
        'excluded_provider' => $secondApplication->bound('parallel-runner.excluded-provider'),
        'bootstrapper' => $secondApplication->make('config')->boolean('parallel-runner.configured-bootstrapper'),
        'setup_callbacks' => count(
            $parallelTestingReflection->getProperty('setUpProcessCallbacks')->getValue($parallelTesting),
        ),
        'teardown_callbacks' => count(
            $parallelTestingReflection->getProperty('tearDownProcessCallbacks')->getValue($parallelTesting),
        ),
        'runtime_reused' => $runtimePath === $secondApplication->basePath(),
        'runtime_preserved' => $modifiedEnvironmentExample === file_get_contents($environmentExamplePath),
    ], JSON_THROW_ON_ERROR);
} finally {
    $firstApplication?->terminate();
    $firstApplication?->flush();
    $secondApplication?->terminate();
    $secondApplication?->flush();
}
