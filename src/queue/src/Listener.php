<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class Listener
{
    /**
     * The environment the workers should run under.
     */
    protected string $environment;

    /**
     * The amount of seconds to wait before polling the queue.
     */
    protected int $sleep = 3;

    /**
     * The number of times to try a job before logging it failed.
     */
    protected int $maxTries = 0;

    /**
     * The output handler callback.
     */
    protected ?Closure $outputHandler = null;

    /**
     * Create a new queue listener.
     */
    public function __construct(
        protected string $commandPath
    ) {
    }

    /**
     * Get the PHP binary.
     */
    protected function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find(false) ?: 'php';
    }

    /**
     * Get the Artisan binary.
     */
    protected function artisanBinary(): string
    {
        return defined('ARTISAN_BINARY') ? ARTISAN_BINARY : 'artisan';
    }

    /**
     * Listen to the given queue connection.
     */
    public function listen(?string $connection, string $queue, ListenerOptions $options): void
    {
        $process = $this->makeProcess($connection, $queue, $options);

        while (true) {
            $this->runProcess($process, $options->memory);

            if ($options->rest) {
                sleep($options->rest);
            }
        }
    }

    /**
     * Create a new Symfony process for the worker.
     */
    public function makeProcess(?string $connection, string $queue, ListenerOptions $options): Process
    {
        $command = $this->createCommand(
            $connection,
            $queue,
            $options
        );

        // If the environment is set, we will append it to the command array so the
        // workers will run under the specified environment. Otherwise, they will
        // just run under the production environment which is not always right.
        if (isset($options->environment)) {
            $command = $this->addEnvironment($command, $options);
        }

        return new Process(
            $command,
            $this->commandPath,
            null,
            null,
            $options->timeout
        );
    }

    /**
     * Add the environment option to the given command.
     */
    protected function addEnvironment(array $command, ListenerOptions $options): array
    {
        return array_merge($command, ["--env={$options->environment}"]);
    }

    /**
     * Create the command with the listener options.
     */
    protected function createCommand(?string $connection, string $queue, ListenerOptions $options): array
    {
        return array_filter([
            $this->phpBinary(),
            $this->artisanBinary(),
            'queue:work',
            $connection,
            '--once',
            "--name={$options->name}",
            "--queue={$queue}",
            "--backoff={$options->backoff}",
            "--memory={$options->memory}",
            "--sleep={$options->sleep}",
            "--tries={$options->maxTries}",
        ], function ($value) {
            return ! is_null($value);
        });
    }

    /**
     * Run the given process.
     */
    public function runProcess(Process $process, int $memory): void
    {
        $process->run(function ($type, $line) {
            $this->handleWorkerOutput($type, $line);
        });

        // Once we have run the job we'll go check if the memory limit has been exceeded
        // for the script. If it has, we will kill this script so the process manager
        // will restart this with a clean slate of memory automatically on exiting.
        if ($this->memoryExceeded($memory)) {
            $this->stop();
        }
    }

    /**
     * Handle output from the worker process.
     */
    protected function handleWorkerOutput(string $type, string $line): void
    {
        if (isset($this->outputHandler)) {
            call_user_func($this->outputHandler, $type, $line);
        }
    }

    /**
     * Determine if the memory limit has been exceeded.
     */
    public function memoryExceeded(int $memoryLimit): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Stop listening and bail out of the script.
     *
     * @return never
     */
    public function stop()
    {
        exit;
    }

    /**
     * Set the output handler callback.
     */
    public function setOutputHandler(Closure $outputHandler): void
    {
        $this->outputHandler = $outputHandler;
    }
}
