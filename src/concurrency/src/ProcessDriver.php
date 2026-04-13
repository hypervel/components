<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Closure;
use Exception;
use Hypervel\Console\Application;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Process\Pool;
use Hypervel\Support\Arr;
use Hypervel\Support\Defer\DeferredCallback;
use Laravel\SerializableClosure\SerializableClosure;

use function Hypervel\Support\defer;

class ProcessDriver implements Driver
{
    /**
     * Create a new process based concurrency driver.
     */
    public function __construct(
        protected ProcessFactory $processFactory
    ) {
    }

    /**
     * Run the given tasks concurrently and return an array containing the results.
     */
    public function run(Closure|array $tasks): array
    {
        $command = Application::formatCommandString('invoke-serialized-closure');

        $results = $this->processFactory->pool(function (Pool $pool) use ($tasks, $command) {
            foreach (Arr::wrap($tasks) as $key => $task) {
                $pool->as((string) $key)->path(base_path())->env([
                    'HYPERVEL_INVOKABLE_CLOSURE' => base64_encode(
                        serialize(new SerializableClosure($task))
                    ),
                ])->command($command);
            }
        })->start()->wait();

        return $results->collect()->mapWithKeys(function ($result, $key) {
            if ($result->failed()) {
                throw new Exception('Concurrent process failed with exit code [' . $result->exitCode() . ']. Message: ' . $result->errorOutput());
            }

            $result = json_decode($result->output(), true);

            if (! $result['successful']) {
                throw new $result['exception'](
                    ...(! empty(array_filter($result['parameters']))
                        ? $result['parameters']
                        : [$result['message']])
                );
            }

            return [$key => unserialize($result['result'])];
        })->all();
    }

    /**
     * Start the given tasks in the background after the current task has finished.
     */
    public function defer(Closure|array $tasks): DeferredCallback
    {
        $command = Application::formatCommandString('invoke-serialized-closure');

        return defer(function () use ($tasks, $command) {
            foreach (Arr::wrap($tasks) as $task) {
                $this->processFactory->path(base_path())->env([
                    'HYPERVEL_INVOKABLE_CLOSURE' => base64_encode(
                        serialize(new SerializableClosure($task))
                    ),
                ])->run($command . ' 2>&1 &');
            }
        });
    }
}
