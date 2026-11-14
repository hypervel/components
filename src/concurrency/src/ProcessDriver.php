<?php

declare(strict_types=1);

namespace Hypervel\Concurrency;

use Carbon\CarbonInterval;
use Closure;
use Exception;
use Hypervel\Console\Application;
use Hypervel\Contracts\Concurrency\Driver;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Process\Pool;
use Hypervel\Support\Arr;
use Hypervel\Support\Defer\DeferredCallback;
use Hypervel\Support\Facades\Context;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use SensitiveParameter;
use Throwable;

use function Hypervel\Support\defer;

/**
 * @phpstan-import-type ResultEnvelope from SerializedClosureResult
 */
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
     *
     * @throws Throwable
     */
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        $command = Application::formatCommandString('invoke-serialized-closure');

        $envelopeKeys = [];

        $results = $this->processFactory->pool(function (Pool $pool) use ($tasks, $command, $timeout, &$envelopeKeys) {
            foreach (Arr::wrap($tasks) as $key => $task) {
                $envelopeKeys[$key] = bin2hex(random_bytes(32));

                $process = $pool->as((string) $key)->path(base_path())->env([
                    SerializedClosureResult::KEY_ENVIRONMENT_VARIABLE => $envelopeKeys[$key],
                    '__HYPERVEL_CONTEXT' => base64_encode(serialize(Context::dehydrate())),
                    'HYPERVEL_INVOKABLE_CLOSURE' => base64_encode(
                        serialize(new SerializableClosure($task))
                    ),
                ])->command($command);

                if ($timeout !== null) {
                    $process->timeout($timeout);
                }
            }
        })->start()->wait();

        return $results->collect()->mapWithKeys(function ($result, $key) use ($envelopeKeys) {
            if ($result->failed()) {
                throw new Exception('Concurrent process failed with exit code [' . $result->exitCode() . ']. Message: ' . $result->errorOutput());
            }

            return [$key => SerializedClosureResult::decode($this->parseEnvelope($result->output(), $envelopeKeys[$key]))];
        })->all();
    }

    /**
     * Extract the signed result envelope from the given process output.
     *
     * @return ResultEnvelope
     *
     * @throws RuntimeException
     */
    protected function parseEnvelope(string $output, #[SensitiveParameter] string $key): array
    {
        return SerializedClosureResult::parseEnvelope($output, $key);
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
                    '__HYPERVEL_CONTEXT' => base64_encode(serialize(Context::dehydrate())),
                    'HYPERVEL_INVOKABLE_CLOSURE' => base64_encode(
                        serialize(new SerializableClosure($task))
                    ),
                ])->run($command . ' 2>&1 &');
            }
        });
    }
}
