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
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Throwable;

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
    public function run(Closure|array $tasks, CarbonInterval|int|null $timeout = null): array
    {
        $command = Application::formatCommandString('invoke-serialized-closure');

        $results = $this->processFactory->pool(function (Pool $pool) use ($tasks, $command, $timeout) {
            foreach (Arr::wrap($tasks) as $key => $task) {
                $process = $pool->as((string) $key)->path(base_path())->env([
                    'HYPERVEL_INVOKABLE_CLOSURE' => base64_encode(
                        serialize(new SerializableClosure($task))
                    ),
                ])->command($command);

                if ($timeout !== null) {
                    $process->timeout($timeout);
                }
            }
        })->start()->wait();

        return $results->collect()->mapWithKeys(function ($result, $key) {
            if ($result->failed()) {
                throw new Exception('Concurrent process failed with exit code [' . $result->exitCode() . ']. Message: ' . $result->errorOutput());
            }

            $output = $result->output();

            if (($position = strpos($output, "\x1f\x8b")) !== false) {
                $output = substr($output, 0, $position);
            }

            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload)
                || ! array_key_exists('successful', $payload)
                || ! is_bool($payload['successful'])) {
                throw new RuntimeException('Invalid concurrent process response envelope.');
            }

            /** @var array{
             *     successful: bool,
             *     result?: string,
             *     exception?: class-string<Throwable>,
             *     message?: string,
             *     parameters?: array<string, mixed>
             * } $payload
             */
            if ($payload['successful'] === false) {
                if ((array_key_exists('exception', $payload) && ! is_string($payload['exception']))
                    || (array_key_exists('message', $payload) && ! is_string($payload['message']))
                    || (array_key_exists('parameters', $payload) && ! is_array($payload['parameters']))) {
                    throw new RuntimeException('Invalid concurrent process response envelope.');
                }

                $exceptionClass = $payload['exception'] ?? RuntimeException::class;
                $message = $payload['message'] ?? 'Serialized closure execution failed.';
                $parameters = $payload['parameters'] ?? ['message' => $message];

                try {
                    $exception = new $exceptionClass(...$parameters);
                } catch (Throwable $constructionException) {
                    throw new RuntimeException($message, previous: $constructionException);
                }

                if (! $exception instanceof Throwable) {
                    throw new RuntimeException($message);
                }

                throw $exception;
            }

            $encodedResult = $payload['result'] ?? null;
            $serializedResult = is_string($encodedResult)
                ? base64_decode($encodedResult, true)
                : false;

            if ($serializedResult === false) {
                throw new RuntimeException('Unable to decode the concurrent process result.');
            }

            // Malformed payloads warn and return false, which is also a valid serialized result.
            $unserializedResult = @unserialize($serializedResult);

            if ($unserializedResult === false && $serializedResult !== serialize(false)) {
                throw new RuntimeException('Unable to decode the concurrent process result.');
            }

            return [$key => $unserializedResult];
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
